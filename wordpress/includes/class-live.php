<?php
/**
 * Live tier: pasted URLs and the shortcode, rendered from a cache that a
 * background job fills.
 *
 * @package FrameworkEmbed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Framework\Embed\EmbedException;

/**
 * Renders URLs that have no stored copy without waiting on the network.
 *
 * - No cached copy: a plain link now; a background job fetches the document.
 * - Fresh copy (within its Cache-Control max-age): the figure.
 * - Expired copy: the figure, and a background refresh (stale-while-revalidate).
 * - 404 from the server: the copy is dropped and the link stays.
 *
 * Copies are kept for STALE_TTL past their max-age so an expired copy can
 * still render while the refresh runs.
 */
final class Framework_Embed_Live {

	/** Background job hook. */
	const HOOK = 'framework_embed_refresh';

	/** Seconds an expired copy may still render while it is refreshed. */
	const STALE_TTL = WEEK_IN_SECONDS;

	/** Seconds between attempts for one URL, so views don't queue duplicate jobs. */
	const RETRY_TTL = 300;

	/** Seconds a 404 is remembered before the URL is tried again. */
	const GONE_TTL = HOUR_IN_SECONDS;

	/** Hooks the background job. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'refresh' ) );
	}

	/**
	 * Transient key for one URL and kind of record.
	 *
	 * @param string $kind live, pending, or gone.
	 * @param string $url  Artifact URL.
	 */
	public static function key( string $kind, string $url ): string {
		return 'framework_embed_' . $kind . '_' . sha1( trim( $url ) );
	}

	/**
	 * HTML for a URL from the cache, scheduling a fetch when needed.
	 *
	 * @param string $url Artifact URL as the author pasted it.
	 */
	public static function render( string $url ): string {
		$plugin = Framework_Embed_Plugin::instance();
		$url    = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( null === $plugin->matcher()->match( $url ) ) {
			return $plugin->link( $url ) . $plugin->notice( __( 'This URL is not a Framework artifact on an allowed host. Add self-hosted Framework Cloud servers under Settings → Framework embeds.', 'framework-embed' ), $url );
		}

		$entry = get_transient( self::key( 'live', $url ) );
		if ( is_array( $entry ) && isset( $entry['body'], $entry['expires'] ) ) {
			$html = $plugin->render_json( (string) $entry['body'] );
			if ( null !== $html ) {
				if ( (int) $entry['expires'] <= time() ) {
					self::schedule( $url );
				}

				return $html;
			}
		}

		if ( get_transient( self::key( 'gone', $url ) ) ) {
			return $plugin->link( $url ) . $plugin->notice( __( 'This Framework artifact is private, password-protected, or deleted. Visitors see a link.', 'framework-embed' ), $url );
		}

		self::schedule( $url );

		return $plugin->link( $url ) . $plugin->notice( __( 'This artifact is being fetched in the background. Visitors see a link until the fetch completes.', 'framework-embed' ), $url );
	}

	/**
	 * Queues a background fetch, at most once per RETRY_TTL for a URL. Uses
	 * Action Scheduler when a plugin provides it, else WP-Cron.
	 *
	 * @param string $url Artifact URL.
	 */
	public static function schedule( string $url ): void {
		$pending = self::key( 'pending', $url );
		if ( get_transient( $pending ) ) {
			return;
		}
		set_transient( $pending, 1, self::RETRY_TTL );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $url ), 'framework-embed' );
		} else {
			wp_schedule_single_event( time(), self::HOOK, array( $url ) );
		}
	}

	/**
	 * Background job: fetches a URL and updates its cached copy. A failed
	 * request keeps the old copy; the next view after RETRY_TTL tries again.
	 *
	 * @param string $url Artifact URL.
	 * @return string fresh, uncacheable, gone, or error.
	 */
	public static function refresh( $url ): string {
		$url = trim( (string) $url );
		try {
			$answer = Framework_Embed_Plugin::instance()->request( $url );
		} catch ( EmbedException $e ) {
			return 'error';
		}
		delete_transient( self::key( 'pending', $url ) );

		if ( null === $answer['doc'] ) {
			delete_transient( self::key( 'live', $url ) );
			set_transient( self::key( 'gone', $url ), 1, self::GONE_TTL );

			return 'gone';
		}
		delete_transient( self::key( 'gone', $url ) );
		if ( $answer['ttl'] <= 0 ) {
			// no-store, no-cache, private, or no max-age: nothing may be kept.
			delete_transient( self::key( 'live', $url ) );

			return 'uncacheable';
		}
		set_transient(
			self::key( 'live', $url ),
			array(
				'body'    => $answer['body'],
				'expires' => time() + $answer['ttl'],
			),
			$answer['ttl'] + self::STALE_TTL
		);

		return 'fresh';
	}
}
