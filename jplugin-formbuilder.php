<?php
/**
 * Plugin Name:       jPlugin Formbuilder
 * Plugin URI:        https://example.com/jplugin-formbuilder
 * Description:       Secure, theme-adaptive forms with a visual builder, private uploads, Cloudflare Turnstile, and a standalone submission inbox.
 * Version:           1.0.6
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Jirath
 * Text Domain:       jplugin-formbuilder
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin_data = function_exists( 'get_file_data' ) ? get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' ) : array( 'Version' => '1.0.6' );

define( 'JFB_VERSION', (string) ( $plugin_data['Version'] ?? '1.0.6' ) );
define( 'JFB_FILE', __FILE__ );
define( 'JFB_PATH', plugin_dir_path( __FILE__ ) );
define( 'JFB_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'JFB\\' ) ) {
			return;
		}

		$relative = strtolower( str_replace( array( 'JFB\\', '\\', '_' ), array( '', '-', '-' ), $class ) );
		$file     = JFB_PATH . 'includes/class-' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'JFB\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JFB\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			return;
		}

		( new JFB\Plugin() )->run();
	}
);
