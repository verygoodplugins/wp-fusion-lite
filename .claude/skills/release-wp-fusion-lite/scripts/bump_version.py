#!/usr/bin/env python3
"""
bump_version.py — set the new Lite version atomically in the three places
it lives:

  1. wp-fusion-lite.php header comment:    `* Version: X`
  2. wp-fusion-lite.php constant:          `define( 'WP_FUSION_VERSION', 'X' );`
  3. readme.txt:                           `Stable tag: X`

If any of the three files don't match the expected pattern, the script
aborts without writing anything — it's safer to stop than commit a
half-update.

Usage:
    bump_version.py 3.47.10
    bump_version.py 3.47.10 --dry-run
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

VERSION_RE = re.compile(r"^\d+(?:\.\d+){1,3}$")

HEADER_RE = re.compile(r"(^\s*\*\s*Version:\s*)[\d.]+(\s*)$", re.MULTILINE)
CONSTANT_RE = re.compile(
    r"(define\(\s*'WP_FUSION_VERSION'\s*,\s*')[\d.]+('\s*\)\s*;)"
)
STABLE_TAG_RE = re.compile(r"(^Stable tag:\s*)[\d.]+(\s*)$", re.MULTILINE)


def find_lite_root() -> Path:
    here = Path(__file__).resolve()
    for parent in [here, *here.parents]:
        if (parent / ".git").exists():
            return parent
    raise SystemExit("could not locate Lite repo root (no .git ancestor)")


def replace_once(pattern: re.Pattern, text: str, replacement: str, label: str) -> str:
    new_text, count = pattern.subn(replacement, text, count=1)
    if count != 1:
        raise SystemExit(f"expected exactly one match for {label}, got {count}")
    return new_text


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("version", help="new version string, e.g. 3.47.10")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    if not VERSION_RE.match(args.version):
        print(f"version string looks malformed: {args.version}", file=sys.stderr)
        return 2

    root = find_lite_root()
    main_php = root / "wp-fusion-lite.php"
    readme = root / "readme.txt"
    if not main_php.is_file():
        print(f"missing {main_php}", file=sys.stderr)
        return 1
    if not readme.is_file():
        print(f"missing {readme}", file=sys.stderr)
        return 1

    php_text = main_php.read_text()
    readme_text = readme.read_text()

    new_php = replace_once(
        HEADER_RE,
        php_text,
        rf"\g<1>{args.version}\g<2>",
        "header `* Version:` in wp-fusion-lite.php",
    )
    new_php = replace_once(
        CONSTANT_RE,
        new_php,
        rf"\g<1>{args.version}\g<2>",
        "`define( 'WP_FUSION_VERSION', ... );` in wp-fusion-lite.php",
    )
    new_readme = replace_once(
        STABLE_TAG_RE,
        readme_text,
        rf"\g<1>{args.version}\g<2>",
        "`Stable tag:` in readme.txt",
    )

    changes = []
    if new_php != php_text:
        changes.append(("wp-fusion-lite.php", main_php, new_php))
    if new_readme != readme_text:
        changes.append(("readme.txt", readme, new_readme))

    if not changes:
        print(f"already at {args.version} — no changes")
        return 0

    for label, path, content in changes:
        if args.dry_run:
            print(f"[dry] would bump {label} -> {args.version}")
        else:
            path.write_text(content)
            print(f"bumped {label} -> {args.version}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
