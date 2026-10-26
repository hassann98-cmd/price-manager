#!/usr/bin/env python3
"""
ساخت بسته‌ی نصب `price-manager.zip` از پوشه‌ی افزونه.

چرا اسکریپت: فایل zip داخل مخزن نگه داشته می‌شود (راه نصب کاربر است) و اگر
دستی ساخته شود، دیر یا زود از سورس عقب می‌ماند — نسخه‌ی قبلی همین فایل،
`class-stock.php` و `uninstall.php` را نداشت و نصب از آن به خطای مرگبار می‌رسید.

خروجی **قطعی (deterministic)** است: ترتیب فایل‌ها مرتب و زمان همه‌ی رکوردها
ثابت است، بنابراین دو بار ساخت پشت‌سرهم بایت‌به‌بایت یکی است و CI می‌تواند
«تازه بودن» فایل داخل مخزن را بررسی کند.

استفاده:
    python3 tools/build-zip.py            # ساخت/به‌روزرسانی
    python3 tools/build-zip.py --check    # فقط بررسی؛ اگر عقب باشد خطا می‌دهد
"""

from __future__ import annotations

import argparse
import os
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN_DIR = "woocommerce-advanced-price-manager"
ZIP_PATH = os.path.join(ROOT, "price-manager.zip")

# چیزهایی که در بسته‌ی نصب نباید باشند (توسعه/تست/گیت).
EXCLUDE_DIRS = {"node_modules", "vendor", ".git", "tests"}
EXCLUDE_FILES = {".DS_Store", "Thumbs.db"}

# زمان ثابت برای همه‌ی رکوردها (خروجی قطعی).
FIXED_DATE = (2020, 1, 1, 0, 0, 0)


def collect() -> list:
    """فهرست مسیرهای نسبی داخل افزونه، مرتب‌شده."""
    paths = []

    for root, dirs, files in os.walk(os.path.join(ROOT, PLUGIN_DIR)):
        dirs[:] = sorted(d for d in dirs if d not in EXCLUDE_DIRS)

        rel_root = os.path.relpath(root, ROOT).replace(os.sep, "/")
        for name in sorted(files):
            if name in EXCLUDE_FILES or name.endswith(".log"):
                continue
            paths.append(f"{rel_root}/{name}")

    return sorted(paths)


def build_bytes() -> bytes:
    """
    فایل zip را در حافظه می‌سازد.
    نویسه‌ی مسیرها UTF-8 و بدون فشرده‌سازی مسیر اضافه است تا با همه‌ی ابزارها
    باز شود (ZipFile پیش‌فرض همین است).
    """
    import io

    buffer = io.BytesIO()

    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        # پوشه‌های خالی هم ثبت می‌شوند تا ساختار در unzip حفظ شود.
        dirs = set()
        for rel in collect():
            parts = rel.split("/")
            for i in range(1, len(parts)):
                dirs.add("/".join(parts[:i]) + "/")

        for directory in sorted(dirs):
            info = zipfile.ZipInfo(directory, date_time=FIXED_DATE)
            info.external_attr = (0o40755 << 16) | 0x10
            info.compress_type = zipfile.ZIP_STORED
            zf.writestr(info, b"")

        for rel in collect():
            full = os.path.join(ROOT, rel)
            with open(full, "rb") as handle:
                data = handle.read()

            info = zipfile.ZipInfo(rel, date_time=FIXED_DATE)
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zf.writestr(info, data)

    return buffer.getvalue()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="فقط بررسی تازه‌بودن zip")
    args = parser.parse_args()

    content = build_bytes()

    if args.check:
        if not os.path.exists(ZIP_PATH):
            print("❌ price-manager.zip وجود ندارد. python3 tools/build-zip.py را اجرا کنید.")
            return 1
        with open(ZIP_PATH, "rb") as handle:
            if handle.read() != content:
                print("❌ price-manager.zip از سورس عقب است. python3 tools/build-zip.py را اجرا کنید.")
                return 1
        print("✅ price-manager.zip با سورس هم‌خوان است.")
        return 0

    with open(ZIP_PATH, "wb") as handle:
        handle.write(content)

    files = len(collect())
    print(f"✅ ساخته شد: price-manager.zip — {files} فایل، {len(content) // 1024} کیلوبایت.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
