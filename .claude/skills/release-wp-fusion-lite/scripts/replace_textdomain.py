#!/usr/bin/env python3
"""
replace_textdomain.py — walk every .php file under the Lite repo and
replace the literal textdomain strings:

    'wp-fusion'  ->  'wp-fusion-lite'
    "wp-fusion"  ->  "wp-fusion-lite"

This matches the manual find/replace the release process has always done
(see references/textdomain-context.md for the reasoning). The quote-scoped
replacement is a close-enough approximation of "only touch textdomain
arguments" — it also incidentally covers strings in configs, slugs in
__( 'foo', 'wp-fusion' ) calls, etc. That over-reach is intentional and
matches the historical manual process.

Excludes vendor/, node_modules/, .git/, and build/ bundles (minified JS
shouldn't be source-edited).

Flags:
    --dry-run     Print the file list and match counts, don't write.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

EXCLUDE_DIRS = {".git", "vendor", "node_modules"}
# Do not touch the built secure-block bundles; they are emitted artifacts.
EXCLUDE_PATH_CONTAINS = ("/build/",)

REPLACEMENTS = [
    ("'wp-fusion'", "'wp-fusion-lite'"),
    ('"wp-fusion"', '"wp-fusion-lite"'),
]


def find_lite_root() -> Path:
    here = Path(__file__).resolve()
    for parent in [here, *here.parents]:
        if (parent / ".git").exists():
            return parent
    raise SystemExit("could not locate Lite repo root (no .git ancestor)")


def should_skip(path: Path, root: Path) -> bool:
    rel = path.relative_to(root).as_posix()
    parts = rel.split("/")
    if any(part in EXCLUDE_DIRS for part in parts):
        return True
    if any(s in "/" + rel for s in EXCLUDE_PATH_CONTAINS):
        return True
    return False


def process_file(path: Path) -> tuple[int, str | None]:
    """Return (total_replacements, new_content_or_None)."""
    try:
        text = path.read_text()
    except UnicodeDecodeError:
        return 0, None
    total = 0
    new_text = text
    for needle, replacement in REPLACEMENTS:
        count = new_text.count(needle)
        if count:
            new_text = new_text.replace(needle, replacement)
            total += count
    if total == 0:
        return 0, None
    return total, new_text


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--root", type=Path, default=None, help="override Lite repo root")
    args = ap.parse_args()

    root = args.root or find_lite_root()
    root = root.resolve()

    files_touched = 0
    total_replacements = 0

    for path in root.rglob("*.php"):
        if should_skip(path, root):
            continue
        count, new_text = process_file(path)
        if count == 0:
            continue
        files_touched += 1
        total_replacements += count
        rel = path.relative_to(root)
        if args.dry_run:
            print(f"  [dry] {rel}: {count} replacement(s)")
        else:
            path.write_text(new_text)
            print(f"  {rel}: {count} replacement(s)")

    print()
    prefix = "[dry] would touch" if args.dry_run else "touched"
    print(f"{prefix} {files_touched} file(s), {total_replacements} replacement(s) total.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
