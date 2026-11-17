#!/bin/sh
# Bundles the PHP core into wordpress/vendor-embed and writes wordpress/dist/framework-embed-<version>.zip.
# Usage: wordpress/build/build.sh (from any directory)
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
plugin="$root/wordpress"
version=$(sed -n 's/^ \* Version: *//p' "$plugin/framework-embed.php")

rm -rf "$plugin/vendor-embed"
mkdir -p "$plugin/vendor-embed"
cp -R "$root/php/src" "$plugin/vendor-embed/src"
cp -R "$root/php/assets" "$plugin/vendor-embed/assets"
cp "$root/php/LICENSE" "$plugin/vendor-embed/LICENSE"
cp "$plugin/build/autoload.php" "$plugin/vendor-embed/autoload.php"

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/framework-embed"
(cd "$plugin" && cp -R framework-embed.php uninstall.php readme.txt includes blocks languages vendor-embed "$stage/framework-embed/")
cp "$root/LICENSE" "$stage/framework-embed/LICENSE"
mkdir -p "$plugin/dist"
rm -f "$plugin/dist/framework-embed-$version.zip"
(cd "$stage" && zip -qrX "$plugin/dist/framework-embed-$version.zip" framework-embed)
echo "wordpress/dist/framework-embed-$version.zip"
