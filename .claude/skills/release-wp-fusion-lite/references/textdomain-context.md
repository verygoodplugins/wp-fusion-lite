# Why the literal-quoted textdomain replace is the right scope

The release checklist says:

> Find and replace `'wp-fusion'` to `'wp-fusion-lite'` across all files,
> search including the single quotes (to change the textdomain)

That instruction is deliberate. A bare search-and-replace of `wp-fusion`
would hit too much:

- The directory name inside filesystem paths (e.g. `wp-content/plugins/wp-fusion/...`).
- Slug references in documentation.
- The constant name fragment `WP_FUSION_VERSION` (technically no, because
  of casing, but near-misses exist).
- Support URLs and some admin labels that reference "wp-fusion" as a
  product identifier.

The quoted form (`'wp-fusion'` or `"wp-fusion"`) is a reasonable proxy for
"this is a PHP string argument, probably a textdomain". In the WP Fusion
codebase, strings of exactly those forms are:

- `__( 'Some label', 'wp-fusion' )` and family — translation calls.
- Comparisons like `if ( 'wp-fusion' === $slug )` — these tend not to
  exist in the main code paths, and when they do, the Lite slug is the
  correct value anyway.
- Array keys/values used as textdomain or i18n identifiers in filter
  arguments.
- Registration hooks: `register_deactivation_hook( 'wp-fusion', ... )` —
  if any — these take the plugin filename basename, NOT the textdomain,
  so in Lite they should be `'wp-fusion-lite'` which is exactly what we
  want.

`replace_textdomain.py` performs this exact literal swap in `.php` files
repo-wide, skipping `vendor/`, `node_modules/`, and `build/` (the built
Secure Block assets are emitted artifacts; the PHP side of those is
edited normally).

## Why not a parser-aware replacement?

A "find all `__()` and `_e()` calls, change their textdomain argument"
implementation would be more precise but:

1. It misses edge cases: `load_plugin_textdomain('wp-fusion', ...)`,
   `_x('foo', 'context', 'wp-fusion')` with four args, custom wrappers
   like `wpf_( 'label' )` that dispatch to a helper.
2. The manual process has worked fine for years of releases without a
   parser. The blast radius of the literal replace is known.
3. Parsing PHP correctly is a rabbit hole. The `nikic/php-parser`
   approach would pull in Composer, add a build dependency, and move
   the script further from the original step it automates.

If a textdomain-safe string ever gets accidentally swapped (say, a URL
slug that happens to be `'wp-fusion'`), the `verify_sync.sh` PHP lint
and the manual CRM smoke-test should catch the regression before the
tag push.

## Verification

After running `replace_textdomain.py`, `verify_sync.sh` greps for any
remaining `'wp-fusion'` or `"wp-fusion"` in `.php` files. If any remain,
something went wrong.
