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

# WordPress.org requires bundled scripts: no CDN code, so WordPress gets local copies.
# The WordPress fetcher replaces StreamFetcher, which uses raw cURL.
rm "$plugin/vendor-embed/src/Http/StreamFetcher.php"
# Vega: the same files as the CDN defaults in js/src/browser.js, checked against their integrity hashes.
mkdir -p "$plugin/vendor-embed/vega"
for name in vega vega-lite vega-embed; do
	file="$plugin/third-party/vega/$name.min.js"
	expected=$(sed -n "s#.*/npm/$name@[^/]*/build/$name.min.js', integrity: '\(sha384-[^']*\)'.*#\1#p" "$root/js/src/browser.js")
	actual="sha384-$(openssl dgst -sha384 -binary "$file" | openssl base64 -A)"
	[ "$expected" = "$actual" ] || { echo "$name.min.js does not match the integrity hash in js/src/browser.js" >&2; exit 1; }
	cp "$file" "$plugin/third-party/vega/LICENSE-$name" "$plugin/vendor-embed/vega/"
done
# JetBrains Mono: local font files, and the stylesheet points to them.
mkdir -p "$plugin/vendor-embed/assets/fonts"
cp "$plugin"/third-party/fonts/* "$plugin/vendor-embed/assets/fonts/"
sed -i.bak 's#https://cdn\.jsdelivr\.net/npm/@fontsource/jetbrains-mono@[^/]*/files/#fonts/#g' "$plugin/vendor-embed/assets/framework-embed.css"
rm "$plugin/vendor-embed/assets/framework-embed.css.bak"
! grep -q 'cdn\.jsdelivr\.net' "$plugin/vendor-embed/assets/framework-embed.css" || { echo "framework-embed.css still loads from a CDN" >&2; exit 1; }

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/framework-embed"
(cd "$plugin" && cp -R framework-embed.php uninstall.php readme.txt includes blocks vendor-embed "$stage/framework-embed/")
cp "$root/LICENSE" "$stage/framework-embed/LICENSE"
mkdir -p "$plugin/dist"
rm -f "$plugin/dist/framework-embed-$version.zip"
(cd "$stage" && zip -qrX "$plugin/dist/framework-embed-$version.zip" framework-embed)
echo "wordpress/dist/framework-embed-$version.zip"
