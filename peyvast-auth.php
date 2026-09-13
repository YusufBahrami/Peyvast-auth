<?php
/**
 * Plugin Name:       Peyvast Auth
 * Plugin URI:        https://github.com/yusufbahrami/peyvast-auth
 * Description:       Secure phone and email OTP authentication with password login, registration and Iran provider integrations.
 * Version:           1.0.1
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Tested up to:      7.1
 * Author:    	  	  Yusuf Bahrami, Peyvast Agency
 * Author URI: 		  https://peyvastagency.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       peyvast-auth
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PEYVAST_AUTH_VERSION', '1.0.1' );
define( 'PEYVAST_AUTH_TEXT_DOMAIN', 'peyvast-auth' );
define( 'PEYVAST_AUTH_FILE', __FILE__ );
define( 'PEYVAST_AUTH_DIR', plugin_dir_path( __FILE__ ) );
define( 'PEYVAST_AUTH_URL', plugin_dir_url( __FILE__ ) );
define( 'PEYVAST_AUTH_OPTION', 'peyvast_auth_settings' );
define( 'PEYVAST_AUTH_OTP_TABLE', $GLOBALS['wpdb']->prefix . 'peyvast_auth_otp_challenges' );
define( 'PEYVAST_AUTH_PHONE_IDENTITY_TABLE', $GLOBALS['wpdb']->prefix . 'peyvast_auth_phone_identity' );
define( 'PEYVAST_AUTH_LOG_TABLE', $GLOBALS['wpdb']->prefix . 'peyvast_auth_logs' );
define( 'PEYVAST_AUTH_SECURITY_TABLE', $GLOBALS['wpdb']->prefix . 'peyvast_auth_security_state' );
define( 'PEYVAST_AUTH_OTP_DELIVERY_TABLE', $GLOBALS['wpdb']->prefix . 'peyvast_auth_otp_deliveries' );

require_once PEYVAST_AUTH_DIR . 'lib/action-scheduler/action-scheduler.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Peyvast\\Auth\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = PEYVAST_AUTH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( PEYVAST_AUTH_FILE, array( 'Peyvast\\Auth\\Infrastructure\\WordPress\\Installer', 'activate' ) );
register_deactivation_hook( PEYVAST_AUTH_FILE, array( 'Peyvast\\Auth\\Infrastructure\\WordPress\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			PEYVAST_AUTH_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( PEYVAST_AUTH_FILE ) ) . '/languages'
		);

		Peyvast\Auth\Core\Plugin::boot();
	},
	20
);
