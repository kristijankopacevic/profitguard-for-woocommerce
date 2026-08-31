# ProfitGuard for WooCommerce v1.0.0 — ready to submit

Everything that could be done without your WordPress.org account is done. The
code, the graphics, the screenshots, the readme, the ZIP and the SVN tree are all
built and verified.

---

## What you have to do

**1. Finish activating your WordPress.org account.**

You registered as **truepotato**. The account is not live yet —
`profiles.wordpress.org/truepotato` still returns 404, which is what you see
until the registration is confirmed. Check the inbox for the address you signed
up with (including spam) and click the link WordPress.org sent; it asks you to
set a password. Once that is done the profile page resolves.

`readme.txt` already names `truepotato` as the contributor, so nothing else
needs changing.

**2. Open the submission page and upload the ZIP.**
<https://wordpress.org/plugins/developers/add/>

```
profitguard-for-woocommerce/dist/profitguard-for-woocommerce.zip
```

**3. Click Submit Plugin.**

That is the whole list. The form reads the name, version, description and
requirements from the ZIP, so there is nothing else to fill in.

---

## Two things that are permanent, so glance at them once

- **The slug.** The form will propose `profitguard-for-woocommerce`. That becomes
  the plugin's URL forever and cannot be changed later.
- **`Stable tag: 1.0.0`.** After approval, this is what decides which version
  merchants actually download — not `trunk`. `bin/build-release.sh` refuses to
  build if the plugin header and the stable tag ever disagree.

---

## After you submit

An automated scan runs within minutes. It is the same Plugin Check that already
passes here with zero errors, so it should come back clean.

Then a volunteer reviews the code by hand. This takes anywhere from two days to
a few weeks, and **a first submission usually comes back with at least one
comment. That is normal and is not a rejection.**

If they write to you, paste their message back into the coding agent — see
"AFTER SUBMISSION" at the end of the session, or `REVIEW_RESPONSES.md`, which
already has truthful prepared answers for the questions this plugin is most
likely to attract. Do not send those proactively; reviewers read code, not
documentation.

---

## Once approved

You will get an SVN URL. Then:

```bash
svn co https://plugins.svn.wordpress.org/profitguard-for-woocommerce/ pg-svn
cd pg-svn

cp -r /path/to/profitguard-for-woocommerce/release/trunk/.     trunk/
cp -r /path/to/profitguard-for-woocommerce/release/tags/1.0.0/ tags/1.0.0/
cp    /path/to/profitguard-for-woocommerce/release/assets/*    assets/

svn add trunk/* tags/1.0.0 assets/* --force
svn ci -m "ProfitGuard for WooCommerce 1.0.0" --username YOUR_USERNAME
```

`release/` is already laid out in exactly that shape, so this is a copy, not a
build. Note that `assets/` is a **sibling** of `trunk/`, not inside it — the
graphics are served from the directory, not shipped in the plugin.

The graphics appear on the plugin page about fifteen minutes after the commit.

---

## Subscribe to the support forum

It is created automatically with the plugin page. An unanswered support thread is
the most visible negative signal a listing carries, and it is entirely within
your control.
