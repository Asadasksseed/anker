<?php
/**
 * Admin UI: top-level "Anker Dev" menu and features page.
 *
 * Uses the native WordPress admin design language (`.wrap`, `.card`, `.form-table`)
 * and the Settings API.
 *
 * @package Anker_Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Anker_Dev_Admin
 */
class Anker_Dev_Admin {

	/**
	 * Page slug for the features page.
	 */
	const PAGE_SLUG = 'anker-dev';

	/**
	 * Capability required to manage the plugin.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Hook suffix returned by add_menu_page, used to scope asset loading.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Boot the admin layer.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . ANKER_DEV_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register the top-level admin menu.
	 */
	public function register_menu() {
		$this->hook_suffix = add_menu_page(
			__( 'انکر دِو', 'anker-dev' ),
			__( 'انکر دِو', 'anker-dev' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-plugins',
			58 // Just below WooCommerce (55) and Products (56).
		);

		// Single submenu so the parent label and submenu match.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'ویژگی‌ها', 'anker-dev' ),
			__( 'ویژگی‌ها', 'anker-dev' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option and the Settings API hookup.
	 */
	public function register_settings() {
		Anker_Dev_Settings::register();
	}

	/**
	 * Enqueue our small admin stylesheet on our screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'anker-dev-admin',
			ANKER_DEV_URL . 'assets/css/admin.css',
			array(),
			ANKER_DEV_VERSION
		);
	}

	/**
	 * Add a "تنظیمات" link to the plugin row on the Plugins screen.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string>
	 */
	public function plugin_action_links( $links ) {
		$url       = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$settings  = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'تنظیمات', 'anker-dev' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Render the features / settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$features = Anker_Dev_Plugin::instance()->get_features();
		?>
		<div class="wrap anker-dev-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'انکر دِو', 'anker-dev' ); ?></h1>
			<hr class="wp-header-end" />

			<p class="description anker-dev-intro">
				<?php esc_html_e( 'افزونهٔ Anker Dev مجموعه‌ای از ویژگی‌های توسعه‌دهنده برای ووکامرس را در اختیار شما قرار می‌دهد. در این صفحه می‌توانید هر ویژگی را به‌صورت مستقل فعال یا غیرفعال کنید و تنظیمات آن را تغییر دهید.', 'anker-dev' ); ?>
			</p>

			<?php settings_errors( Anker_Dev_Settings::OPTION_NAME ); ?>

			<form method="post" action="options.php" class="anker-dev-form">
				<?php settings_fields( Anker_Dev_Settings::OPTION_GROUP ); ?>

				<?php if ( empty( $features ) ) : ?>
					<div class="card anker-dev-feature-card">
						<p><?php esc_html_e( 'در حال حاضر هیچ ویژگی‌ای ثبت نشده است.', 'anker-dev' ); ?></p>
					</div>
				<?php else : ?>
					<?php foreach ( $features as $feature ) : ?>
						<?php $this->render_feature_card( $feature ); ?>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php submit_button( __( 'ذخیرهٔ تغییرات', 'anker-dev' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single feature card.
	 *
	 * @param Anker_Dev_Feature $feature Feature instance.
	 */
	protected function render_feature_card( Anker_Dev_Feature $feature ) {
		$enabled    = $feature->is_enabled();
		$enabled_id = $feature->field_id( 'enabled' );
		?>
		<div class="card anker-dev-feature-card" id="<?php echo esc_attr( 'anker-dev-feature-' . $feature->id() ); ?>">
			<div class="anker-dev-feature-card__header">
				<h2 class="title"><?php echo esc_html( $feature->title() ); ?></h2>
				<span class="anker-dev-status anker-dev-status--<?php echo $enabled ? 'on' : 'off'; ?>">
					<?php echo $enabled ? esc_html__( 'فعال', 'anker-dev' ) : esc_html__( 'غیرفعال', 'anker-dev' ); ?>
				</span>
			</div>

			<p class="description"><?php echo esc_html( $feature->description() ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $enabled_id ); ?>">
								<?php esc_html_e( 'وضعیت ویژگی', 'anker-dev' ); ?>
							</label>
						</th>
						<td>
							<label class="anker-dev-toggle">
								<input
									type="hidden"
									name="<?php echo esc_attr( $feature->field_name( 'enabled' ) ); ?>"
									value="0"
								/>
								<input
									type="checkbox"
									id="<?php echo esc_attr( $enabled_id ); ?>"
									name="<?php echo esc_attr( $feature->field_name( 'enabled' ) ); ?>"
									value="1"
									<?php checked( $enabled ); ?>
								/>
								<span><?php esc_html_e( 'فعال‌سازی این ویژگی', 'anker-dev' ); ?></span>
							</label>
						</td>
					</tr>
					<?php $feature->render_fields(); ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
