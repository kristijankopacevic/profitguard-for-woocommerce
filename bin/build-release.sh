#!/usr/bin/env bash
#
# Build the SVN-ready release tree that WordPress.org expects.
#
#   release/
#     trunk/        the plugin, exactly as shipped
#     tags/<ver>/   the same files, frozen under the version number
#     assets/       icon, banners and screenshots (NOT part of the plugin)
#
# trunk/ and tags/<ver>/ are unpacked from dist/profitguard-for-woocommerce.zip
# rather than copied from the working tree, so what goes to SVN is byte for byte
# what was tested. Run bin/build-zip.sh first.
#
#   bash bin/build-zip.sh && bash bin/build-release.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="profitguard-for-woocommerce"
ZIP="dist/${SLUG}.zip"
GRAPHICS="assets-wporg"
RELEASE="release"

[ -f "${ZIP}" ] || { echo "ERROR: ${ZIP} not found. Run bin/build-zip.sh first." >&2; exit 1; }

# The version is read from the plugin header, never passed in: a tag that
# disagrees with the header is the single most common broken release.
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' "${SLUG}.php" | head -1)"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -1)"

if [ -z "${VERSION}" ]; then
	echo "ERROR: could not read Version from ${SLUG}.php" >&2; exit 1
fi
if [ "${VERSION}" != "${STABLE}" ]; then
	echo "ERROR: plugin header Version (${VERSION}) != readme Stable tag (${STABLE})." >&2
	echo "       WordPress.org serves the STABLE TAG. Fix one of them." >&2
	exit 1
fi

rm -rf "${RELEASE}"
mkdir -p "${RELEASE}/trunk" "${RELEASE}/tags/${VERSION}" "${RELEASE}/assets"

TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

if command -v unzip >/dev/null 2>&1; then
	unzip -q "${ZIP}" -d "${TMP}"
else
	# Same fallback reasoning as build-zip.sh: `unzip` is not everywhere, and a
	# real interpreter test beats trusting `command -v` on Windows.
	PY=""
	for candidate in python3 python py; do
		if command -v "${candidate}" >/dev/null 2>&1 && "${candidate}" -c 'import zipfile' >/dev/null 2>&1; then
			PY="${candidate}"; break
		fi
	done
	[ -n "${PY}" ] || { echo "ERROR: need unzip or a working python." >&2; exit 1; }
	"${PY}" -c "import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" "${ZIP}" "${TMP}"
fi

cp -r "${TMP}/${SLUG}/." "${RELEASE}/trunk/"
cp -r "${TMP}/${SLUG}/." "${RELEASE}/tags/${VERSION}/"

# Exactly the files WordPress.org serves from assets/, by their exact names.
MISSING=0
for f in icon-256x256.png icon-128x128.png banner-772x250.png banner-1544x500.png \
         screenshot-1.png screenshot-2.png screenshot-3.png \
         screenshot-4.png screenshot-5.png screenshot-6.png; do
	if [ -f "${GRAPHICS}/${f}" ]; then
		cp "${GRAPHICS}/${f}" "${RELEASE}/assets/${f}"
	else
		echo "MISSING GRAPHIC: ${GRAPHICS}/${f}" >&2
		MISSING=1
	fi
done
[ "${MISSING}" -eq 0 ] || { echo "ERROR: graphics missing; see ASSETS.md." >&2; exit 1; }

# Nothing that is not the plugin may reach trunk.
if find "${RELEASE}/trunk" \( -name 'vendor' -o -name 'tests' -o -name '.git*' \
	-o -name 'composer.json' -o -name '*.dist' -o -name 'docker-compose*' \) | grep -q .; then
	echo "ERROR: development files reached release/trunk." >&2
	exit 1
fi

echo "Release tree ready for SVN, version ${VERSION}:"
echo "  ${RELEASE}/trunk           $(find "${RELEASE}/trunk" -type f | wc -l | tr -d ' ') files"
echo "  ${RELEASE}/tags/${VERSION}      $(find "${RELEASE}/tags/${VERSION}" -type f | wc -l | tr -d ' ') files"
echo "  ${RELEASE}/assets          $(find "${RELEASE}/assets" -type f | wc -l | tr -d ' ') files"
