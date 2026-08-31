#!/usr/bin/env bash
#
# Generate the WordPress.org plugin directory graphics.
#
# Uses ImageMagick from a public Docker image, so nothing is installed on the
# host and nothing is paid for. Screenshots are NOT generated here - those must
# be real captures of the real plugin. See ASSETS.md.
#
#   bash bin/make-assets.sh   ->  assets-wporg/
#
set -euo pipefail

cd "$(dirname "$0")/.."
OUT="assets-wporg"
mkdir -p "$OUT"

IM="docker run --rm -v ${PWD}/${OUT}:/out dpokidov/imagemagick"

# The shield-with-step-chart mark, drawn as SVG and rasterised. Kept flat: it is
# displayed at 128px and smaller, where a gradient just turns into mud.
read -r -d '' ICON_SVG <<'SVG' || true
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">
  <rect width="256" height="256" rx="52" fill="#ffffff"/>
  <path d="M128 26 L214 60 v70 c0 52-37 84-86 100-49-16-86-48-86-100 V60 Z"
        fill="#2271b1"/>
  <g fill="#ffffff">
    <rect x="84"  y="140" width="22" height="34" rx="3"/>
    <rect x="117" y="118" width="22" height="56" rx="3"/>
    <rect x="150" y="88"  width="22" height="86" rx="3"/>
  </g>
  <path d="M86 118 L128 96 L170 62" fill="none" stroke="#ffffff"
        stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M150 62 h22 v22" fill="none" stroke="#ffffff"
        stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG

printf '%s' "$ICON_SVG" > "${OUT}/.icon.svg"

$IM /out/.icon.svg -resize 256x256 /out/icon-256x256.png
$IM /out/.icon.svg -resize 128x128 /out/icon-128x128.png

# The banner. No small text near the left edge: WordPress.org overlays the
# plugin name there on some views.
banner() {
  local w=$1 h=$2 name=$3 title=$4 sub=$5 icon=$6
  $IM -size "${w}x${h}" "xc:#f6f7f7" \
    -fill "#ffffff" -draw "rectangle 0,0 ${w},$((h*22/100))" \
    -fill "#2271b1" -draw "rectangle 0,$((h*97/100)) ${w},${h}" \
    \( "/out/${icon}" -resize "$((h*44/100))x$((h*44/100))" \) \
    -gravity west -geometry "+$((w*5/100))+0" -composite \
    -gravity west -fill "#1d2327" -pointsize "$title" \
    -annotate "+$((w*20/100))-$((h*9/100))" "ProfitGuard" \
    -gravity west -fill "#646970" -pointsize "$sub" \
    -annotate "+$((w*20/100))+$((h*10/100))" \
      "Product margins and shipping profit, calculated inside your own WordPress." \
    "/out/${name}"
}

banner 772  250 banner-772x250.png   52  19 icon-256x256.png
banner 1544 500 banner-1544x500.png 104  38 icon-256x256.png

rm -f "${OUT}/.icon.svg"

echo
echo "Generated in ${OUT}/:"
ls -1 "${OUT}"
echo
echo "Screenshots are NOT generated - capture the six real states listed in"
echo "ASSETS.md, name them screenshot-1.png .. screenshot-6.png, and put them"
echo "in the same directory before committing to SVN assets/."
