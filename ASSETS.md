# Plugin directory graphics

These files are **not** part of the plugin and are not in the ZIP. They live in
the SVN `assets/` directory, a sibling of `trunk/`, and WordPress.org serves them
from there.

**All ten are generated and present** in `assets-wporg/`, and `bin/build-release.sh`
copies them into `release/assets/` under the exact names below.

| File | Size | Where it appears | Status |
| --- | --- | --- | --- |
| `icon-256x256.png` | 256 × 256 | Search results, plugin cards | Generated |
| `icon-128x128.png` | 128 × 128 | Same, non-retina | Generated |
| `banner-772x250.png` | 772 × 250 | Top of the plugin page | Generated |
| `banner-1544x500.png` | 1544 × 500 | Same, retina | Generated |
| `screenshot-1.png` … `screenshot-6.png` | 1440 × 900 | Screenshots tab | Captured |

Rules that are actually enforced:

- PNG or JPG. **No SVG.** No animation.
- Screenshot numbers must line up with the order of the captions under
  `== Screenshots ==` in `readme.txt`. They do.
- The banner has no safe area — WordPress.org overlays the plugin name on some
  views, so the composition is deliberately left-weighted with clear space on
  the right.

## The design

- Ground: `#f7f9fb` → `#eaf1f7`. Rule: `#2271b1`.
- Text: `#12212e`, secondary `#4a5866`.
- Mark: `#2271b1` outer shield, `#1b5c93` inner face, `#8fd0ff` growth arrow.

A shield containing a rising bar chart: the "guard" and the "profit" halves of
the name, flat, with no gradient or bevel because the icon is rendered at 128 px
and often smaller.

Nothing here borrows the WordPress, WooCommerce or Shopify marks, and the Woo
purple is deliberately unused. The plugin *name* may say "for WooCommerce"; the
*graphics* may not imply an official affiliation.

## How they were made — and how to remake them

The sources are committed in `assets-src/`:

- `mark.svg` — the shield mark, the single source of truth for the artwork
- `icon.html` — centres the mark on white
- `banner.html` — the mark, wordmark and tagline, sized in `vh` so one file
  renders correctly at both banner sizes

They are rasterised by pointing a headless browser at each file with the
viewport set to the exact output size and taking a screenshot. Any headless
Chrome does this; no image toolchain, font install, or paid service is involved.

```
icon.html    at 256×256   -> icon-256x256.png
icon.html    at 128×128   -> icon-128x128.png
banner.html  at 772×250   -> banner-772x250.png
banner.html  at 1544×500  -> banner-1544x500.png
```

To change the artwork, edit `assets-src/mark.svg` and re-render. Editing the PNGs
directly will be overwritten.

## The screenshots

Captured from the **real plugin**, installed from `dist/profitguard-for-woocommerce.zip`
onto a clean WordPress 7.1 + WooCommerce 11.0.1 with HPOS enabled, populated by
`bin/seed-demo.php`. Viewport 1440 × 900. Every capture was asserted free of
`Notice:`, `Warning:`, `Deprecated:` and `Fatal error` before being saved.

| File | Screen | Caption in `readme.txt` |
| --- | --- | --- |
| `screenshot-1.png` | Dashboard | Score, coverage, profit health, shipping health |
| `screenshot-2.png` | Findings, Margin | Current price, target price, difference |
| `screenshot-3.png` | Findings, Shipping | Charged vs. carrier billed, duplicates |
| `screenshot-4.png` | Import → cost preview | Detected mapping and first rows, before saving |
| `screenshot-5.png` | Import → carrier preview | Optional columns marked "not in this file" |
| `screenshot-6.png` | Settings | Target margin, retention, currency, uninstall |

**Every figure in them is synthetic**, produced by the deterministic seeder. No
real merchant's catalogue, order numbers or revenue appears anywhere.

To retake them, bring up the environment as in `DEVELOPMENT.md`, seed it, and
capture the six URLs:

```
/wp-admin/admin.php?page=profitguard
/wp-admin/admin.php?page=profitguard-findings&module=MARGIN&orderby=impact
/wp-admin/admin.php?page=profitguard-findings&module=SHIPPING&orderby=impact
/wp-admin/admin.php?page=profitguard-import      (after uploading samples/sample-product-costs.csv)
/wp-admin/admin.php?page=profitguard-import      (after uploading samples/sample-carrier-costs.csv)
/wp-admin/admin.php?page=profitguard-settings
```

Set `woocommerce_coming_soon` to `no` first, or every capture carries a
"Store coming soon" badge in the admin bar.
