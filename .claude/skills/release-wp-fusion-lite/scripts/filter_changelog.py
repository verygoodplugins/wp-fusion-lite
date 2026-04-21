#!/usr/bin/env python3
"""
filter_changelog.py — take Pro's readme.txt changelog, filter out entries
that only make sense in Pro (paid integrations), and emit a draft Lite
changelog for human review.

Workflow:
  1. Parse Lite's current readme.txt to find the last shipped version
     (from `Stable tag:`).
  2. Parse Pro's readme.txt and extract all `= VERSION - DATE =` entries
     strictly newer than the Lite shipped version.
  3. For each entry, drop bullets that mention any keyword from
     references/integration-keywords.md (case-insensitive word match).
  4. Flag bullets within the same release that mention the same CRM name
     (candidates for manual consolidation — script does NOT auto-collapse
     wording, that's a judgment call).
  5. Concatenate the filtered Pro entries with Lite's existing changelog
     (the entries at/below Lite's last shipped version). Trim oldest
     entries until the total changelog section is under 5,000 words.
  6. Write the draft `== Changelog ==` section to a file.

The script is non-destructive by default: it writes to the output path
(default: a temp file) and tells you what to do next. It never modifies
readme.txt in place.

Flags:
    --pro-readme PATH     Path to Pro's readme.txt (default: ../wp-fusion/readme.txt).
    --lite-readme PATH    Path to Lite's readme.txt (default: ./readme.txt).
    --output PATH         Where to write the draft (default: stdout).
    --keywords PATH       Override the integration-keywords file.
    --word-limit N        Changelog word cap (default: 5000).
    --verbose             Print dropped bullets + dedup candidates.
"""
from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable

# ---------- helpers ----------

VERSION_HEADER_RE = re.compile(r"^=\s*([\d.]+)\s*-\s*(.+?)\s*=\s*$")
STABLE_TAG_RE = re.compile(r"^Stable tag:\s*([\d.]+)\s*$", re.MULTILINE)


def parse_version(v: str) -> tuple[int, ...]:
    """3.46.3.1 -> (3, 46, 3, 1). Short strings compare as expected."""
    parts = []
    for chunk in v.split("."):
        try:
            parts.append(int(chunk))
        except ValueError:
            # Fallback: treat non-numeric chunk as 0 (shouldn't happen).
            parts.append(0)
    return tuple(parts)


def version_newer_than(a: str, b: str) -> bool:
    return parse_version(a) > parse_version(b)


# ---------- parsing ----------

@dataclass
class ChangelogEntry:
    version: str
    date: str
    bullets: list[str] = field(default_factory=list)

    def render(self) -> str:
        out = [f"= {self.version} - {self.date} ="]
        out.extend(self.bullets)
        return "\n".join(out)


def extract_changelog_section(text: str) -> tuple[str, str, str]:
    """Split readme text into (pre_changelog, changelog_body, post_changelog).

    The changelog section is delimited by `== Changelog ==` and extends until
    the next `== <section> ==` header or end of file.
    """
    # Find "== Changelog ==" header.
    m = re.search(r"^==\s*Changelog\s*==\s*$", text, re.MULTILINE)
    if not m:
        raise ValueError("no `== Changelog ==` section found in readme")
    start = m.end()

    # Find the next top-level section header after Changelog, if any.
    end_m = re.search(r"^==[^=]+==\s*$", text[start:], re.MULTILINE)
    end = start + end_m.start() if end_m else len(text)

    return text[: m.start()], text[m.end() : end], text[end:]


def parse_entries(changelog_body: str) -> list[ChangelogEntry]:
    entries: list[ChangelogEntry] = []
    current: ChangelogEntry | None = None
    for line in changelog_body.splitlines():
        m = VERSION_HEADER_RE.match(line.strip())
        if m:
            if current is not None:
                entries.append(current)
            current = ChangelogEntry(version=m.group(1), date=m.group(2))
            continue
        if current is None:
            continue
        if line.strip().startswith("*"):
            current.bullets.append(line.rstrip())
        # Ignore blank lines between bullets — they come back out in rendering.
    if current is not None:
        entries.append(current)
    return entries


# ---------- filtering ----------

def load_keywords(keywords_file: Path) -> list[str]:
    """Keywords file: lines starting with `-` are list items. Lowercase,
    unique."""
    if not keywords_file.exists():
        raise SystemExit(f"keywords file not found: {keywords_file}")
    out: list[str] = []
    for line in keywords_file.read_text().splitlines():
        s = line.strip()
        if s.startswith("-"):
            out.append(s[1:].strip().lower())
    if not out:
        raise SystemExit(f"no keywords parsed from {keywords_file}")
    return sorted(set(out), key=len, reverse=True)


def bullet_matches_keyword(bullet: str, keywords: Iterable[str]) -> str | None:
    """Return the first matching keyword, or None."""
    low = bullet.lower()
    for kw in keywords:
        # Word-boundary-ish match: require the keyword to be a substring
        # bordered by non-alphanumeric chars on both sides (so "wc" doesn't
        # match "wcag"). Use regex with boundaries, escaping keyword.
        pat = r"(?<![a-z0-9])" + re.escape(kw) + r"(?![a-z0-9])"
        if re.search(pat, low):
            return kw
    return None


# Simple list of CRM keywords for dedup detection. Not authoritative — if a
# release has 3 bullets all mentioning "Capsule", this flags them for
# manual consolidation.
CRM_KEYWORDS = [
    "capsule", "hubspot", "activecampaign", "klaviyo", "mailchimp",
    "convertkit", "kit", "drip", "ontraport", "infusionsoft", "keap",
    "zoho", "salesforce", "nationbuilder", "customer.io", "customer io",
    "sendinblue", "brevo", "getresponse", "highlevel", "go high level",
    "mailerlite", "mautic", "maropost", "sender.net", "sender",
    "fluentcrm", "groundhogg", "birdsend", "tubular", "loopify",
    "autonami", "copper", "flodesk", "agilecrm", "constant contact",
    "convertfox", "drift", "intercom", "mailengine", "mailpoet",
    "mailjet", "omnisend", "pipedrive", "platformly", "quentn",
    "sendfox", "sendy", "staffbase", "tubular", "userengage",
    "vbout", "wp-amelia", "dynamics", "engagebay",
]


def find_dedup_candidates(bullets: list[str]) -> dict[str, list[int]]:
    """Return {crm_keyword: [bullet_indices]} where 2+ bullets mention the
    same CRM within a single release entry."""
    found: dict[str, list[int]] = {}
    for i, b in enumerate(bullets):
        low = b.lower()
        for kw in CRM_KEYWORDS:
            pat = r"(?<![a-z0-9])" + re.escape(kw) + r"(?![a-z0-9])"
            if re.search(pat, low):
                found.setdefault(kw, []).append(i)
                break  # one kw per bullet — first match wins
    return {k: v for k, v in found.items() if len(v) > 1}


def filter_entry(entry: ChangelogEntry, keywords: list[str], verbose: bool) -> tuple[ChangelogEntry, list[str], dict[str, list[int]]]:
    """Return (filtered_entry, dropped_bullets, dedup_candidates)."""
    kept: list[str] = []
    dropped: list[str] = []
    for b in entry.bullets:
        kw = bullet_matches_keyword(b, keywords)
        if kw is not None:
            dropped.append(f"[{kw}] {b}")
        else:
            kept.append(b)
    filtered = ChangelogEntry(version=entry.version, date=entry.date, bullets=kept)
    dedup = find_dedup_candidates(kept)
    return filtered, dropped, dedup


# ---------- word count trimming ----------

def word_count(text: str) -> int:
    return len(text.split())


def trim_to_word_limit(entries: list[ChangelogEntry], limit: int) -> tuple[list[ChangelogEntry], int, int]:
    """Keep entries from newest to oldest until cumulative word count would
    exceed limit. Returns (kept_entries, kept_word_count, dropped_count).

    Entries are assumed ordered newest-first.
    """
    kept: list[ChangelogEntry] = []
    total = 0
    for entry in entries:
        rendered = entry.render()
        wc = word_count(rendered)
        if total + wc > limit:
            break
        kept.append(entry)
        total += wc
    return kept, total, len(entries) - len(kept)


# ---------- main ----------

def find_lite_root() -> Path:
    here = Path(__file__).resolve()
    for parent in [here, *here.parents]:
        if (parent / ".git").exists():
            return parent
    raise SystemExit("could not locate Lite repo root (no .git ancestor)")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pro-readme", type=Path, default=None)
    ap.add_argument("--lite-readme", type=Path, default=None)
    ap.add_argument("--from-version", default=None,
                    help="Override the last-shipped Lite version instead of reading Stable tag: "
                         "from readme.txt. Useful when the branch pre-sets Stable tag to a future "
                         "value (e.g. 3.47.9) but the actual last release was 3.46.3.1.")
    ap.add_argument("--output", type=Path, default=None)
    ap.add_argument("--keywords", type=Path, default=None)
    ap.add_argument("--word-limit", type=int, default=5000)
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()

    lite_root = find_lite_root()
    skill_dir = Path(__file__).resolve().parent.parent
    pro_readme = args.pro_readme or (lite_root.parent / "wp-fusion" / "readme.txt")
    lite_readme = args.lite_readme or (lite_root / "readme.txt")
    keywords_file = args.keywords or (skill_dir / "references" / "integration-keywords.md")

    if not pro_readme.is_file():
        print(f"Pro readme not found: {pro_readme}", file=sys.stderr)
        return 1
    if not lite_readme.is_file():
        print(f"Lite readme not found: {lite_readme}", file=sys.stderr)
        return 1

    pro_text = pro_readme.read_text()
    lite_text = lite_readme.read_text()

    # Current Lite shipped version — explicit flag wins over Stable tag:.
    if args.from_version:
        lite_shipped = args.from_version
    else:
        m = STABLE_TAG_RE.search(lite_text)
        if not m:
            print("could not find `Stable tag:` in Lite readme.txt", file=sys.stderr)
            return 1
        lite_shipped = m.group(1)

    _, pro_body, _ = extract_changelog_section(pro_text)
    _, lite_body, _ = extract_changelog_section(lite_text)

    pro_entries = parse_entries(pro_body)
    lite_entries = parse_entries(lite_body)

    # Filter Pro entries newer than Lite's shipped version.
    keywords = load_keywords(keywords_file)
    new_entries = [e for e in pro_entries if version_newer_than(e.version, lite_shipped)]

    if not new_entries:
        print(f"no Pro entries newer than Lite's shipped version ({lite_shipped}).", file=sys.stderr)
        print("nothing to do — is Lite already at or past Pro's version?", file=sys.stderr)
        return 0

    filtered_new: list[ChangelogEntry] = []
    all_dropped: list[tuple[str, list[str]]] = []
    all_dedup: list[tuple[str, dict[str, list[int]]]] = []
    for e in new_entries:
        fe, dropped, dedup = filter_entry(e, keywords, args.verbose)
        filtered_new.append(fe)
        if dropped:
            all_dropped.append((e.version, dropped))
        if dedup:
            all_dedup.append((e.version, dedup))

    # Drop entries whose bullets are ALL filtered out.
    filtered_new = [e for e in filtered_new if e.bullets]

    # Combine: new entries (newest-first) + existing Lite entries (already newest-first).
    # Guard against overlap — if somehow a version appears in both, keep the new one.
    seen_versions = {e.version for e in filtered_new}
    combined = filtered_new + [e for e in lite_entries if e.version not in seen_versions]

    kept, total_words, dropped_count = trim_to_word_limit(combined, args.word_limit)

    # Render.
    out_lines = ["== Changelog ==", ""]
    for entry in kept:
        out_lines.append(entry.render())
        out_lines.append("")
    out_text = "\n".join(out_lines).rstrip() + "\n"

    if args.output:
        args.output.write_text(out_text)
        print(f"wrote draft changelog to {args.output}")
    else:
        sys.stdout.write(out_text)

    # Report to stderr so stdout stays clean if piped.
    print("", file=sys.stderr)
    print(f"Lite shipped:     {lite_shipped}", file=sys.stderr)
    print(f"New Pro entries:  {len(new_entries)}", file=sys.stderr)
    print(f"After filtering:  {len(filtered_new)} entries retained", file=sys.stderr)
    print(f"Final changelog:  {len(kept)} entries, {total_words} words", file=sys.stderr)
    if dropped_count:
        print(f"  (trimmed {dropped_count} oldest entries to stay under {args.word_limit} words)", file=sys.stderr)

    if args.verbose:
        for version, dropped in all_dropped:
            print(f"\n-- dropped bullets in {version} --", file=sys.stderr)
            for b in dropped:
                print(f"  {b}", file=sys.stderr)
        for version, dedup in all_dedup:
            print(f"\n-- dedup candidates in {version} --", file=sys.stderr)
            for kw, idxs in dedup.items():
                print(f"  {kw!r}: {len(idxs)} bullets — consider collapsing", file=sys.stderr)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
