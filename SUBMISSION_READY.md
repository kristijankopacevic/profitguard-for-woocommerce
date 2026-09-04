# WordPress.org submission — staged, blocked on one owner action

**Updated:** 2026-09-04, after v1.0.0 shipped on GitHub.
**Supersedes** the 2026-08-31 version of this file, which said the plugin was
"built and verified" when nothing had ever been verified by CI.

Everything needed to submit is prepared. Exactly one thing is missing, and only
the account owner can do it.

---

## The blocker

The WordPress.org account **`truepotato`** was registered but never activated.
The registration confirmation email was never opened, so the account does not
exist as far as the directory is concerned:

- `https://profiles.wordpress.org/truepotato/` → **404**
- `wordpress.org/plugins/developers/add/` → *"Before you can upload a new plugin, please log in."*

**What to do (5 minutes, free, owner only):**

1. Open the inbox for the address used at signup. **Check spam** — this is where
   it usually is.
2. Find the WordPress.org confirmation email and click the activation link.
3. Set a password.
4. Confirm `https://profiles.wordpress.org/truepotato/` now loads.

Then follow `WORDPRESS_SUBMISSION.md`, which has the full step-by-step.

Nothing else is outstanding. If a different account name is preferred, change
`Contributors:` on line 2 of `readme.txt` before uploading — that is the only
place it appears.

## What is staged

### The archive

Built by `bin/build-zip.sh` and published as a GitHub Release asset. This is the
file to upload — do not rebuild it by hand.

| | |
|---|---|
| File | `profitguard-for-woocommerce.zip` |
| Size | 120,010 bytes |
| SHA-256 | `237750458773c4164b2e66d87b0565343718c2a42d515fe3ff491bef77b15609` |
| Source | https://github.com/kristijankopacevic/profitguard-for-woocommerce/releases/tag/v1.0.0 |
| Contents | 29 shipped PHP files, `readme.txt`, `LICENSE`, `assets/css/admin.css`, `languages/README.md` — and nothing else. Asserted in CI. |

The archive's single top-level directory is the plugin slug, and no `tests/`,
`vendor/`, `node_modules/`, `bin/`, `samples/`, `.git` or `.github` reaches it.

### Listing fields, as the form asks for them

| Field | Value |
|---|---|
| Plugin name | ProfitGuard for WooCommerce |
| Slug (requested) | `profitguard-for-woocommerce` |
| Contributors | `truepotato` |
| Tags | woocommerce, profit, margin, cost of goods, shipping |
| Requires at least | 6.4 |
| Tested up to | 7.1 |
| Requires PHP | 7.4 |
| Stable tag | 1.0.0 |
| Licence | GPLv2 or later |
| Short description | *Turn WooCommerce cost and carrier data into actionable margin and shipping-profit insights. All analysis runs locally in your own store.* (136 chars, limit 150) |

The slug `wordpress.org/plugins/profitguard-for-woocommerce/` resolved to a
search page when last checked, i.e. unclaimed and unpublished.

### Graphics — `assets-wporg/`

All present, and `bin/build-release.sh` refuses to build the SVN tree if any is
missing:

- `icon-128x128.png`, `icon-256x256.png`
- `banner-772x250.png`, `banner-1544x500.png`
- `screenshot-1.png` … `screenshot-6.png`

**The six screenshots were regenerated on 2026-09-04** from the browser test's
own artifacts. The previous set was committed before any CI existed and had
drifted: `screenshot-4.png` showed the import preview *without* the current → new
comparison table that its caption describes. Do not re-use the old images.

### The SVN tree

`bin/build-release.sh` unpacks `trunk/` and `tags/1.0.0/` from the tested ZIP
rather than copying the working tree, so what reaches SVN is byte-for-byte what
CI verified. It also refuses to run if the plugin header `Version` disagrees with
`readme.txt`'s `Stable tag` — the single most common broken release. Both checks
run on every CI build and pass.

## Why the review should be uneventful

Every item a reviewer checks is verified on each release by GitHub Actions run
`33917153186` (green on the `v1.0.0` tag):

- **Plugin Check: zero errors.** Two real errors were found and fixed getting
  here — `missing_direct_file_access_protection` on two files that both carried
  the canonical guard, because the check only scans so far into a file for it and
  a long docblock had pushed it out of range.
- **PHPCS, full `WordPress` standard: zero errors, zero warnings** across 51 files.
- **PHPUnit on PHP 7.4, 8.1, 8.2, 8.3 and 8.4:** 200 tests, 281,558 assertions.
- **No outbound requests.** Proven by walking the PHP tokens of the shipped
  archive, not by grepping it — a comment mentioning `wp_remote_get` must not be
  able to fail the check, and a real call must not be able to slip past one.
- **Installed from this exact ZIP** into fresh WordPress 7.1 + WooCommerce
  11.1.0, then deactivated and reactivated.
- **HPOS compatibility** read back out of `FeaturesUtil` at runtime.
- **Sanitisation, escaping and nonces:** every admin action passes through one
  choke point that checks `manage_woocommerce` and then `check_admin_referer`
  before dispatching; all four page renderers call the same capability guard.
- **Uninstall is opt-in** and removes nothing by default.

## Known gaps a reviewer might raise

Recorded here so the answer is ready rather than improvised:

1. **`$wpdb->prepare` warnings.** Plugin Check reports `InterpolatedNotPrepared`
   and `UnfinishedPrepare` warnings in `Plugin/Repository.php`. All are table
   names — which cannot be bound as placeholders — built from `$wpdb->prefix`
   plus a literal, never from input. Every *value* in every query is bound. The
   reasoning is written at the top of that file and in `phpcs.xml.dist`.
2. **Third-party cost-plugin compatibility is untested.** `readme.txt` says the
   plugin *reads costs stored by several common cost-of-goods plugins*. The meta
   keys are read and the path is exercised, but no test installs those plugins,
   so the claim is about reading keys — not certified compatibility with any
   named product. Worth softening further if a reviewer objects.
3. **`languages/` ships only a README.** No `.pot` is generated yet; the text
   domain is correct and consistent throughout, so translation is possible but no
   catalogue is provided.
