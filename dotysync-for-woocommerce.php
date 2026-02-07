<?php
/**
 * Plugin Name: DotySync for WooCommerce
 * Description: Automatically sync products from Dotypos POS (API V2) to WooCommerce.
 * Version: 3.1.0
 * Author: Tamim Hasan
 * Author URI: https://wa.me/+8801639675616
 * Text Domain: dotysync-for-woocommerce
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'DOTYSYNC_VERSION', '3.1.0' );
define( 'DOTYSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOTYSYNC_URL', plugin_dir_url( __FILE__ ) );

// Autoloader or Includes
require_once DOTYSYNC_DIR . 'includes/class-dotysync-security.php';
require_once DOTYSYNC_DIR . 'includes/class-dotysync-api.php';
require_once DOTYSYNC_DIR . 'includes/class-dotysync-sync.php'; 
require_once DOTYSYNC_DIR . 'includes/class-dotysync-cron.php'; 
require_once DOTYSYNC_DIR . 'includes/class-dotysync-webhook.php'; // New Webhook Class

// Admin UI
if ( is_admin() ) {
    require_once DOTYSYNC_DIR . 'admin/settings-page.php';
}

/**
 * Main Plugin Class
 */
class DotySync {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Initialization hooks
        new DotySync_Webhook();
	}
    
    /**
     * Get API Instance
     */
    public function get_api() {
        return new DotySync_Api();
    }

    /**
     * Get Sync Instance
     */
    public function get_sync() {
        return new DotySync_Sync();
    }
}

// Initialize
function dotysync_init() {
	return DotySync::get_instance();
}
add_action( 'plugins_loaded', 'dotysync_init' );
