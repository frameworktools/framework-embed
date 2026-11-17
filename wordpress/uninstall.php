<?php
/**
 * Removes the plugin's option and cached embed documents.
 *
 * @package FrameworkEmbed
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the options, scheduled jobs, and every framework_embed_ transient on
 * the current site. Stored copies inside posts stay: they are post content.
 */
function framework_embed_uninstall_site() {
	global $wpdb;

	delete_option( 'framework_embed_hosts' );
	delete_option( 'framework_embed_takedown' );
	delete_option( 'framework_embed_takedown_cursor' );
	wp_clear_scheduled_hook( 'framework_embed_takedown' );
	wp_unschedule_hook( 'framework_embed_refresh' );

	// Transients may live in the options table; delete them by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_framework_embed_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_framework_embed_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $framework_embed_site ) {
		switch_to_blog( $framework_embed_site );
		framework_embed_uninstall_site();
		restore_current_blog();
	}
} else {
	framework_embed_uninstall_site();
}
