#!/usr/bin/env bash
#
# sync_from_pro.sh — copy the current state of WP Fusion (Pro) into WP Fusion
# Lite, then prune everything the Lite distribution doesn't ship.
#
# The four top-level items that come across wholesale:
#   - wp-fusion.php
#   - assets/
#   - includes/
#   - build/
#
# Everything outside those four paths in the Lite repo is preserved (.git,
# .github, .claude, readme.txt, README.md, .distignore, package.json, etc.).
#
# Flags:
#   --dry-run    Print what would happen without touching disk.
#
# Env:
#   PRO_PATH     Path to the Pro repo. Default: ../wp-fusion relative to
#                LITE_PATH.
#   LITE_PATH    Path to the Lite repo. Default: the git root containing this
#                script.

set -euo pipefail

DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help)
      sed -n '3,22p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "unknown flag: $arg" >&2
      exit 2
      ;;
  esac
done

# Resolve the Lite repo root: walk up from this script until we find a .git dir.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${LITE_PATH:-}" ]]; then
  candidate="$script_dir"
  while [[ "$candidate" != "/" && ! -d "$candidate/.git" ]]; do
    candidate="$(dirname "$candidate")"
  done
  if [[ ! -d "$candidate/.git" ]]; then
    echo "could not locate Lite repo root (no .git ancestor of $script_dir)" >&2
    exit 1
  fi
  LITE_PATH="$candidate"
fi
LITE_PATH="$(cd "$LITE_PATH" && pwd)"

if [[ -z "${PRO_PATH:-}" ]]; then
  PRO_PATH="$(cd "$LITE_PATH/.." && pwd)/wp-fusion"
fi

if [[ ! -d "$PRO_PATH" ]]; then
  echo "Pro path does not exist: $PRO_PATH" >&2
  echo "set PRO_PATH to the path of the wp-fusion (Pro) repo." >&2
  exit 1
fi
if [[ ! -f "$PRO_PATH/wp-fusion.php" ]]; then
  echo "$PRO_PATH does not look like the Pro repo (no wp-fusion.php)." >&2
  exit 1
fi

echo "Pro:  $PRO_PATH"
echo "Lite: $LITE_PATH"
if (( DRY_RUN )); then
  echo "(dry run — no changes will be written)"
fi
echo

run() {
  if (( DRY_RUN )); then
    printf '  [dry] %s\n' "$*"
  else
    printf '  %s\n' "$*"
    "$@"
  fi
}

# rsync needs trailing slashes to copy contents vs the dir itself.
# -a archive, -v verbose, --delete removes stale files from the Lite copy of
#     each synced tree. We intentionally scope --delete per-sync so it never
#     reaches outside the four items below.
rsync_item() {
  local src="$1" dst="$2" kind="$3"
  if [[ "$kind" == "file" ]]; then
    if (( DRY_RUN )); then
      echo "  [dry] rsync $src -> $dst"
    else
      rsync -a "$src" "$dst"
    fi
  else
    if (( DRY_RUN )); then
      echo "  [dry] rsync -a --delete $src/ -> $dst/"
    else
      rsync -a --delete "$src/" "$dst/"
    fi
  fi
}

echo "==> 1. rsync wp-fusion.php, assets/, includes/, build/ from Pro"
rsync_item "$PRO_PATH/wp-fusion.php" "$LITE_PATH/wp-fusion.php" file
rsync_item "$PRO_PATH/assets"        "$LITE_PATH/assets"        dir
rsync_item "$PRO_PATH/includes"      "$LITE_PATH/includes"      dir
rsync_item "$PRO_PATH/build"         "$LITE_PATH/build"         dir

echo
echo "==> 2. prune includes/integrations/ (keep only class-base.php)"
integrations="$LITE_PATH/includes/integrations"
if [[ -d "$integrations" ]]; then
  # Delete every entry except class-base.php (file).
  # Use find -mindepth 1 so we don't try to delete the directory itself.
  if (( DRY_RUN )); then
    find "$integrations" -mindepth 1 -not -name 'class-base.php' -not -path "$integrations" | head -5 | sed 's/^/  [dry] rm -rf /'
    count=$(find "$integrations" -mindepth 1 -not -name 'class-base.php' | wc -l | tr -d ' ')
    echo "  [dry] (... $count items total)"
  else
    # Collect then delete — avoids find -delete traversal races.
    while IFS= read -r path; do
      rm -rf -- "$path"
    done < <(find "$integrations" -mindepth 1 -maxdepth 1 -not -name 'class-base.php')
    echo "  pruned."
  fi
else
  echo "  (no includes/integrations/ to prune — skipped)"
fi

echo
echo "==> 3. delete includes/class-api.php and includes/admin/class-updater.php"
for victim in "includes/class-api.php" "includes/admin/class-updater.php"; do
  target="$LITE_PATH/$victim"
  if [[ -e "$target" ]]; then
    run rm -f -- "$target"
  else
    echo "  (not present: $victim)"
  fi
done

echo
echo "==> 4. delete languages/ if present"
if [[ -d "$LITE_PATH/languages" ]]; then
  run rm -rf -- "$LITE_PATH/languages"
else
  echo "  (no languages/ — skipped)"
fi

echo
echo "==> 5. prune build/ (keep only secure-block* files)"
build_dir="$LITE_PATH/build"
if [[ -d "$build_dir" ]]; then
  if (( DRY_RUN )); then
    find "$build_dir" -mindepth 1 -maxdepth 1 -not -name 'secure-block*' | sed 's/^/  [dry] rm -rf /'
  else
    while IFS= read -r path; do
      rm -rf -- "$path"
    done < <(find "$build_dir" -mindepth 1 -maxdepth 1 -not -name 'secure-block*')
    echo "  pruned."
  fi
else
  echo "  (no build/ — skipped)"
fi

echo
echo "==> 6. rename wp-fusion.php -> wp-fusion-lite.php"
if [[ -f "$LITE_PATH/wp-fusion.php" ]]; then
  if [[ -f "$LITE_PATH/wp-fusion-lite.php" ]]; then
    # After a fresh rsync the old Lite main file gets overwritten by Pro's
    # wp-fusion.php (different filename, preserved), and the previous
    # wp-fusion-lite.php from Lite stays. Prefer the fresh Pro-sourced file.
    run rm -f -- "$LITE_PATH/wp-fusion-lite.php"
  fi
  run mv -- "$LITE_PATH/wp-fusion.php" "$LITE_PATH/wp-fusion-lite.php"
else
  echo "  (no wp-fusion.php to rename — step 1 may have failed)"
fi

echo
echo "sync complete."
