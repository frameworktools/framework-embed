# Builds the JS bundle, copies it into the PHP package, and runs both test suites.
.PHONY: all install build assets fixtures test test-js test-php check-assets clean \
	wordpress wordpress-test wordpress-e2e drupal drupal-test drupal-e2e statamic-install statamic-test plugins-test

all: build test

install:
	cd js && npm ci
	cd php && composer install

build:
	cd js && npm run build
	$(MAKE) assets

# The PHP package ships the built CSS and JS so plugins can enqueue them.
# The Drupal module serves its own copy, since vendor/ is usually outside the web root.
assets:
	mkdir -p php/assets drupal/assets
	cp js/dist/framework-embed.js js/dist/framework-embed.iife.js js/dist/framework-embed.css php/assets/
	cp js/dist/framework-embed.iife.js js/dist/framework-embed.css drupal/assets/

# Regenerates spec/fixtures/*.html and spec/format-cases.json from the JS renderer.
# Review every diff: these files are the specification both renderers are tested against.
fixtures:
	cd js && npm run fixtures && node scripts/write-format-cases.mjs

test: test-js test-php check-assets

test-js:
	cd js && npm test

test-php:
	cd php && vendor/bin/phpunit

check-assets:
	@for f in framework-embed.js framework-embed.iife.js framework-embed.css; do \
		cmp -s js/dist/$$f php/assets/$$f || { echo "php/assets/$$f is out of date: run make build"; exit 1; }; \
	done; echo "php/assets matches js/dist"
	@for f in framework-embed.iife.js framework-embed.css; do \
		cmp -s php/assets/$$f drupal/assets/$$f || { echo "drupal/assets/$$f is out of date: run make drupal"; exit 1; }; \
	done; echo "drupal/assets matches php/assets"

clean:
	rm -rf js/dist wordpress/vendor-embed wordpress/dist

# CMS plugins. Unit tests use php/vendor's PHPUnit (make install), except Statamic's,
# which need Testbench in statamic/vendor (make statamic-install).
plugins-test: wordpress-test drupal-test statamic-test

# Bundles php/src and php/assets into wordpress/vendor-embed and writes wordpress/dist/framework-embed-<version>.zip.
wordpress:
	wordpress/build/build.sh

wordpress-test: wordpress
	cd wordpress && ../php/vendor/bin/phpunit

# Boots WordPress in Playground (npx, Node 20+) and checks rendered posts with fixture fetches.
wordpress-e2e: wordpress
	wordpress/tests/e2e/run.sh

# Refreshes drupal/assets from php/assets.
drupal:
	mkdir -p drupal/assets
	cp php/assets/framework-embed.iife.js php/assets/framework-embed.css drupal/assets/

drupal-test:
	cd drupal && ../php/vendor/bin/phpunit

# Installs a throwaway site in an existing Drupal Composer project: make drupal-e2e DRUPAL_PROJECT=/path/to/project
drupal-e2e:
	@test -n "$(DRUPAL_PROJECT)" || { echo "Set DRUPAL_PROJECT to a Composer project that requires drupal/framework_embed and drush/drush"; exit 1; }
	drupal/tests/e2e/run.sh "$(DRUPAL_PROJECT)"

statamic-install:
	cd statamic && composer install

statamic-test:
	cd statamic && vendor/bin/phpunit
