# Plugin directory graphics

These files do **not** live in the plugin. They go in the SVN `assets/`
directory, a sibling of `trunk/`, and WordPress.org serves them from there.

I cannot produce binary PNGs from this environment, so what follows is the exact
specification plus a script that generates all of them for free.

## What is required

| File | Size | Where it appears | Required? |
| --- | --- | --- | --- |
| `icon-256x256.png` | 256 × 256 | Search results, plugin cards | Strongly recommended |
| `icon-128x128.png` | 128 × 128 | Same, non-retina | Optional if 256 exists |
| `banner-772x250.png` | 772 × 250 | Top of the plugin page | Strongly recommended |
| `banner-1544x500.png` | 1544 × 500 | Same, retina | Optional |
| `screenshot-1.png` … `screenshot-6.png` | ≥ 1200 px wide | Screenshots tab | Strongly recommended |

Rules that actually get enforced:

- PNG or JPG. **No SVG.** No animation.
- Screenshot numbers must line up with the order of the captions under
  `== Screenshots ==` in `readme.txt`. `screenshot-3.png` is the third caption.
- The banner has no safe area. The plugin name is overlaid by WordPress.org on
  some views, so do not put small text near the left edge.
- Keep each file under about 1 MB. There is no hard cap, but the plugin page
  loads all of them.

## Design

Match the plugin, which uses WordPress admin colours and reserves saturation for
severity:

- Ground: `#f6f7f7`. Panel: `#ffffff`. Border: `#c3c4c7`.
- Text: `#1d2327`, secondary `#646970`.
- Accent (the "guard" mark): `#2271b1` — the WordPress admin blue.
- Severity, used only where severity is meant: critical `#8a2424`,
  high `#8a5000`, medium `#7a6000`.

The icon is a shield containing an upward step-chart. Flat, no gradient, no
bevel, no drop shadow — it is rendered at 128 px and often smaller.

**Do not** put "WooCommerce" or "Woo" in the graphics as a logo, and do not use
the Woo purple. The directory rejects assets that imply an official affiliation.
The plugin *name* may say "for WooCommerce"; the *mark* may not borrow theirs.

## Generating them, free

`bin/make-assets.sh` produces every file above using ImageMagick from a Docker
image. Nothing is installed on your machine and nothing is paid for.

```bash
bash bin/make-assets.sh    # -> assets-wporg/
```

For the screenshots, use a browser rather than a generator — real screenshots of
the real plugin are what the directory wants, and fabricating them would be
misrepresenting the product.

## Capturing the six screenshots

Bring up the demo store (see `DEVELOPMENT.md`), set the browser to **1440 × 900**
so the captures are ≥ 1200 px wide, and take these six, in this order:

1. **Dashboard, populated.** `WooCommerce → ProfitGuard` after
   `bin/run-scan.php`. Must show the ProfitGuard Score with its label, the
   coverage panel, and the stat cards.
   Caption: *The dashboard: your ProfitGuard Score, how much of your store it
   covers, and what is costing you the most.*
2. **Findings, filtered to critical.** `Findings` tab, severity filter set to
   Critical, sorted by largest impact.
   Caption: *Every finding, sorted by evidenced financial impact — never by
   guesswork.*
3. **Cost import, the mapping step.** Upload `samples/sample-product-costs.csv`
   and stop at the preview. Must show detected columns, the mapping selects and
   the matched/unmatched counts.
   Caption: *Import supplier costs from CSV. Review the mapping and the match
   results before anything is saved.*
4. **Shipping findings.** Findings filtered to the Shipping module, showing at
   least one `SHIPPING_LOSS` next to one `MISSING_CARRIER_COST` rendered as an
   em dash.
   Caption: *Shipping profit per order, from your carrier invoice. Missing costs
   are shown as missing, never estimated.*
5. **Settings.** Target margin, currency, retention and the uninstall toggle.
   Caption: *Set your target margin once. Everything is calculated from your own
   data, inside your own WordPress.*
6. **Empty state, before the first scan.** A fresh activation, before any scan.
   Caption: *A clear starting point. No score is invented before there is
   anything to score.*

Screenshot 6 needs a clean install — take it first, on the ZIP test stack,
before you seed anything.

**Every screenshot must be of the synthetic demo store.** Never capture a real
merchant's catalogue, order numbers or revenue.
