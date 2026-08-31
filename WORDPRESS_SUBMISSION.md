# Submitting ProfitGuard to the WordPress.org plugin directory

Every step here is free. There is no paid service anywhere in this process.

Expect **two to fourteen days** for the initial review, sometimes longer. The
reviewers are volunteers and a first submission usually comes back with at least
one comment; that is normal and is not a rejection.

---

## Before you submit

Work through this list first. Each of these is something reviewers check, and
fixing one after a rejection costs you another full review cycle.

- [ ] **Build the ZIP**: `bash bin/build-zip.sh` → `dist/profitguard-for-woocommerce.zip`
- [ ] **Test that exact ZIP on a clean WordPress.** Not your dev copy — the ZIP.
      `docker compose -p pgzip -f docker-compose.ziptest.yml up -d` then install
      it through the admin.
- [ ] **Run Plugin Check** (see below). Zero ERRORs.
- [ ] Replace the placeholder author URI in the main plugin file with something
      real — a GitHub profile is fine, a domain is not required.
- [ ] Decide your support email or forum and be prepared to answer on it.
- [ ] Confirm `Tested up to:` in `readme.txt` matches a WordPress version you
      have actually run it on.

### Running Plugin Check

Plugin Check is the official tool the review team uses. Run it yourself first.

```bash
docker compose -p pgzip -f docker-compose.ziptest.yml up -d
docker compose -p pgzip -f docker-compose.ziptest.yml run --rm wpcli \
  "wp core install --url=http://localhost:8081 --title=Test --admin_user=admin \
     --admin_password=admin --admin_email=a@b.test --skip-email \
   && wp plugin install woocommerce --activate \
   && wp plugin install /dist/profitguard-for-woocommerce.zip --activate \
   && wp plugin install plugin-check --activate \
   && wp plugin check profitguard-for-woocommerce"
```

**Current state:** 0 errors, on WordPress 7.1 + WooCommerce 11.0.1 with HPOS,
with the plugin installed only from the built ZIP.

If you just want the short version of what is left to do by hand, read
[`SUBMISSION_READY.md`](SUBMISSION_READY.md). If a reviewer writes to you,
[`REVIEW_RESPONSES.md`](REVIEW_RESPONSES.md) has prepared, truthful answers.
 There are ~20 warnings, all
`PluginCheck.Security.DirectDB.UnescapedDBParameter` on `includes/Plugin/Repository.php`,
`includes/Plugin/Database.php` and `uninstall.php`. Those are unavoidable for a
plugin with its own tables: `$wpdb->prepare()` binds values but cannot bind a
table NAME, so the table name is interpolated. Every one of those table names is
`$wpdb->prefix` plus a hard-coded literal, and every VALUE is bound. Warnings do
not block approval; if a reviewer asks, that paragraph is your answer.

---

## The steps

### 1. Create a WordPress.org account — 2 minutes, free

<https://login.wordpress.org/register>

Use a username you are content to have public forever: it becomes your plugin's
committer name and appears on the plugin page.

### 2. Submit the plugin — 10 minutes

<https://wordpress.org/plugins/developers/add/>

Upload `dist/profitguard-for-woocommerce.zip`.

The form reads the plugin header and `readme.txt` from the ZIP, so there is
nothing else to fill in. Double-check the slug it proposes is
`profitguard-for-woocommerce` — the slug is **permanent** and cannot be changed
later, and it is what the plugin's URL and directory name become forever.

### 3. Wait for the automated scan — minutes

An automated check runs immediately. If it finds a blocking problem you get an
email within minutes and the submission does not reach a human. This is the same
check Plugin Check runs locally, which is why you ran it first.

### 4. Wait for the human review — days to weeks

A volunteer reviewer reads the code. For a plugin like this they typically look
at: escaping and sanitisation, nonces and capability checks, the uninstall
routine, whether the readme claims anything the code does not do, and whether
anything phones home.

**You will probably get at least one comment.** Reply to the email thread
directly — do not resubmit the plugin, and do not open a second submission.
Fix the issue, reply explaining what you changed, and attach the new ZIP if they
ask for one.

Likely questions for this plugin, and the honest answers:

| Question | Answer |
| --- | --- |
| Why direct database queries? | Findings, imported carrier rows and scan history are high-volume analytics rows. Putting them in `wp_options` would bloat the autoloaded option cache on every page load of the entire site. |
| Why interpolate the table name? | `$wpdb->prepare()` binds values, not identifiers. The names come from `$wpdb->prefix` plus a literal and never from input. |
| Does it call any external service? | No. There are no `wp_remote_*` calls anywhere in the plugin. |
| Is there telemetry? | None of any kind. |
| Why does it read other plugins' meta keys? | To reuse a cost a merchant has already entered. Read-only; ProfitGuard never writes to another plugin's meta. |

### 5. Approval and SVN — 1 day after approval

On approval you get an email with your SVN repository URL:

```
https://plugins.svn.wordpress.org/profitguard-for-woocommerce/
```

This is Subversion, not Git. Install a client (`svn`, free) and check it out:

```bash
svn co https://plugins.svn.wordpress.org/profitguard-for-woocommerce/ pg-svn
cd pg-svn
```

You will see `trunk/`, `tags/`, `branches/` and `assets/`.

### 6. Publish the first version — 20 minutes

```bash
# Unpack the built ZIP into trunk.
rm -rf trunk/*
unzip -j -d /tmp/pg dist/profitguard-for-woocommerce.zip   # or extract manually
cp -r /tmp/pg/profitguard-for-woocommerce/* trunk/

svn add trunk/* --force
svn ci -m "Initial release 1.0.0" --username YOUR_USERNAME

# Tag it. The directory removed from `tags/` is what the directory SERVES.
svn cp trunk tags/1.0.0
svn ci -m "Tag 1.0.0" --username YOUR_USERNAME
```

**`Stable tag: 1.0.0` in `readme.txt` is what decides what merchants download.**
Not trunk. If the tag does not exist, nobody can install the plugin — this is the
single most common first-release mistake.

### 7. Upload the graphics — 15 minutes

These go in the SVN `assets/` directory (a sibling of `trunk/`, **not** inside
it), and they are served from there rather than from the plugin.

```bash
cp icon-256x256.png       assets/
cp banner-772x250.png     assets/
cp screenshot-1.png       assets/
# ... screenshot-2 through screenshot-6
svn add assets/*
svn ci -m "Add plugin assets" --username YOUR_USERNAME
```

Sizes and naming are in [`ASSETS.md`](ASSETS.md). Screenshot numbers must match
the order of the `== Screenshots ==` captions in `readme.txt`.

Assets appear on the plugin page within about 15 minutes.

### 8. Afterwards

- Statistics appear at
  `https://wordpress.org/plugins/profitguard-for-woocommerce/advanced/`
  within a day or two: downloads, active installs, and the version breakdown.
- The support forum is created automatically. **Subscribe to it.** An unanswered
  support thread is the most visible negative signal a plugin page can carry.
- To release an update: bump the version in **both** the plugin header and
  `Stable tag:`, commit to `trunk`, then `svn cp trunk tags/1.0.1`.

---

## What this costs

Nothing. WordPress.org hosting, the SVN repository, the support forum, the
statistics and the translation platform are all free and have no paid tier.

The only things you might eventually pay for are a domain for a plugin homepage
and a support email address, and neither is required — a GitHub repository URL
works for both.
