#!/bin/sh
# Installs a throwaway Drupal site (SQLite) in an existing Composer project that
# already requires drupal/framework_embed and drush/drush, enables the module
# with the fixture fetcher, serves it with PHP's built-in server, and runs
# check.php. See drupal/README.md for preparing the project.
# Usage: drupal/tests/e2e/run.sh /path/to/drupal-project   (DRUPAL_PORT=8890 by default)
set -eu

site=$(cd "$1" && pwd)
root=$(cd "$(dirname "$0")/../../.." && pwd)
port=${DRUPAL_PORT:-8890}
data=$(mktemp -d)
drush="$site/vendor/bin/drush"

chmod -R u+w "$site/web/sites/default" 2>/dev/null || true
rm -f "$site/web/sites/default/settings.php"
"$drush" -r "$site/web" site:install standard --db-url="sqlite://localhost/$data/site.sqlite" \
	--account-pass=admin --site-name="Framework embed test" -y -q
chmod u+w "$site/web/sites/default" "$site/web/sites/default/settings.php"
cat >>"$site/web/sites/default/settings.php" <<PHP

\$settings['extension_discovery_scan_tests'] = TRUE;
\$settings['framework_embed_fixtures'] = '$root/spec/fixtures';
PHP
"$drush" -r "$site/web" en framework_embed framework_embed_test -y -q
# Unaggregated assets, so check.php can see the module's library files.
"$drush" -r "$site/web" config:set system.performance css.preprocess 0 -y -q
"$drush" -r "$site/web" config:set system.performance js.preprocess 0 -y -q
# Drupal 11.3+ standard installs may leave out the Article type; the recipe is idempotent.
if [ "$("$drush" -r "$site/web" php:eval 'echo \Drupal\node\Entity\NodeType::load("article") ? "yes" : "no";')" = no ]; then
	"$drush" -r "$site/web" recipe core/recipes/article_content_type -q
fi
"$drush" -r "$site/web" php:script "$root/drupal/tests/e2e/setup.php"

(cd "$site/web" && exec php -S "127.0.0.1:$port" .ht.router.php >"$data/server.log" 2>&1) &
server=$!
# DRUPAL_KEEP=1 leaves the site and server running for inspection.
[ "${DRUPAL_KEEP:-0}" = 1 ] || trap 'kill $server 2>/dev/null; rm -rf "$data"' EXIT INT TERM
sleep 1

login=$("$drush" -r "$site/web" user:login --uri="http://127.0.0.1:$port" --no-browser)
status=0
php "$root/drupal/tests/e2e/check.php" "http://127.0.0.1:$port" "$root/spec/fixtures" "$login" "$drush -r $site/web" || status=1

# PHP errors, warnings, and deprecations end up in dblog under "php".
errors=$("$drush" -r "$site/web" watchdog:show --type=php --format=json 2>/dev/null || true)
if [ -n "$errors" ] && [ "$errors" != "[]" ]; then
	echo "FAIL PHP messages logged:"
	echo "$errors"
	status=1
else
	echo "ok   no PHP errors, warnings, or deprecations in dblog"
fi
exit $status
