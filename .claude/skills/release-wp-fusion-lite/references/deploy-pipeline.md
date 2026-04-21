# Deploy pipeline

How `git push origin <tag>` becomes a live release on wordpress.org.

## The workflow

`.github/workflows/deploy.yml`:

```yaml
on:
  push:
    tags:
      - "*"
```

Any tag push triggers the `tag` job, which:

1. Checks out the repo at the tag.
2. Runs `10up/action-wordpress-plugin-deploy@stable` with:
   - `generate-zip: true` — produce an installable zip.
   - Uses `SVN_USERNAME` and `SVN_PASSWORD` secrets for the wordpress.org
     SVN commit.
3. Creates a GitHub Release attaching the generated zip.

## The 10up action

Under the hood, [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)
does this:

1. SVN-checks out the wordpress.org plugin directory:
   `https://plugins.svn.wordpress.org/wp-fusion-lite/`.
2. Rsyncs the Git checkout into `trunk/` of the SVN working copy, respecting
   `.distignore` (so things like `.github/`, `.git/`, `node_modules/` are
   excluded from what gets shipped).
3. Copies `trunk/` into `tags/<version>/`.
4. If `assets/banner*.png` etc. are present in `.wordpress-org/`, they go
   into SVN's `assets/`.
5. `svn ci` with the configured credentials.

Within ~5 minutes, wordpress.org's systems pick up the new `trunk/` and
`tags/<version>/`, and the plugin directory shows the new version.

## Secrets required

Configured in the GitHub repo settings (Actions → Secrets):

- `SVN_USERNAME` — the wordpress.org account with commit rights on this plugin.
- `SVN_PASSWORD` — the application-specific password (not the account login password).
- `GITHUB_TOKEN` — provided automatically by the runner.

If `SVN_PASSWORD` ever needs rotation: log into wordpress.org, generate
a new application password, update the secret. The 10up action reads
whatever is set.

## .distignore

Lite's `.distignore` excludes: `.wordpress-org`, `.git`, `.github`,
`/node_modules`, `.distignore`, `.gitignore`, `.stylelintrc.js`,
`.stylelintignore`, `.gitattributes`, `junit.xml`.

Notable omissions (things NOT excluded, i.e. they ship in the SVN commit):
`.claude/` — including this skill — is currently shipped. Either:

1. Accept that the skill ships to wordpress.org (harmless but bloats the
   download), or
2. Add `.claude` to `.distignore`.

Option 2 is preferable — end users don't need the skill, and the skill
exists for release tooling, not runtime. Consider adding:

```
/.claude
```

to `.distignore` as part of this skill's first merge.

## Rollback

There is no clean rollback once the SVN commit lands:

- You can tag a new patch version that reverts the change.
- You cannot un-publish a specific version from wordpress.org without
  emailing plugins@wordpress.org.

This is why Phase 7 (CRM smoke-test) is a hard human gate, and why Phase 8
(`git push`) asks for explicit confirmation before the tag push.

## Verification

After the workflow finishes successfully:

- GitHub release exists at `https://github.com/verygoodplugins/wp-fusion-lite/releases/tag/<version>`.
- wordpress.org plugin page shows the new version: `https://wordpress.org/plugins/wp-fusion-lite/`.
  Can be polled with:
  ```bash
  curl -s https://wordpress.org/plugins/wp-fusion-lite/ | grep -oE 'Version [0-9.]+' | head -1
  ```
- `wp plugin update wp-fusion-lite` on any site with Lite installed should
  pick up the new version within ~24h (wordpress.org cache), often faster.
