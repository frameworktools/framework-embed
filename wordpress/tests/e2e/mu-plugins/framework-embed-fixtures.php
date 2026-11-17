<?php
/**
 * Test harness only. Never ship this file. The e2e runner mounts it as a
 * must-use plugin, spec/fixtures at /fixtures, and testing/ at /testing.
 *
 * - Serves embed requests from the shared FixtureServer, counting them in the
 *   framework_embed_test_requests option.
 * - ?as_editor=1 renders a request as user 1.
 * - ?framework_embed_test=… runs one test action and exits:
 *     requests          print the request count
 *     gone, down, bump  set the FixtureServer lists from &names=a,b
 *     expire            mark every cached live copy expired
 *     takedown          run the takedown check and print its counts
 *
 * @package FrameworkEmbed
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended

require_once '/testing/FixtureServer.php';

add_filter(
	'framework_embed_fetcher',
	static function () {
		return new \FrameworkEmbedTesting\FixtureServer(
			'/fixtures',
			(array) get_option( 'framework_embed_test_gone', array() ),
			(array) get_option( 'framework_embed_test_down', array() ),
			(array) get_option( 'framework_embed_test_bump', array() ),
			static function () {
				update_option( 'framework_embed_test_requests', (int) get_option( 'framework_embed_test_requests', 0 ) + 1, false );
			}
		);
	}
);

add_filter(
	'determine_current_user',
	static function ( $user_id ) {
		return isset( $_GET['as_editor'] ) ? 1 : $user_id;
	},
	99
);

add_action(
	'init',
	static function () {
		if ( ! isset( $_GET['framework_embed_test'] ) ) {
			return;
		}
		global $wpdb;
		$action = sanitize_key( $_GET['framework_embed_test'] );
		$names  = isset( $_GET['names'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['names'] ) ) ) ) : array();
		switch ( $action ) {
			case 'requests':
				echo (int) get_option( 'framework_embed_test_requests', 0 );
				break;
			case 'gone':
			case 'down':
			case 'bump':
				update_option( 'framework_embed_test_' . $action, array_values( $names ), false );
				echo 'ok';
				break;
			case 'expire':
				$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_framework\\_embed\\_live\\_%'" );
				foreach ( $rows as $row ) {
					$key   = substr( $row, strlen( '_transient_' ) );
					$entry = get_transient( $key );
					if ( is_array( $entry ) ) {
						$entry['expires'] = 1;
						set_transient( $key, $entry, DAY_IN_SECONDS );
					}
				}
				echo count( $rows );
				break;
			case 'takedown':
				echo wp_json_encode( Framework_Embed_Stored::takedown() );
				break;
		}
		exit;
	},
	1
);
