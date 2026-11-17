#!/bin/sh
# Boots WordPress in Playground with the plugin, a fixture-serving fetcher, and
# test posts, then runs check.php against it. WP-Cron runs only when check.php
# requests wp-cron.php, as on a site with a real cron job. Needs Node 20+ and PHP 8.1+ with curl.
# Usage: wordpress/tests/e2e/run.sh   (PLAYGROUND_PORT=9455 by default)
set -eu

root=$(cd "$(dirname "$0")/../../.." && pwd)
e2e="$root/wordpress/tests/e2e"
port=${PLAYGROUND_PORT:-9455}
log=$(mktemp)
unpacked=$(mktemp -d)

# Test the distributable: build the zip and mount what it unpacks to.
zip=$("$root/wordpress/build/build.sh")
unzip -q "$root/$zip" -d "$unpacked"

npx --yes @wp-playground/cli@3.1.61 server --port="$port" \
	--mount="$unpacked/framework-embed:/wordpress/wp-content/plugins/framework-embed" \
	--mount="$e2e/mu-plugins:/wordpress/wp-content/mu-plugins" \
	--mount="$e2e:/e2e" \
	--mount="$root/spec/fixtures:/fixtures" \
	--mount="$root/testing:/testing" \
	--blueprint="$e2e/blueprint.json" \
	--define-bool WP_DEBUG true --define-bool WP_DEBUG_DISPLAY true \
	--define-bool DISABLE_WP_CRON true >"$log" 2>&1 &
server=$!
# npx starts node as a grandchild, so stop it by its command line too.
trap 'kill $server 2>/dev/null; pkill -f "wp-playground-cli server --port=$port" 2>/dev/null; rm -rf "$log" "$unpacked"' EXIT INT TERM

i=0
until grep -q 'Ready!' "$log"; do
	i=$((i + 1))
	if [ "$i" -gt 180 ] || ! kill -0 "$server" 2>/dev/null; then
		cat "$log"
		echo "Playground did not start" >&2
		exit 1
	fi
	sleep 1
done

php "$e2e/check.php" "http://127.0.0.1:$port" "$root/spec/fixtures"
