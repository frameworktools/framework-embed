<?php
/**
 * Settings → Framework embeds.
 *
 * @package FrameworkEmbed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Framework\Embed\UrlMatcher;

/**
 * The settings page for extra allowed hosts.
 */
final class Framework_Embed_Settings {

	/** Settings group and page slug. */
	const PAGE = 'framework-embed';

	/** Hooks the settings page into WordPress. */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FRAMEWORK_EMBED_FILE ), array( self::class, 'action_links' ) );
	}

	/** Registers the hosts option with its sanitizer. */
	public static function register_setting(): void {
		register_setting(
			self::PAGE,
			Framework_Embed_Plugin::HOSTS_OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitize_hosts' ),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::PAGE,
			Framework_Embed_Stored::TAKEDOWN_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
				'show_in_rest'      => false,
			)
		);
	}

	/** Adds Settings → Framework embeds. */
	public static function add_page(): void {
		add_options_page(
			__( 'Framework embeds', 'framework-embed' ),
			__( 'Framework embeds', 'framework-embed' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 */
	public static function action_links( $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'framework-embed' ) . '</a>' );

		return $links;
	}

	/**
	 * Normalizes one host entry, or returns null when UrlMatcher would not accept it.
	 * A bare host name allows https on the default port. An origin such as
	 * http://localhost:8000 allows exactly that scheme, host, and port.
	 *
	 * @param string $entry Host name or origin.
	 */
	public static function normalize_host( string $entry ): ?string {
		$entry = strtolower( trim( $entry ) );
		if ( '' === $entry ) {
			return null;
		}
		$bare   = ! str_contains( $entry, '://' );
		$origin = ( new UrlMatcher( array( $entry ) ) )->allowedOrigin( ( $bare ? 'https://' . $entry : rtrim( $entry, '/' ) ) . '/' );
		if ( null === $origin ) {
			return null;
		}

		// https on the default port is the bare host name.
		$host = substr( $origin, strlen( 'https://' ) );

		return str_starts_with( $origin, 'https://' ) && ! str_contains( $host, ':' ) ? $host : $origin;
	}

	/**
	 * Sanitizes the submitted hosts: one per line, invalid entries reported and dropped.
	 *
	 * @param mixed $value Textarea string or array.
	 * @return list<string>
	 */
	public static function sanitize_hosts( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', (string) $value );
		$hosts = array();
		$bad   = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_string( $line ) || '' === trim( $line ) ) {
				continue;
			}
			$host = self::normalize_host( $line );
			if ( null === $host ) {
				$bad[] = trim( $line );
			} elseif ( ! in_array( $host, UrlMatcher::DEFAULT_HOSTS, true ) ) {
				$hosts[] = $host;
			}
		}
		if ( $bad && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				Framework_Embed_Plugin::HOSTS_OPTION,
				'framework_embed_invalid_hosts',
				sprintf(
					/* translators: %s: comma-separated list of rejected entries. */
					__( 'Not saved: %s. Enter a host name such as framework.example.org or an origin such as http://localhost:8000. IP addresses and paths are not allowed.', 'framework-embed' ),
					implode( ', ', $bad )
				)
			);
		}

		return array_values( array_unique( $hosts ) );
	}

	/** Renders the settings page. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hosts = Framework_Embed_Plugin::instance()->extra_hosts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Framework embeds', 'framework-embed' ); ?></h1>
			<p><?php esc_html_e( 'Artifacts from framework.pub are enabled by default. Add self-hosted Framework Cloud servers below to allow embeds from those hosts.', 'framework-embed' ); ?></p>
			<p><?php esc_html_e( 'The Framework artifact block saves a copy of the artifact with the post. Pasted URLs and the shortcode use a background cache and display a link until the first fetch completes.', 'framework-embed' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="framework-embed-hosts"><?php esc_html_e( 'Extra hosts', 'framework-embed' ); ?></label></th>
						<td>
							<textarea id="framework-embed-hosts" name="<?php echo esc_attr( Framework_Embed_Plugin::HOSTS_OPTION ); ?>" rows="5" cols="50" class="large-text code" aria-describedby="framework-embed-hosts-help"><?php echo esc_textarea( implode( "\n", $hosts ) ); ?></textarea>
							<p class="description" id="framework-embed-hosts-help"><?php esc_html_e( 'Enter one host per line. A host name such as framework.example.org allows HTTPS on the default port. An origin such as http://localhost:8000 allows that exact scheme, host, and port. IP addresses and paths are not allowed.', 'framework-embed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Takedown check', 'framework-embed' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Framework_Embed_Stored::TAKEDOWN_OPTION ); ?>" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( Framework_Embed_Stored::TAKEDOWN_OPTION ); ?>" value="1" <?php checked( Framework_Embed_Stored::takedown_enabled() ); ?>> <?php esc_html_e( 'Remove stored copies when an artifact is deleted or restricted', 'framework-embed' ); ?></label>
							<p class="description"><?php esc_html_e( 'WP-Cron checks stored artifacts on allowed hosts once a day. If the server returns 404 or 410, the stored copy is replaced with a link. Timeouts and server errors leave the stored copy unchanged.', 'framework-embed' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'framework-embed' ) ); ?>
			</form>
		</div>
		<?php
	}
}
