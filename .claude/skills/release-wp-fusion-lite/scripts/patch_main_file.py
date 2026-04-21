#!/usr/bin/env python3
"""
patch_main_file.py — transform the freshly-synced wp-fusion-lite.php (which
is really wp-fusion.php renamed) into the Lite variant:

  - Rewrite the plugin header (Name, Description, Text Domain, drop
    GitHub Plugin URI and WC/Elementor comment lines).
  - Rename the class WP_Fusion -> WP_Fusion_Lite as a bare identifier,
    leaving quoted-string occurrences alone (so class_exists('WP_Fusion')
    inside is_full_version() still detects the Pro class correctly).
  - Strip out four functions that only make sense in Pro: updater(),
    wpf_update_message(), load_textdomain(), wpf_update_message_error().
    Tolerates any of these already being absent.

Flags:
    --dry-run     Print the diff without writing.
    --file PATH   Override the target file (default: wp-fusion-lite.php in
                  the detected Lite repo root).
"""
from __future__ import annotations

import argparse
import difflib
import os
import re
import sys
from pathlib import Path

HEADER_EDITS = [
    (re.compile(r"^\s*\*\s*Plugin Name:\s*WP Fusion\s*$", re.MULTILINE),
     " * Plugin Name: WP Fusion Lite"),
    (re.compile(r"^\s*\*\s*Description:.*$", re.MULTILINE),
     " * Description: WP Fusion Lite synchronizes your WordPress users with your CRM or marketing automation system."),
    (re.compile(r"^\s*\*\s*Text Domain:\s*wp-fusion\s*$", re.MULTILINE),
     " * Text Domain: wp-fusion-lite"),
]

# Header comment lines to strip entirely (the whole line, including the
# trailing newline).
HEADER_LINES_TO_REMOVE = [
    re.compile(r"^\s*\*\s*GitHub Plugin URI:.*\r?\n", re.MULTILINE),
    re.compile(r"^\s*\*\s*WC requires at least:.*\r?\n", re.MULTILINE),
    re.compile(r"^\s*\*\s*WC tested up to:.*\r?\n", re.MULTILINE),
    re.compile(r"^\s*\*\s*Elementor tested up to:.*\r?\n", re.MULTILINE),
    re.compile(r"^\s*\*\s*Elementor Pro tested up to:.*\r?\n", re.MULTILINE),
]

# Replace "WP_Fusion" as a bare identifier (including inside /* comments */)
# but NOT inside single- or double-quoted strings. This uses a negative
# lookbehind on the quote chars and a negative lookahead on "_Lite" so the
# substitution is idempotent.
CLASS_RENAME_RE = re.compile(r"(?<![\'\"])\bWP_Fusion\b(?!_Lite)")

FUNCTIONS_TO_REMOVE = [
    "updater",
    "wpf_update_message",
    "load_textdomain",
    "wpf_update_message_error",
]

# Exact literal string swaps applied after class rename.
# Pro checks if Lite is active (to deactivate it); Lite must do the reverse.
LITERAL_SWAPS = [
    (
        "is_plugin_active( 'wp-fusion-lite/wp-fusion-lite.php' )",
        "is_plugin_active( 'wp-fusion/wp-fusion.php' )",
    ),
]


def remove_function(source: str, func_name: str) -> tuple[str, bool]:
    """Remove a method definition (and its preceding docblock, if any) from
    the source. Returns (new_source, removed?).

    Implementation: find the `function <name>` keyword, walk forward to the
    opening brace that starts the method body, count braces until balanced,
    then extend the removal range backward to cover a leading docblock
    comment if present.
    """
    # Match: optional modifier words, "function", name, whitespace, "(".
    pattern = re.compile(
        r"^[ \t]*(?:public\s+|private\s+|protected\s+|static\s+)*function\s+"
        + re.escape(func_name)
        + r"\s*\(",
        re.MULTILINE,
    )
    m = pattern.search(source)
    if not m:
        return source, False

    # Walk forward from the end of the regex match to find the opening brace.
    i = m.end()
    n = len(source)
    # Skip the parameter list (parentheses may be balanced themselves for
    # default values that include calls — keep a simple paren counter).
    depth = 1  # we already consumed the opening '('.
    while i < n and depth > 0:
        c = source[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
        i += 1
    # Now skip whitespace until the body's '{'.
    while i < n and source[i] not in "{":
        i += 1
    if i >= n:
        raise ValueError(f"could not find opening brace for {func_name}")

    # Balanced brace scan from here, respecting strings and comments so braces
    # inside them don't throw off the count.
    body_start = i
    depth = 0
    in_str: str | None = None
    escaped = False
    block_comment = False
    line_comment = False
    while i < n:
        c = source[i]
        nxt = source[i + 1] if i + 1 < n else ""

        if line_comment:
            if c == "\n":
                line_comment = False
        elif block_comment:
            if c == "*" and nxt == "/":
                block_comment = False
                i += 1
        elif in_str is not None:
            if escaped:
                escaped = False
            elif c == "\\":
                escaped = True
            elif c == in_str:
                in_str = None
        else:
            if c == "'" or c == '"':
                in_str = c
            elif c == "/" and nxt == "/":
                line_comment = True
                i += 1
            elif c == "/" and nxt == "*":
                block_comment = True
                i += 1
            elif c == "#":
                line_comment = True
            elif c == "{":
                depth += 1
            elif c == "}":
                depth -= 1
                if depth == 0:
                    # end of function body
                    body_end = i + 1
                    break
        i += 1
    else:
        raise ValueError(f"unbalanced braces while removing {func_name}")

    # Extend the start backward to swallow the preceding docblock, if the
    # method is preceded by one. Walk from the line containing the "function"
    # keyword (m.start()) backward to the nearest "*/" closing a block
    # comment; if it's immediately before (whitespace only), consume it.
    removal_start = m.start()

    # Find the start of the line where `function` appears — that's already
    # removal_start (re.MULTILINE matches at start of line / file).
    # Look backward for /** ... */ docblock.
    prefix = source[:removal_start]
    # Strip trailing whitespace from prefix to see what's immediately above.
    stripped = prefix.rstrip()
    if stripped.endswith("*/"):
        # Find the matching /**.
        docblock_end = len(stripped)  # exclusive
        docblock_start = stripped.rfind("/**", 0, docblock_end)
        if docblock_start != -1:
            # Only consume if nothing but whitespace sits between the docblock
            # and the function keyword.
            between = prefix[docblock_end:]
            if between.strip() == "":
                # Also consume the indentation on the docblock's line.
                line_start = stripped.rfind("\n", 0, docblock_start) + 1
                removal_start = line_start

    # Extend body_end forward through the trailing newline so we don't leave
    # a blank line behind.
    if body_end < n and source[body_end] == "\n":
        body_end += 1
    # Collapse a run of blank lines that might result.
    while body_end < n and source[body_end] == "\n" and (body_end + 1 >= n or source[body_end + 1] == "\n"):
        body_end += 1

    new_source = source[:removal_start] + source[body_end:]
    return new_source, True


def apply_header_edits(source: str) -> str:
    for pat, replacement in HEADER_EDITS:
        source = pat.sub(replacement, source, count=1)
    for pat in HEADER_LINES_TO_REMOVE:
        source = pat.sub("", source)
    return source


def apply_literal_swaps(source: str) -> tuple[str, list[str]]:
    """Apply LITERAL_SWAPS. Returns (new_source, list_of_applied_descriptions)."""
    applied = []
    for needle, replacement in LITERAL_SWAPS:
        if needle in source:
            source = source.replace(needle, replacement)
            applied.append(f"{needle!r} -> {replacement!r}")
    return source, applied


def rename_class(source: str) -> tuple[str, int]:
    new_source, count = CLASS_RENAME_RE.subn("WP_Fusion_Lite", source)
    return new_source, count


def find_lite_root() -> Path:
    here = Path(__file__).resolve()
    for parent in [here, *here.parents]:
        if (parent / ".git").exists():
            return parent
    raise SystemExit("could not locate Lite repo root (no .git ancestor)")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--file", type=Path, default=None)
    args = ap.parse_args()

    target = args.file or (find_lite_root() / "wp-fusion-lite.php")
    if not target.is_file():
        print(f"target file not found: {target}", file=sys.stderr)
        return 1

    original = target.read_text()
    source = original

    source = apply_header_edits(source)

    removed = []
    for fn in FUNCTIONS_TO_REMOVE:
        source, did = remove_function(source, fn)
        if did:
            removed.append(fn)

    source, rename_count = rename_class(source)
    source, swapped = apply_literal_swaps(source)

    if source == original:
        print(f"{target}: no changes")
        return 0

    if args.dry_run:
        diff = difflib.unified_diff(
            original.splitlines(keepends=True),
            source.splitlines(keepends=True),
            fromfile=f"a/{target.name}",
            tofile=f"b/{target.name}",
        )
        sys.stdout.writelines(diff)
        print(f"\n[dry-run] would rename {rename_count} bare WP_Fusion references")
        print(f"[dry-run] would remove functions: {removed or '(none)'}")
        if swapped:
            for s in swapped:
                print(f"[dry-run] literal swap: {s}")
        return 0

    target.write_text(source)
    print(f"{target}: wrote changes")
    print(f"  renamed {rename_count} bare WP_Fusion references")
    print(f"  removed functions: {removed or '(none)'}")
    if swapped:
        for s in swapped:
            print(f"  literal swap: {s}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
