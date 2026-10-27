#!/usr/bin/env bash
#
# Build the wordpress.org submission zip.
#
# Stages exactly what ships — plugin PHP, readme, license, runtime assets —
# under a top-level `sendfeather/` directory, and excludes everything that is
# development-only (dev/, tests, images/icons which belong in SVN /assets).
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

STAGE="dist/stage"
ZIP="dist/sendfeather.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/sendfeather"

cp sendfeather.php uninstall.php readme.txt LICENSE "$STAGE/sendfeather/"
cp -R includes "$STAGE/sendfeather/includes"
mkdir -p "$STAGE/sendfeather/assets/css" "$STAGE/sendfeather/assets/js" "$STAGE/sendfeather/languages"
cp assets/css/admin.css "$STAGE/sendfeather/assets/css/"
cp assets/js/admin.js "$STAGE/sendfeather/assets/js/"

# Sanity: the stable tag and the plugin header version must agree.
header_version=$(grep -m1 '^ \* Version:' sendfeather.php | sed 's/[^0-9.]//g')
stable_tag=$(grep -m1 '^Stable tag:' readme.txt | sed 's/[^0-9.]//g')
if [ "$header_version" != "$stable_tag" ]; then
  echo "Version mismatch: plugin header ${header_version} vs stable tag ${stable_tag}" >&2
  exit 1
fi

(cd "$STAGE" && zip -qr "../sendfeather.zip" sendfeather)
rm -rf "$STAGE"

echo "Built ${ZIP} (version ${header_version})"
unzip -l "$ZIP"
