<?php
/**
 * Plugin Name:       DEV3 AI Job Listings Assistant for WP Job Manager
 * Plugin URI:        https://dev3.tech/item/dev3-ai-wp-job-manager
 * Description:       AI job descriptions, inclusive-language checks, salary normalisation, better JobPosting schema and candidate matching for WP Job Manager.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  wp-job-manager
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-wp-job-manager
 * Domain Path:       /languages
 *
 * @package Dev3\AiWpJobManager
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3JM_VERSION', '1.0.0' );
define( 'DEV3JM_FILE', __FILE__ );
define( 'DEV3JM_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3JM_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3JM_SLUG', 'dev3-ai-wp-job-manager' );
define( 'DEV3JM_NAME', 'DEV3 AI Job Listings Assistant for WP Job Manager' );
define( 'DEV3JM_MIN_PHP', '7.4' );
define( 'DEV3JM_MIN_WP', '6.0' );
define( 'DEV3JM_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3JM_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiWpJobManager\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3JM_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiWpJobManager\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiWpJobManager\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiWpJobManager\Plugin', 'instance' ) );
