<?php
/**
 * Main plugin bootstrap class for Anker Dev.
 *
 * @package Anker_Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Anker_Dev_Plugin
 */
class Anker_Dev_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Anker_Dev_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Registered features, keyed by feature id.
	 *
	 * @var array<string, Anker_Dev_Feature>
	 */
	private $features = array();

	/**
	 * Admin layer.
	 *
	 * @var Anker_Dev_Admin|null
	 */
	private $admin = null;

	/**
	 * Get the singleton instance, booting the plugin on first call.
	 *
	 * @return Anker_Dev_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin: load i18n, register features, init admin and feature hooks.
	 */
	private function boot() {
		// Always load translations.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Register built-in features. Third parties can hook in too.
		$this->register_default_features();

		// Persistence hooks must always run, regardless of admin context, so
		// `anker_dev_settings_updated` fires whenever the option changes.
		Anker_Dev_Settings::attach_persistence_hooks();

		/**
		 * Fires after the default features have been registered, allowing third
		 * parties to add their own Anker_Dev_Feature subclasses via:
		 *
		 *   add_action( 'anker_dev_register_features', function( $plugin ) {
		 *       $plugin->register_feature( new My_Feature() );
		 *   } );
		 *
		 * @param Anker_Dev_Plugin $plugin
		 */
		do_action( 'anker_dev_register_features', $this );

		// Show an admin notice and bail out if WooCommerce is not active.
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_missing_notice' ) );
			return;
		}

		// Wire up runtime hooks for every registered feature. Each feature's
		// handlers self-check `is_enabled()` so that any previously-scheduled
		// Action Scheduler actions don't error out when the feature is disabled.
		foreach ( $this->features as $feature ) {
			$feature->init_hooks();
		}

		// Boot admin UI.
		if ( is_admin() ) {
			$this->admin = new Anker_Dev_Admin();
			$this->admin->init();
		}
	}

	/**
	 * Register the built-in features.
	 */
	private function register_default_features() {
		$this->register_feature( new Anker_Dev_Cancel_Pending_Orders() );
	}

	/**
	 * Register a feature with the plugin.
	 *
	 * @param Anker_Dev_Feature $feature Feature instance.
	 */
	public function register_feature( Anker_Dev_Feature $feature ) {
		$this->features[ $feature->id() ] = $feature;
	}

	/**
	 * Get all registered features.
	 *
	 * @return array<string, Anker_Dev_Feature>
	 */
	public function get_features() {
		return $this->features;
	}

	/**
	 * Get a single feature by id.
	 *
	 * @param string $id Feature id.
	 * @return Anker_Dev_Feature|null
	 */
	public function get_feature( $id ) {
		return isset( $this->features[ $id ] ) ? $this->features[ $id ] : null;
	}

	/**
	 * Load the plugin's text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'anker-dev', false, dirname( ANKER_DEV_BASENAME ) . '/languages' );
	}

	/**
	 * Whether WooCommerce is active and loaded.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Render an admin notice when WooCommerce is missing.
	 */
	public function render_woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo esc_html__( 'افزونهٔ Anker Dev برای کار به ووکامرس نیاز دارد. لطفاً ابتدا ووکامرس را نصب و فعال کنید.', 'anker-dev' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Plugin activation: seed defaults and let features schedule their jobs.
	 */
	public static function on_activate() {
		// Make sure features are registered (boot path runs only on plugins_loaded).
		$plugin = self::instance();

		// Seed default options if the option does not exist yet.
		if ( false === get_option( Anker_Dev_Settings::OPTION_NAME, false ) ) {
			update_option( Anker_Dev_Settings::OPTION_NAME, Anker_Dev_Settings::defaults(), false );
		}

		foreach ( $plugin->get_features() as $feature ) {
			$feature->on_activate();
		}
	}

	/**
	 * Plugin deactivation: ask each feature to clean up.
	 */
	public static function on_deactivate() {
		$plugin = self::instance();
		foreach ( $plugin->get_features() as $feature ) {
			$feature->on_deactivate();
		}
	}
}
