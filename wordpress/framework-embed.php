<?php
/**
 * Plugin Name:       Framework Embeds
 * Plugin URI:        https://github.com/framework-cloud/framework-embed
 * Description:       Shows Framework artifacts (models, tables, tests, figures, and charts) from framework.pub and other Framework Cloud servers, without iframes.
 * Version:           0.3.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Framework
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       framework-embed
 * Domain Path:       /languages
 *
 * @package FrameworkEmbed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FRAMEWORK_EMBED_VERSION', '0.3.2' );
define( 'FRAMEWORK_EMBED_FILE', __FILE__ );
define( 'FRAMEWORK_EMBED_DIR', __DIR__ );

if ( ! is_file( __DIR__ . '/vendor-embed/autoload.php' ) ) {
	// A checkout without `make wordpress`: show why nothing works instead of failing.
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Framework Embeds is missing its bundled renderer. Build the plugin with `make wordpress`.', 'framework-embed' ) . '</p></div>';
			}
		}
	);
	return;
}

require_once __DIR__ . '/vendor-embed/autoload.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-live.php';
require_once __DIR__ . '/includes/class-stored.php';
require_once __DIR__ . '/includes/class-settings.php';

Framework_Embed_Plugin::instance()->register();
Framework_Embed_Settings::register();

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( Framework_Embed_Stored::TAKEDOWN_HOOK );
		wp_unschedule_hook( Framework_Embed_Live::HOOK );
	}
);
