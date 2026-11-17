<?php
/**
 * Minimal WordPress stand-ins for unit tests of the plugin's glue code.
 * The end-to-end run (tests/e2e/run.sh) exercises real WordPress.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('FRAMEWORK_EMBED_VERSION', 'test');
define('FRAMEWORK_EMBED_FILE', dirname(__DIR__, 2) . '/framework-embed.php');
define('FRAMEWORK_EMBED_DIR', dirname(__DIR__, 2));

require dirname(__DIR__, 3) . '/php/vendor/autoload.php';

/** Test state the stubs read and write. */
final class WP
{
    public static array $options = [];
    public static array $transients = [];
    public static array $filters = [];
    public static array $enqueued = [];
    public static array $errors = [];
    public static array $requests = [];
    public static mixed $remote = null;
    public static bool $canEdit = false;
    public static array $scheduled = [];

    public static function reset(): void
    {
        self::$options = self::$transients = self::$filters = self::$enqueued = self::$errors = self::$requests = self::$scheduled = [];
        self::$remote = null;
        self::$canEdit = false;
    }
}

final class WP_Error
{
    public function __construct(public string $code = '', private string $message = '', public mixed $data = null) {}

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function get_option($name, $default = false) { return WP::$options[$name] ?? $default; }
function get_transient($key) { return WP::$transients[$key][0] ?? false; }
function set_transient($key, $value, $ttl) { WP::$transients[$key] = [$value, $ttl]; return true; }
function delete_transient($key) { unset(WP::$transients[$key]); return true; }
function add_filter($name, $callback, $priority = 10, $args = 1) { WP::$filters[$name][] = $callback; return true; }
function remove_filter($name, $callback, $priority = 10) { WP::$filters[$name] = array_values(array_filter(WP::$filters[$name] ?? [], fn ($c) => $c !== $callback)); return true; }
function add_action($name, $callback, $priority = 10, $args = 1) { return add_filter($name, $callback, $priority, $args); }
function apply_filters($name, $value, ...$args) { foreach (WP::$filters[$name] ?? [] as $callback) { $value = $callback($value, ...$args); } return $value; }
function has_filter($name, $callback = false) { return false; }
function current_filter() { return ''; }
function current_user_can($cap) { return WP::$canEdit; }
function wp_enqueue_style($handle) { WP::$enqueued[] = "style:{$handle}"; }
function wp_enqueue_script($handle) { WP::$enqueued[] = "script:{$handle}"; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '') { return 'https://site.example' . $path; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_safe_remote_get($url, $args) { WP::$requests[] = ['url' => $url, 'args' => $args, 'external' => apply_filters('http_request_host_is_external', false, parse_url($url, PHP_URL_HOST), $url), 'ports' => apply_filters('http_allowed_safe_ports', [80, 443, 8080], parse_url($url, PHP_URL_HOST), $url)]; return WP::$remote; }
function wp_remote_retrieve_response_code($answer) { return $answer['response']['code']; }
function wp_remote_retrieve_headers($answer) { return $answer['headers']; }
function wp_remote_retrieve_body($answer) { return $answer['body']; }
function esc_url($url, $protocols = null) { return preg_match('#^https?://#i', $url) ? htmlspecialchars($url, ENT_QUOTES) : ''; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function __($text, $domain = 'default') { return $text; }
function shortcode_atts($pairs, $atts, $shortcode = '') { return array_merge($pairs, array_intersect_key((array) $atts, $pairs)); }
function add_settings_error($setting, $code, $message) { WP::$errors[] = $message; }
function get_block_wrapper_attributes() { return 'class="wp-block-framework-artifact"'; }
function wp_schedule_single_event($time, $hook, $args = []) { WP::$scheduled[] = [$hook, $args]; return true; }

require dirname(__DIR__, 3) . '/testing/FixtureServer.php';
require dirname(__DIR__, 2) . '/includes/class-plugin.php';
require dirname(__DIR__, 2) . '/includes/class-live.php';
require dirname(__DIR__, 2) . '/includes/class-stored.php';
require dirname(__DIR__, 2) . '/includes/class-settings.php';
