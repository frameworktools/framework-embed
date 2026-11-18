<?php
/**
 * Hosts, requests, rendering, the embed handler, the block, and the shortcode.
 *
 * @package FrameworkEmbed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Framework\Embed\Assets;
use Framework\Embed\Client;
use Framework\Embed\Document;
use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;
use Framework\Embed\Renderer;
use Framework\Embed\UrlMatcher;

/**
 * Turns Framework artifact URLs into rendered figures.
 *
 * Two tiers. The framework/artifact block stores the embed document with the
 * post and renders from that copy (Framework_Embed_Stored). URLs pasted on
 * their own line and the shortcode render from a cache that a background job
 * fills (Framework_Embed_Live). Rendering a page never waits on the network.
 */
final class Framework_Embed_Plugin {

	/** Option that holds the extra allowed hosts. */
	const HOSTS_OPTION = 'framework_embed_hosts';

	/** Response size limit in bytes, as in the core StreamFetcher. */
	const MAX_BYTES = 16777216;

	/** Content filters that run autoembed before wpautop. */
	const CONTENT_FILTERS = array( 'the_content', 'widget_text_content', 'widget_block_content' );

	/**
	 * The shared instance.
	 *
	 * @var Framework_Embed_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Figures held back from wpautop and wptexturize, by placeholder token.
	 *
	 * @var array<string, string>
	 */
	private $pending = array();

	/** The shared instance. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Hooks the plugin into WordPress. */
	public function register(): void {
		add_action( 'init', array( $this, 'init' ) );
		foreach ( self::CONTENT_FILTERS as $filter ) {
			add_filter( $filter, array( $this, 'restore_placeholders' ), 9999 );
		}
		Framework_Embed_Live::register();
		Framework_Embed_Stored::register();
	}

	/** Registers assets, the block, the shortcode, and the embed handler. */
	public function init(): void {
		$url = plugins_url( 'vendor-embed/assets/', FRAMEWORK_EMBED_FILE );
		wp_register_style( 'framework-embed', $url . 'framework-embed.css', array(), Assets::version() );
		wp_add_inline_style( 'framework-embed', '.framework-embed-notice{border-left:4px solid #d63638;padding:.5em 1em;background:#fcf0f1;color:#1e1e1e;font-size:.875em}' );
		wp_register_script(
			'framework-embed',
			$url . 'framework-embed.iife.js',
			array(),
			Assets::version(),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		// Charts load the bundled vega, vega-lite, and vega-embed instead of the CDN defaults.
		$vega = plugins_url( 'vendor-embed/vega/', FRAMEWORK_EMBED_FILE );
		wp_add_inline_script(
			'framework-embed',
			'window.FrameworkEmbedConfig = Object.assign({}, window.FrameworkEmbedConfig, ' . wp_json_encode(
				array(
					'vegaScripts' => array(
						$vega . 'vega.min.js',
						$vega . 'vega-lite.min.js',
						$vega . 'vega-embed.min.js',
					),
				)
			) . ');',
			'before'
		);

		register_block_type(
			FRAMEWORK_EMBED_DIR . '/blocks/artifact',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
		wp_set_script_translations( 'framework-artifact-editor-script', 'framework-embed' );

		add_shortcode( 'framework_artifact', array( $this, 'render_shortcode' ) );

		// Matches any http(s) URL whose path looks like an artifact. handle_embed()
		// checks the host against the allow-list and declines the rest.
		wp_embed_register_handler(
			'framework',
			'#^https?://[^/\s?\#]+/(?:d/|@|api/embed/v1/artifacts/)[^\s]*$#i',
			array( $this, 'handle_embed' )
		);
	}

	/**
	 * The allowed hosts: framework.pub, then the administrator's extra hosts.
	 *
	 * @return list<string>
	 */
	public function hosts(): array {
		$hosts = array_merge( UrlMatcher::DEFAULT_HOSTS, $this->extra_hosts() );

		/**
		 * Filters the hosts embed documents are fetched from.
		 *
		 * @param list<string> $hosts Bare host names (https) or origins such as http://localhost:8000.
		 */
		return array_values( array_unique( (array) apply_filters( 'framework_embed_hosts', $hosts ) ) );
	}

	/**
	 * The hosts an administrator added in Settings → Framework embeds.
	 *
	 * @return list<string>
	 */
	public function extra_hosts(): array {
		$hosts = get_option( self::HOSTS_OPTION, array() );

		return is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();
	}

	/** The allow-list matcher for the current hosts. */
	public function matcher(): UrlMatcher {
		return new UrlMatcher( $this->hosts() );
	}

	/** The longest time a live document is fresh, in seconds. */
	public function max_ttl(): int {
		/**
		 * Filters the longest time a cached embed document counts as fresh, in
		 * seconds. The server's Cache-Control can only shorten it.
		 *
		 * @param int $max_ttl Seconds.
		 */
		return max( 0, (int) apply_filters( 'framework_embed_max_ttl', HOUR_IN_SECONDS ) );
	}

	/**
	 * Requests one embed document now, bypassing every cache. Background jobs
	 * and the editor's fetch endpoint call this; page rendering never does.
	 *
	 * @param string $url Artifact URL or embed API URL on an allowed host.
	 * @return array{doc: ?array, body: string, ttl: int} doc is null when the server answers 404 or 410.
	 * @throws EmbedException When the URL is not allowed or the request fails.
	 */
	public function request( string $url ): array {
		/**
		 * Filters the fetcher. A fetcher takes an embed API URL and returns a
		 * Framework\Embed\Http\Response. It must not follow redirects.
		 *
		 * @param callable $fetcher The WordPress HTTP API fetcher.
		 */
		$fetcher  = apply_filters( 'framework_embed_fetcher', array( $this, 'fetch' ) );
		$response = null;
		$capture  = static function ( string $request_url ) use ( $fetcher, &$response ) {
			$answer = $fetcher( $request_url );
			if ( is_array( $answer ) ) {
				$answer = new Response( (int) ( $answer['status'] ?? 0 ), (array) ( $answer['headers'] ?? array() ), (string) ( $answer['body'] ?? '' ) );
			}

			return $response = $answer;
		};
		$doc      = ( new Client( $this->hosts(), $capture ) )->resolve( $url );

		return array(
			'doc'  => $doc,
			'body' => $response instanceof Response ? $response->body : '',
			'ttl'  => $response instanceof Response ? min( $this->max_ttl(), Client::ttl( $response ) ) : 0,
		);
	}

	/**
	 * Requests an embed API URL with wp_safe_remote_get, without redirects or cookies.
	 *
	 * @param string $url Embed API URL on an allowed host.
	 * @throws EmbedException When the request fails.
	 */
	public function fetch( string $url ): Response {
		// wp_safe_remote_get refuses private addresses and unusual ports. An
		// administrator's own Framework server may use either, so allow exactly
		// the hosts they listed for the length of this request.
		$listed = new UrlMatcher( $this->extra_hosts() );
		$trust  = $listed->isAllowed( $url );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port   = (int) wp_parse_url( $url, PHP_URL_PORT );

		$external = static function ( $is_external, $request_host ) use ( $host ) {
			return strtolower( (string) $request_host ) === strtolower( $host ) ? true : $is_external;
		};
		$ports    = static function ( $allowed ) use ( $port ) {
			return $port > 0 ? array_merge( (array) $allowed, array( $port ) ) : $allowed;
		};
		if ( $trust ) {
			add_filter( 'http_request_host_is_external', $external, 10, 2 );
			add_filter( 'http_allowed_safe_ports', $ports );
		}

		try {
			$answer = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
					'user-agent'          => 'framework-embed-wordpress/' . FRAMEWORK_EMBED_VERSION . '; ' . home_url( '/' ),
					'headers'             => array( 'Accept' => 'application/json' ),
					'cookies'             => array(),
				)
			);
		} finally {
			if ( $trust ) {
				remove_filter( 'http_request_host_is_external', $external, 10 );
				remove_filter( 'http_allowed_safe_ports', $ports );
			}
		}

		if ( is_wp_error( $answer ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; EmbedException::HTTP is an error code.
			throw new EmbedException( EmbedException::HTTP, esc_html( 'The embed request failed: ' . $answer->get_error_message() ) );
		}

		$headers = wp_remote_retrieve_headers( $answer );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		return new Response( (int) wp_remote_retrieve_response_code( $answer ), $headers, (string) wp_remote_retrieve_body( $answer ) );
	}

	/**
	 * Renders a stored or cached document and enqueues its assets. Image
	 * artifacts point at the image URL in the document; no file is copied.
	 *
	 * @param string $json Raw embed document.
	 * @return string|null Null when the JSON is not an embed document.
	 */
	public function render_json( string $json ): ?string {
		$doc = Document::decode( $json );
		if ( null === $doc ) {
			return null;
		}
		try {
			$html = Renderer::render( $doc );
		} catch ( \Throwable $e ) {
			return null;
		}
		$this->enqueue( $html );

		return $html;
	}

	/**
	 * Enqueues the stylesheet, and the script when the figure holds a chart
	 * or variable tips (the script draws charts, and opens tips on hover
	 * beside their term).
	 *
	 * @param string $html Rendered figure.
	 */
	public function enqueue( string $html ): void {
		wp_enqueue_style( 'framework-embed' );
		if ( str_contains( $html, 'data-fw-embed-chart' ) || str_contains( $html, 'fw-embed__term' ) ) {
			wp_enqueue_script( 'framework-embed' );
		}
	}

	/**
	 * Embed handler callback for URLs pasted on their own line (live tier).
	 *
	 * @param array  $matches Regex matches.
	 * @param array  $attr    Embed attributes.
	 * @param string $url     The URL.
	 * @return string|false False lets WordPress try other handlers and oEmbed.
	 */
	public function handle_embed( $matches, $attr, $url ) {
		if ( null === $this->matcher()->match( (string) $url ) ) {
			return false;
		}

		return $this->protect( Framework_Embed_Live::render( (string) $url ) );
	}

	/**
	 * Hides a figure from wpautop when called from a content filter.
	 * Autoembed runs before wpautop, which would add <br> and <p> tags inside
	 * the figure. Hand it a block-level placeholder and put the figure back
	 * after the formatting filters.
	 *
	 * @param string $html Rendered HTML.
	 */
	public function protect( string $html ): string {
		$filter = current_filter();
		if ( '' !== $html && in_array( $filter, self::CONTENT_FILTERS, true ) && false !== has_filter( $filter, 'wpautop' ) ) {
			$token                   = bin2hex( random_bytes( 8 ) );
			$this->pending[ $token ] = $html;

			return "\n" . '<div data-framework-embed="' . $token . '"></div>' . "\n";
		}

		return $html;
	}

	/**
	 * Replaces placeholders left by protect() with their figures.
	 *
	 * @param string $content Filtered content.
	 */
	public function restore_placeholders( $content ) {
		if ( ! $this->pending || ! is_string( $content ) || ! str_contains( $content, 'data-framework-embed="' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'#(?:<p>\s*)?<div data-framework-embed="([0-9a-f]{16})"></div>(?:\s*</p>)?#',
			function ( $m ) {
				if ( ! isset( $this->pending[ $m[1] ] ) ) {
					return $m[0];
				}
				$html = $this->pending[ $m[1] ];
				unset( $this->pending[ $m[1] ] );

				return $html;
			},
			$content
		);
	}

	/**
	 * Render callback for the framework/artifact block: the stored copy, or
	 * the live tier for blocks saved without one.
	 *
	 * @param array $attributes Block attributes.
	 */
	public function render_block( $attributes ): string {
		$html = Framework_Embed_Stored::render( is_array( $attributes ) ? $attributes : array() );

		return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Shortcode callback: [framework_artifact url="…"] (live tier).
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'url' => '' ), $atts, 'framework_artifact' );

		return Framework_Embed_Live::render( html_entity_decode( (string) $atts['url'], ENT_QUOTES ) );
	}

	/**
	 * A plain link to the artifact.
	 *
	 * @param string $url Artifact URL.
	 */
	public function link( string $url ): string {
		$href = esc_url( $url, array( 'http', 'https' ) );

		return '' === $href ? '' : '<p><a href="' . $href . '">' . esc_html( $url ) . '</a></p>';
	}

	/**
	 * A notice for people who can edit posts, explaining a missing figure.
	 *
	 * @param string $message Plain text.
	 * @param string $url     Artifact URL.
	 */
	public function notice( string $message, string $url ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		wp_enqueue_style( 'framework-embed' );

		return '<p class="framework-embed-notice" role="note">' . esc_html( $message ) . ' <code>' . esc_html( $url ) . '</code></p>';
	}
}
