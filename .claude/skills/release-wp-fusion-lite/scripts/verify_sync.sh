#!/usr/bin/env bash
#
# verify_sync.sh — run sanity checks after sync + patch + textdomain + bump.
#
# Any failure is reported with a short reason. Exit code is the number of
# failed checks (0 means all passed).
#
# Env:
#   LITE_PATH   Lite repo root (default: git root containing this script)
#   PHP_LINT    Set to 0 to skip `php -l` (faster if you already linted)

set -uo pipefail

# Resolve Lite path the same way sync_from_pro.sh does.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${LITE_PATH:-}" ]]; then
  candidate="$script_dir"
  while [[ "$candidate" != "/" && ! -d "$candidate/.git" ]]; do
    candidate="$(dirname "$candidate")"
  done
  LITE_PATH="$candidate"
fi
cd "$LITE_PATH" || exit 1

fail=0
pass_count=0

check() {
  local label="$1"; shift
  if "$@"; then
    printf "  \033[32m✓\033[0m %s\n" "$label"
    pass_count=$((pass_count + 1))
  else
    printf "  \033[31m✗\033[0m %s\n" "$label"
    fail=$((fail + 1))
  fi
}

echo "verifying Lite state at $LITE_PATH"
echo

# 1. File layout.
check "wp-fusion-lite.php exists" test -f wp-fusion-lite.php
check "wp-fusion.php does NOT exist" test ! -f wp-fusion.php

# 2. includes/integrations/ contains only class-base.php
# Ignore macOS .DS_Store metadata files — they're created by Finder and excluded at dist time.
check "includes/integrations/ contains only class-base.php" bash -c '
  unexpected=$(find includes/integrations -mindepth 1 -not -name class-base.php -not -name .DS_Store 2>/dev/null)
  [[ -z "$unexpected" ]]
'

# 3. build/ contains only secure-block*
check "build/ contains only secure-block* files" bash -c '
  unexpected=$(find build -mindepth 1 -maxdepth 1 -not -name "secure-block*" -not -name .DS_Store 2>/dev/null)
  [[ -z "$unexpected" ]]
'

# 4. languages/ absent
check "languages/ is absent" test ! -d languages

# 5. deleted files
check "includes/class-api.php deleted" test ! -f includes/class-api.php
check "includes/admin/class-updater.php deleted" test ! -f includes/admin/class-updater.php

# 6. class rename
check "class WP_Fusion_Lite is defined" \
  grep -qE '^final class WP_Fusion_Lite\b' wp-fusion-lite.php
check "no bare 'class WP_Fusion' remains" \
  bash -c '! grep -nE "^final class WP_Fusion[^_]" wp-fusion-lite.php'

# 7. removed functions absent
check "updater() removed" \
  bash -c '! grep -qE "^[[:space:]]*(public|private|protected)?[[:space:]]*function[[:space:]]+updater[[:space:]]*\(" wp-fusion-lite.php'
check "wpf_update_message() removed" \
  bash -c '! grep -qE "function[[:space:]]+wpf_update_message[[:space:]]*\(" wp-fusion-lite.php'
check "load_textdomain() removed" \
  bash -c '! grep -qE "^[[:space:]]*(public|private|protected)?[[:space:]]*function[[:space:]]+load_textdomain[[:space:]]*\(" wp-fusion-lite.php'

# 8. textdomain swapped
check "no quoted 'wp-fusion' (single-quoted) in .php files" bash -c "
  hits=\$(grep -rlE \"'wp-fusion'\" --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git . 2>/dev/null)
  [[ -z \"\$hits\" ]]
"
check 'no quoted "wp-fusion" (double-quoted) in .php files' bash -c '
  hits=$(grep -rlE "\"wp-fusion\"" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git . 2>/dev/null)
  [[ -z "$hits" ]]
'

# 9. text domain in main file header
check "Text Domain: wp-fusion-lite in header" \
  grep -qE '^\s*\*\s*Text Domain:\s*wp-fusion-lite\s*$' wp-fusion-lite.php

# 10. plugin name in main file header
check "Plugin Name: WP Fusion Lite in header" \
  grep -qE '^\s*\*\s*Plugin Name:\s*WP Fusion Lite\s*$' wp-fusion-lite.php

# 11. version consistency across 3 locations
check "version matches across header, constant, readme Stable tag" bash -c '
  header=$(grep -oE "^\s*\*\s*Version:\s*[0-9.]+" wp-fusion-lite.php | awk "{print \$NF}")
  constant=$(grep -oE "WP_FUSION_VERSION'\''[^'\'']*'\''[0-9.]+" wp-fusion-lite.php | grep -oE "[0-9.]+$")
  stable=$(grep -oE "^Stable tag:\s*[0-9.]+" readme.txt | awk "{print \$NF}")
  if [[ -z "$header" || -z "$constant" || -z "$stable" ]]; then
    echo "  (one of header=$header constant=$constant stable=$stable was empty)" >&2
    exit 1
  fi
  if [[ "$header" == "$constant" && "$constant" == "$stable" ]]; then
    exit 0
  else
    echo "  header=$header constant=$constant stable=$stable" >&2
    exit 1
  fi
'

# 12. php -l on all .php files
if [[ "${PHP_LINT:-1}" == "1" ]] && command -v php >/dev/null 2>&1; then
  check "php -l passes on all .php files" bash -c '
    errors=0
    while IFS= read -r -d "" f; do
      if ! php -l "$f" >/dev/null 2>&1; then
        echo "    lint failed: $f" >&2
        errors=$((errors + 1))
      fi
    done < <(find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -not -path "./.git/*" -print0)
    [[ $errors -eq 0 ]]
  '
else
  echo "  (skipping php -l — PHP_LINT=0 or php not on PATH)"
fi

echo
echo "$pass_count passed, $fail failed"
exit "$fail"
