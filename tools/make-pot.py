#!/usr/bin/env python3
"""
ساخت فایل ترجمه‌ی `languages/wapm.pot` از سورس افزونه.

چرا اسکریپت خودمان و نه WP-CLI: می‌خواهیم CI بدون نصب ابزار اضافه (فقط python3)
بتواند POT را بازتولید و «تازه بودن» را بررسی کند. خروجی همان قالب استاندارد
gettext است که Poedit و GlotPress می‌فهمند.

استفاده:
    python3 tools/make-pot.py            # نوشتن فایل
    python3 tools/make-pot.py --check    # فقط بررسی؛ اگر فایل قدیمی باشد خطا می‌دهد

قواعد:
 - فقط فراخوانی‌های با دامنه‌ی `wapm` برداشته می‌شوند.
 - `_n`/`_nx` با دو شکل مفرد/جمع (msgid_plural) پشتیبانی می‌شوند.
 - خروجی قطعی (deterministic) است: مرتب بر اساس msgid و سپس مرجع، تا diff در
   گیت فقط تغییرهای واقعی متن باشد.
"""

from __future__ import annotations

import argparse
import os
import re
import sys

PLUGIN_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "woocommerce-advanced-price-manager")
OUT_FILE = os.path.join(PLUGIN_DIR, "languages", "wapm.pot")
DOMAIN = "wapm"

# متن رشته‌های PHP: تک‌کوتیشنی یا جفت‌کوتیشنی، با پشتیبانی از «...» چندخطی.
STR = r"""'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\""""

CALLS = [
    # (regex, has_context, has_plural)
    (re.compile(r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(?:" + STR + r")\s*,\s*'" + DOMAIN + r"'\s*\)", re.S), False, False),
    (re.compile(r"\b_x\s*\(\s*(?:" + STR + r")\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'" + DOMAIN + r"'\s*\)", re.S), True, False),
    (re.compile(r"\b_n\s*\(\s*(?:" + STR + r")\s*,\s*(?:" + STR + r")\s*,\s*[^,]+,\s*'" + DOMAIN + r"'\s*\)", re.S), False, True),
    (re.compile(r"\b_nx\s*\(\s*(?:" + STR + r")\s*,\s*(?:" + STR + r")\s*,\s*[^,]+,\s*'((?:[^'\\]|\\.)*)'\s*,\s*'" + DOMAIN + r"'\s*\)", re.S), True, True),
]


def php_unescape(value: str) -> str:
    """تبدیل escapeهای PHP به متن واقعی."""
    out = []
    i = 0
    while i < len(value):
        ch = value[i]
        if ch == "\\" and i + 1 < len(value):
            nxt = value[i + 1]
            mapping = {"'": "'", '"': '"', "\\": "\\", "n": "\n", "t": "\t", "r": "\r"}
            if nxt in mapping:
                out.append(mapping[nxt])
                i += 2
                continue
        out.append(ch)
        i += 1
    return "".join(out)


def pot_escape(value: str) -> str:
    """escape برای داخل نقل‌قول فایل PO."""
    return value.replace("\\", "\\\\").replace('"', '\\"').replace("\t", "\\t").replace("\n", "\\n")


def collect() -> dict:
    """
    @return {key: {'msgid', 'plural', 'context', 'refs': set}}
    """
    entries: dict = {}

    for root, dirs, files in os.walk(PLUGIN_DIR):
        dirs[:] = [d for d in dirs if d not in {"node_modules", "vendor", "languages", ".git"}]
        for name in sorted(files):
            if not name.endswith(".php"):
                continue

            path = os.path.join(root, name)
            rel = os.path.relpath(path, os.path.dirname(PLUGIN_DIR))
            text = open(path, encoding="utf-8").read()

            for regex, has_context, has_plural in CALLS:
                for match in regex.finditer(text):
                    groups = [g for g in match.groups() if g is not None]
                    # گروه‌ها به ترتیب: (تک‌کوتیشنی|جفت‌کوتیشنی) ... [context] [plural]
                    strings = []
                    idx = 0
                    # هر رشته دو گروه دارد: تک‌کوتیشنی و جفت‌کوتیشنی
                    while idx + 1 < len(match.groups()) or idx < len(match.groups()):
                        single = match.group(idx + 1)
                        double = match.group(idx + 2) if idx + 2 <= len(match.groups()) else None
                        if single is not None or double is not None:
                            strings.append(php_unescape(single if single is not None else double))
                            idx += 2
                        else:
                            break

                    if has_plural:
                        # n، (و در _nx، context) در گروه‌های آخر هستند.
                        tail = match.groups()[len(strings) * 2:]
                        tail = [t for t in tail if t is not None]
                        context = php_unescape(tail[0]) if has_context and tail else ""
                        plural = strings[1] if len(strings) > 1 else ""
                        singular = strings[0] if strings else ""
                    else:
                        tail = match.groups()[len(strings) * 2:]
                        tail = [t for t in tail if t is not None]
                        context = php_unescape(tail[0]) if has_context and tail else ""
                        singular = strings[0] if strings else ""
                        plural = ""

                    if not singular:
                        continue

                    key = (context, singular, plural)
                    line = text.count("\n", 0, match.start()) + 1
                    entry = entries.setdefault(key, {"refs": set()})
                    entry["refs"].add(f"{rel}:{line}")

    return entries


def build(entries: dict) -> str:
    lines = [
        "# Copyright (C) 2026 Hassan",
        "# This file is distributed under the GPL-2.0-or-later license.",
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: WooCommerce Advanced Price Manager 1.0.0\\n"',
        '"Report-Msgid-Bugs-To: https://github.com/hassann98-cmd/price-manager/issues\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        f'"Language-Team: Persian\\n"',
        '"X-Domain: wapm\\n"',
        '"Plural-Forms: nplurals=2; plural=(n > 1);\\n"',
        "",
    ]

    for (context, singular, plural), data in sorted(entries.items(), key=lambda kv: (kv[0][1], kv[0][0], kv[0][2])):
        for ref in sorted(data["refs"]):
            lines.append(f"#: {ref}")
        if context:
            lines.append(f'msgctxt "{pot_escape(context)}"')
        lines.append(f'msgid "{pot_escape(singular)}"')
        if plural:
            lines.append(f'msgid_plural "{pot_escape(plural)}"')
            lines.append('msgstr[0] ""')
            lines.append('msgstr[1] ""')
        else:
            lines.append('msgstr ""')
        lines.append("")

    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="فقط بررسی تازه‌بودن فایل")
    args = parser.parse_args()

    entries = collect()
    content = build(entries)

    os.makedirs(os.path.dirname(OUT_FILE), exist_ok=True)

    if args.check:
        existing = open(OUT_FILE, encoding="utf-8").read() if os.path.exists(OUT_FILE) else ""
        if existing != content:
            print("❌ languages/wapm.pot تازه نیست. python3 tools/make-pot.py را اجرا کنید.")
            return 1
        print(f"✅ wapm.pot تازه است ({len(entries)} رشته).")
        return 0

    open(OUT_FILE, "w", encoding="utf-8").write(content)
    print(f"✅ نوشته شد: {os.path.relpath(OUT_FILE)} — {len(entries)} رشته.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
