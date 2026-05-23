<?php
/**
 * Plugin Name: Anker Dev
 * Plugin URI:  https://shabake.dev/
 * Description: مجموعه‌ای از ویژگی‌های توسعه‌دهنده برای ووکامرس. در نسخهٔ فعلی شامل لغو خودکار سفارش‌های پرداخت‌نشده پس از مدت زمان مشخص است.
 * Version:     1.1.2
 * Author:      shabake.dev
 * Author URI:  https://shabake.dev/
 * Text Domain: anker-dev
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.7
 *
 * @package Anker_Dev
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'ANKER_DEV_VERSION' ) ) {
	return;
}

define( 'ANKER_DEV_VERSION', '1.1.2' );
define( 'ANKER_DEV_FILE', __FILE__ );
define( 'ANKER_DEV_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANKER_DEV_URL', plugin_dir_url( __FILE__ ) );
define( 'ANKER_DEV_BASENAME', plugin_basename( __FILE__ ) );

require_once ANKER_DEV_PATH . 'includes/class-anker-dev-settings.php';
require_once ANKER_DEV_PATH . 'includes/abstract-anker-dev-feature.php';
require_once ANKER_DEV_PATH . 'includes/features/class-anker-dev-cancel-pending-orders.php';
require_once ANKER_DEV_PATH . 'includes/class-anker-dev-admin.php';
require_once ANKER_DEV_PATH . 'includes/class-anker-dev-plugin.php';

/**
 * Declare compatibility with HPOS (custom order tables) and Cart/Checkout blocks.
 * Must be done inside the `before_woocommerce_init` hook.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ANKER_DEV_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ANKER_DEV_FILE, true );
		}
	}
);

// Boot the plugin once all plugins are loaded so we can verify WooCommerce is active.
add_action( 'plugins_loaded', array( 'Anker_Dev_Plugin', 'instance' ), 20 );

// Activation / deactivation life-cycle.
register_activation_hook( __FILE__, array( 'Anker_Dev_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Anker_Dev_Plugin', 'on_deactivate' ) );
