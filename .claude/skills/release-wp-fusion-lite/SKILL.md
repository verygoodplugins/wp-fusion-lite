---
name: release-wp-fusion-lite
description: Use this skill whenever cutting, building, syncing, or deploying a new WP Fusion Lite release — pulling the latest code from the WP Fusion (Pro) plugin, stripping it down to the Lite feature set, curating the readme changelog, and shipping the release to wordpress.org via the SVN deploy workflow. Trigger on phrases like "release lite", "deploy wp-fusion-lite", "cut a lite release", "sync lite from pro", "push a new lite version", "update lite to match pro", "ship lite", or any task that involves copying Pro → Lite and publishing to the WordPress.org plugin directory.
---

# release-wp-fusion-lite

Automates the full WP Fusion → WP Fusion Lite release pipeline: sync Pro → Lite, strip out paid features, patch the main PHP file, curate the changelog, bump the version, verify, and (after a human smoke-test) tag + push to trigger the wordpress.org SVN deploy.

## When to use this skill

Any time a new WP Fusion Lite release needs to go out. Typically this happens after a Pro release — Pro ships at some cadence, and Lite follows with a trimmed-down version of the same codebase. The skill's job is to make that mechanical transformation boring and reliable.

Do NOT use this skill for:
- Editing Lite code directly outside of a release (those are regular commits).
- Releasing Pro itself (Pro has its own pipeline under `verygoodplugins/wp-fusion`).
- Hotfixes to already-released Lite versions that don't originate from Pro.

## Repo layout assumption

The skill assumes both plugins are cloned as siblings:

```
wp-content/plugins/
├── wp-fusion/        ← Pro, source of truth
└── wp-fusion-lite/   ← Lite, where this skill runs
```

If the paths are different, override via env vars on any script: `PRO_PATH=/some/other/wp-fusion LITE_PATH=/some/other/wp-fusion-lite ./scripts/sync_from_pro.sh`.

## Workflow — run in order

The release is broken into phases. Between phases, summarize what happened and wait for user confirmation before any step that touches shared state (Dependabot merges, `git push`, tag push).

### Phase 0 — Pre-flight

1. Confirm `cwd` is the Lite repo and Pro exists as a sibling.
2. **List open Dependabot PRs** and pause:
   ```bash
   gh pr list --search "author:app/dependabot is:open" --repo verygoodplugins/wp-fusion-lite
   ```
   (The `--search` form works consistently across `gh` versions; `--author app/dependabot` is unreliable.)
   If any are open, stop and ask the user to merge them on GitHub first. Do not auto-merge — it's a shared-state action and the user prefers to review each PR.
3. **Verify Lite working tree is clean** — `git status --porcelain`. The sync overwrites `includes/`, `assets/`, `build/`, so uncommitted local changes would be lost. Abort with a clear message if dirty.
4. **Pull latest on both repos**:
   ```bash
   git -C "$LITE_PATH" pull --ff-only
   git -C "$PRO_PATH" pull --ff-only
   ```
5. Ask the user for the **target version**. Default: match Pro's current `WP_FUSION_VERSION`. Confirm before proceeding.

### Phase 1 — Sync from Pro

Run `scripts/sync_from_pro.sh`. It:

1. `rsync`s `wp-fusion.php`, `assets/`, `includes/`, `build/` from Pro → Lite (with `--delete` so stale files in those trees are removed).
2. Deletes all of `includes/integrations/` except `class-base.php`.
3. Deletes `includes/class-api.php` and `includes/admin/class-updater.php`.
4. Deletes `languages/` if present.
5. In `build/`, deletes everything that isn't `secure-block*` (keeps only the Secure Block assets that Lite actually uses).
6. Renames `wp-fusion.php` → `wp-fusion-lite.php`.

Everything outside those four top-level items is preserved: `.git`, `.github`, `.claude`, `readme.txt`, `README.md`, `.distignore`, `package.json`, `composer.json`, etc.

### Phase 2 — Patch `wp-fusion-lite.php`

Run `scripts/patch_main_file.py`. It:

- Replaces the plugin header:
  - `Plugin Name: WP Fusion` → `Plugin Name: WP Fusion Lite`
  - Description → `WP Fusion Lite synchronizes your WordPress users with your CRM or marketing automation system.`
  - `Text Domain: wp-fusion` → `Text Domain: wp-fusion-lite`
  - Removes the `WC requires at least`, `WC tested up to`, `Elementor tested up to`, `Elementor Pro tested up to` comment lines.
  - Removes the `GitHub Plugin URI:` line (updater uses a different mechanism in Lite — none, actually).
- Renames the class: `WP_Fusion` → `WP_Fusion_Lite` as a bare identifier, including bare usages in `instanceof`, `new`, `::instance()`, and docblocks.
  - **Does NOT** rewrite quoted occurrences. `class_exists( 'WP_Fusion' )` inside `is_full_version()` must stay as the Pro-class string check — that's how Lite detects it's Lite vs Pro.
- Removes the functions `updater()`, `wpf_update_message()`, `load_textdomain()`, and `wpf_update_message_error()` if present (balanced brace removal). It's tolerant of any of these already being absent.

### Phase 3 — Textdomain replace (repo-wide)

Run `scripts/replace_textdomain.py`. It:

- In all `.php` files (excluding `vendor/`, `node_modules/`, `.git/`), replaces the literal `'wp-fusion'` → `'wp-fusion-lite'` and `"wp-fusion"` → `"wp-fusion-lite"`.
- Prints a diff summary (files touched, total replacements).

This is the translation textdomain swap. See `references/textdomain-context.md` for why the literal-quoted-string replace is the right scope.

### Phase 4 — readme.txt curation

Run `scripts/filter_changelog.py`. It:

1. Determines the last shipped Lite version — from `--from-version` if supplied, otherwise from `Stable tag:` in Lite's `readme.txt`.
2. Reads the Pro `readme.txt` and extracts all version entries newer than that.
3. Filters each bullet against `references/integration-keywords.md` — drops any bullet that mentions a paid-only integration (WooCommerce, LearnDash, MemberPress, etc.).
4. Runs a simple dedup to collapse bullets that mention the same CRM keyword in the same release (e.g. three "Capsule" bullets → one "Capsule bugfixes" line).
5. Trims oldest release entries until the final changelog section is under **5,000 words** (wordpress.org's cap).
6. Writes the proposed new `== Changelog ==` section to a temp file and **pauses for the user to review and hand-edit**.

The phrasing judgment ("the feature was added and then fixed — just say Added") is left to the user. The script gets you to a 90% draft fast.

**⚠️ Stable tag pitfall:** When releasing after a long gap, the branch may have `Stable tag:` pre-set to a value that's not actually shipped yet (e.g. the branch was set up with `3.47.9` but the last real Lite release was `3.46.3.1`). Without `--from-version`, the script would only pick up entries newer than `3.47.9`, silently missing all the intermediate Pro versions. **Always verify the last-shipped version against `git log` before running Phase 4**, and pass `--from-version <actual-last-shipped>` when there's a mismatch.

**How the hand-off back into `readme.txt` works:**

1. Run: `python scripts/filter_changelog.py --from-version <last-shipped> --output /tmp/lite-changelog-draft.txt`
   - `<last-shipped>` is the version from the last real Lite release (check `git log` — it's the commit message of the previous version commit, e.g. `3.46.3.1`).
   - Omit `--from-version` only if `Stable tag:` in `readme.txt` already reflects the true last-shipped version.
2. Tell the user: "Draft saved to `/tmp/lite-changelog-draft.txt`. Open it, collapse any redundant bullets (dedup candidates are flagged on stderr), and let me know when it's final. I'll splice it into `readme.txt` for you."
3. When the user confirms, read the draft file and the current `readme.txt`, then use the `Edit` tool to replace the entire `== Changelog ==` section (from the header through just before the next `== Section ==` header, or end of file) with the draft contents. Do not re-run `filter_changelog.py` — that would overwrite the user's edits.
4. Verify with a grep that `== Changelog ==` appears exactly once in the new `readme.txt`.

Also in this phase, remind the user to:
- Update `Tested up to:` in `readme.txt` to the current WP version. Fetch the current version from `https://api.wordpress.org/core/version-check/1.7/` and suggest it.
- Review the `== Supported CRMs ==` section against Pro for new additions.
- Update the Supported CRMs list in `README.md` to match.

### Phase 5 — Version bump

Run `scripts/bump_version.py <new-version>`. Updates three places atomically:

1. `wp-fusion-lite.php` header `* Version: X`
2. `wp-fusion-lite.php` `define( 'WP_FUSION_VERSION', 'X' );`
3. `readme.txt` `Stable tag: X`

If any of the three files don't match the expected pattern, the script aborts rather than committing a half-update.

### Phase 6 — Verify

Run `scripts/verify_sync.sh`. It checks:

- `includes/integrations/` contains only `class-base.php`.
- `build/` contains only `secure-block*` files.
- `wp-fusion.php` does not exist.
- `class WP_Fusion_Lite` is defined in `wp-fusion-lite.php`; bare `class WP_Fusion` (not followed by `_Lite`) is not.
- `'wp-fusion'` literal does not appear in any `.php` file outside `vendor/` and `node_modules/`.
- Versions match across the three locations.
- `php -l` passes on every `.php` file outside `vendor/` and `node_modules/`.

Any failure → stop and surface it. The deploy pipeline won't catch these; the skill is the last line of defense before the SVN commit.

### Phase 7 — Manual CRM smoke-test gate

**Hard stop for human.** Tell the user:

> Verification passed. Before I tag and push, please:
>
> 1. Deactivate WP Fusion (Pro), activate WP Fusion Lite.
> 2. Reset the settings.
> 3. Connect to 2–3 CRMs (recommend: HubSpot, ActiveCampaign, and one OAuth-based like Drip or ConvertKit).
> 4. Check `wp-content/debug.log` for any fatal errors.
>
> Let me know when done and I'll tag + push.

Do not proceed until the user explicitly confirms.

### Phase 8 — Commit, tag, push

After explicit confirmation:

1. Stage everything and commit with the version as the message (matches existing convention — commit messages in Lite history are just `3.46.3.1`, `3.46.3`, etc.):
   ```bash
   git add -A
   git commit -m "<version>"
   ```
2. Create an annotated tag:
   ```bash
   git tag -a <version> -m "<version>"
   ```
3. **Confirm with user one more time before pushing.** This is the irreversible step — the tag push triggers `deploy.yml`, which does an SVN commit to wordpress.org. There is no clean rollback on SVN. Phrase the confirmation as "Push `<version>` to origin? This will deploy to wordpress.org."
4. After confirmation:
   ```bash
   git push origin master
   git push origin <version>
   ```

### Phase 9 — Post-deploy verification

1. Watch the deploy workflow:
   ```bash
   gh run watch --repo verygoodplugins/wp-fusion-lite
   ```
2. After ~5 min, check wordpress.org:
   ```bash
   curl -s "https://wordpress.org/plugins/wp-fusion-lite/" | grep -oE 'Version [0-9.]+' | head -1
   ```
3. If a Local WP site is configured (see `config.json` or prompt user), run the plugin update there:
   ```bash
   wp plugin update wp-fusion-lite --path=/path/to/local/site
   ```
   and grep the Local site's debug log for PHP errors.
4. Report back to the user with three links: the GitHub release, the Actions run, and the wordpress.org plugin page.

## Configuration

On first run, the skill will prompt for the Local WP site path and persist it at `.claude/skills/release-wp-fusion-lite/config.json`. This file is gitignored. Schema:

```json
{
  "local_wp_site_path": "/Users/<user>/Local Sites/<site-name>/app/public",
  "wp_cli_alias": null
}
```

If the user doesn't want staging verification, set `"local_wp_site_path": null`. The skill will skip Phase 9's `wp plugin update` step.

## Scripts overview

All scripts live in `scripts/` and support `--dry-run` where it makes sense (sync, textdomain replace, version bump, filter). Run any script with `--help` for flags. Environment variables:

- `PRO_PATH` — path to the Pro repo (default: sibling `wp-fusion/`).
- `LITE_PATH` — path to the Lite repo (default: this repo).

| Script | Purpose | Idempotent |
|---|---|---|
| `sync_from_pro.sh` | rsync Pro → Lite + prune | Yes (re-run after fixing issues) |
| `patch_main_file.py` | Header/class/function edits on `wp-fusion-lite.php` | Yes (checks if already patched) |
| `replace_textdomain.py` | `'wp-fusion'` → `'wp-fusion-lite'` in all PHP | Yes |
| `filter_changelog.py` | Pro changelog → Lite-safe draft | Yes (outputs to temp file) |
| `bump_version.py` | Sync version to 3 places | Yes |
| `verify_sync.sh` | Post-sync sanity checks | Yes |

## References

- `references/integration-keywords.md` — paid-integration keywords used by the changelog filter. Update when Pro ships a new integration.
- `references/textdomain-context.md` — why the literal-quoted replace is the correct scope (not a bare `wp-fusion` replace).
- `references/deploy-pipeline.md` — how `deploy.yml` / the 10up action / SVN / wordpress.org fit together.

## Safety invariants

- Never run `git push` before Phase 8.
- Never run `gh pr merge` on Dependabot PRs without explicit user instruction.
- Never overwrite `readme.txt` changelog text without user review — always go through the temp-file draft workflow.
- `verify_sync.sh` must pass before Phase 7 (smoke-test). If it fails, stop.
- Scripts must not touch `vendor/`, `node_modules/`, `.git/`, or anything in `.github/` (deploy workflow is preserved as-is).
