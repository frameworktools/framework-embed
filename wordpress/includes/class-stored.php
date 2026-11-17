<?php
/**
 * Stored tier: the framework/artifact block keeps the embed document in the post.
 *
 * @package FrameworkEmbed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Framework\Embed\EmbedException;
use Framework\Embed\Renderer;

/**
 * The block stores the embed document in its `document` attribute, as the raw
 * JSON string the server sent, with the pinned version URL in `embedUrl`.
 * Pages render from that copy and never contact the server. The editor fetches
 * through the REST route below; "Fetch again" and "Use newest version" replace
 * the copy only after the author confirms.
 *
 * A daily job (Settings → Framework embeds, on by default) asks the server
 * about each stored copy and removes it only when the server answers 404 or
 * 410. The block then renders a plain link.
 */
final class Framework_Embed_Stored {

	/** Daily takedown check hook. */
	const TAKEDOWN_HOOK = 'framework_embed_takedown';

	/** Option: whether the takedown check runs. */
	const TAKEDOWN_OPTION = 'framework_embed_takedown';

	/** Posts examined per takedown run. */
	const TAKEDOWN_BATCH = 200;

	/** Hooks REST and the takedown job. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( self::TAKEDOWN_HOOK, array( self::class, 'takedown' ) );
		add_action( 'init', array( self::class, 'schedule_takedown' ) );
	}

	/** Schedules the daily takedown check when it is missing. */
	public static function schedule_takedown(): void {
		if ( ! wp_next_scheduled( self::TAKEDOWN_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::TAKEDOWN_HOOK );
		}
	}

	/** Whether the takedown check is on. */
	public static function takedown_enabled(): bool {
		return (bool) get_option( self::TAKEDOWN_OPTION, true );
	}

	/**
	 * HTML for block attributes.
	 *
	 * @param array $attributes url, embedUrl, document, fetchedAt, removed.
	 */
	public static function render( array $attributes ): string {
		$plugin   = Framework_Embed_Plugin::instance();
		$url      = isset( $attributes['url'] ) && is_string( $attributes['url'] ) ? trim( $attributes['url'] ) : '';
		$document = isset( $attributes['document'] ) && is_string( $attributes['document'] ) ? $attributes['document'] : '';

		if ( '' !== $document ) {
			$html = $plugin->render_json( $document );
			if ( null !== $html ) {
				return $html;
			}
		}
		if ( ! empty( $attributes['removed'] ) ) {
			return $plugin->link( $url ) . $plugin->notice( __( 'This artifact is no longer available on the Framework Cloud server, so its stored copy was removed. Visitors see a link.', 'framework-embed' ), $url );
		}
		if ( '' === $url ) {
			return '';
		}

		// A block saved without a stored copy, for example written by hand.
		return Framework_Embed_Live::render( $url );
	}

	/** Registers POST /framework-embed/v1/fetch for the block editor. */
	public static function register_routes(): void {
		register_rest_route(
			'framework-embed/v1',
			'/fetch',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_fetch' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'url'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'newest' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * The URL that answers with the newest version of the artifact at $url.
	 *
	 * @param string $url Artifact URL or embed API URL, with or without a version.
	 */
	public static function newest_url( string $url ): ?string {
		$match = Framework_Embed_Plugin::instance()->matcher()->match( $url );
		if ( null === $match ) {
			return null;
		}
		if ( 'path' === $match->type ) {
			return $match->origin . '/@' . $match->handle . '/' . $match->project . '/' . $match->slug;
		}

		return $match->origin . ( 'embed' === $match->type ? '/api/embed/v1/artifacts/' : '/d/' ) . $match->key;
	}

	/**
	 * Fetches an embed document for the editor.
	 *
	 * @param string $url    Artifact URL or the pinned embed URL.
	 * @param bool   $newest Fetch the newest version instead of the pinned one.
	 * @return array|WP_Error document, embedUrl, url, title, version, latestVersion, fetchedAt, html.
	 */
	public static function fetch_for_editor( string $url, bool $newest = false ) {
		$plugin = Framework_Embed_Plugin::instance();
		$target = $newest ? self::newest_url( $url ) : trim( $url );
		if ( null === $target || null === $plugin->matcher()->match( $target ) ) {
			return new WP_Error( 'framework_embed_not_allowed', __( 'This URL is not a Framework artifact on an allowed host. Add self-hosted Framework Cloud servers under Settings → Framework embeds.', 'framework-embed' ), array( 'status' => 400 ) );
		}
		try {
			$answer = $plugin->request( $target );
		} catch ( EmbedException $e ) {
			return new WP_Error( 'framework_embed_unreachable', __( 'Could not reach the Framework Cloud server or read its embed response.', 'framework-embed' ), array( 'status' => 502 ) );
		}
		$doc = $answer['doc'];
		if ( null === $doc ) {
			return new WP_Error( 'framework_embed_not_found', __( 'This Framework artifact is private, password-protected, or deleted.', 'framework-embed' ), array( 'status' => 404 ) );
		}

		// Pin the version URL. Accept it only on an allowed host.
		$embed_url = isset( $doc['embed_url'] ) && is_string( $doc['embed_url'] ) ? $doc['embed_url'] : '';
		$pinned    = $plugin->matcher()->match( $embed_url );
		$embed_url = null !== $pinned && 'embed' === $pinned->type ? $embed_url : '';

		return array(
			'document'      => $answer['body'],
			'embedUrl'      => $embed_url,
			'url'           => isset( $doc['url'] ) && is_string( $doc['url'] ) ? $doc['url'] : $target,
			'title'         => isset( $doc['title'] ) && is_string( $doc['title'] ) ? $doc['title'] : '',
			'version'       => isset( $doc['version'] ) && is_int( $doc['version'] ) ? $doc['version'] : null,
			'latestVersion' => isset( $doc['latest_version'] ) && is_int( $doc['latest_version'] ) ? $doc['latest_version'] : null,
			'fetchedAt'     => gmdate( 'c' ),
			'html'          => Renderer::render( $doc ),
		);
	}

	/**
	 * REST callback.
	 *
	 * @param WP_REST_Request $request Request with url and newest.
	 */
	public static function rest_fetch( $request ) {
		$result = self::fetch_for_editor( (string) $request['url'], (bool) $request['newest'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Daily job: removes stored copies whose artifact the server reports gone.
	 * Only a 404 or 410 counts; timeouts, 5xx answers, and hosts no longer
	 * allowed leave the copy in place.
	 *
	 * @return array{checked: int, removed: int} Counts for this run.
	 */
	public static function takedown(): array {
		global $wpdb;
		$counts = array(
			'checked' => 0,
			'removed' => 0,
		);
		if ( ! self::takedown_enabled() ) {
			return $counts;
		}

		$cursor = (int) get_option( 'framework_embed_takedown_cursor', 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s ORDER BY ID LIMIT %d",
				$cursor,
				'%' . $wpdb->esc_like( '<!-- wp:framework/artifact ' ) . '%',
				self::TAKEDOWN_BATCH
			)
		);
		update_option( 'framework_embed_takedown_cursor', count( $ids ) < self::TAKEDOWN_BATCH ? 0 : (int) end( $ids ), false );

		$verdicts = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			$changed = false;
			$blocks  = self::check_blocks( parse_blocks( $post->post_content ), $verdicts, $counts, $changed );
			if ( $changed ) {
				self::save_content( (int) $id, serialize_blocks( $blocks ) );
			}
		}

		return $counts;
	}

	/**
	 * Walks blocks, asking the server once per pinned URL.
	 *
	 * @param array $blocks   Parsed blocks.
	 * @param array $verdicts Per-URL results for this run.
	 * @param array $counts   Running counts.
	 * @param bool  $changed  Set when a block was changed.
	 */
	private static function check_blocks( array $blocks, array &$verdicts, array &$counts, bool &$changed ): array {
		$plugin = Framework_Embed_Plugin::instance();
		foreach ( $blocks as $i => $block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::check_blocks( $block['innerBlocks'], $verdicts, $counts, $changed );
			}
			$attrs = $block['attrs'] ?? array();
			if ( 'framework/artifact' !== ( $block['blockName'] ?? '' ) || empty( $attrs['document'] ) ) {
				continue;
			}
			$target = ! empty( $attrs['embedUrl'] ) ? (string) $attrs['embedUrl'] : (string) ( $attrs['url'] ?? '' );
			if ( null === $plugin->matcher()->match( $target ) ) {
				continue;
			}
			if ( ! isset( $verdicts[ $target ] ) ) {
				++$counts['checked'];
				try {
					$verdicts[ $target ] = null === $plugin->request( $target )['doc'] ? 'gone' : 'ok';
				} catch ( EmbedException $e ) {
					$verdicts[ $target ] = 'unknown';
				}
			}
			if ( 'gone' === $verdicts[ $target ] ) {
				$blocks[ $i ]['attrs']['document'] = '';
				$blocks[ $i ]['attrs']['removed']  = true;
				++$counts['removed'];
				$changed = true;
			}
		}

		return $blocks;
	}

	/**
	 * Saves rewritten content without kses, which would otherwise filter the
	 * whole post as if the cron user wrote it.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content New post content.
	 */
	private static function save_content( int $id, string $content ): void {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $content ),
			)
		);
		if ( $kses ) {
			kses_init_filters();
		}
	}
}
