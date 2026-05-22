<?php
/**
 * Abstract base class for all features added by Anker Dev.
 *
 * Each feature is a self-contained module that can be enabled or disabled
 * independently and can register its own settings on the features page.
 *
 * @package Anker_Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Anker_Dev_Feature
 */
abstract class Anker_Dev_Feature {

	/**
	 * Unique feature id (snake_case).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Translated, human-readable feature title.
	 *
	 * @return string
	 */
	abstract public function title();

	/**
	 * Translated, short feature description shown on the features page.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Default settings array for this feature.
	 *
	 * The `enabled` key (0|1) is conventionally used to toggle the feature on or off.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings() {
		return array(
			'enabled' => 1,
		);
	}

	/**
	 * Sanitize the feature's settings slice before persisting.
	 *
	 * @param array<string, mixed> $input Raw input for this feature.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$input              = is_array( $input ) ? $input : array();
		$defaults           = $this->default_settings();
		$output             = $defaults;
		$output['enabled']  = ! empty( $input['enabled'] ) ? 1 : 0;

		return $output;
	}

	/**
	 * Render the feature's settings fields inside the features page card.
	 *
	 * Subclasses should print form inputs whose `name` attributes follow the pattern:
	 *   anker_dev_settings[<feature_id>][<key>]
	 *
	 * @return void
	 */
	public function render_fields() {
		// Default implementation: only the enable toggle, rendered by the admin layer.
	}

	/**
	 * Wire up the feature's runtime hooks. Only called when the feature is enabled.
	 *
	 * @return void
	 */
	abstract public function init_hooks();

	/**
	 * Called on plugin activation. Use to seed defaults / schedule recurring jobs.
	 *
	 * @return void
	 */
	public function on_activate() {}

	/**
	 * Called on plugin deactivation. Use to clean up scheduled jobs.
	 *
	 * @return void
	 */
	public function on_deactivate() {}

	/**
	 * Helper: is this feature currently enabled in settings?
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 1 === (int) Anker_Dev_Settings::get( $this->id(), 'enabled', 1 );
	}

	/**
	 * Helper: get a single setting for this feature.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		return Anker_Dev_Settings::get( $this->id(), $key, $default );
	}

	/**
	 * Build the HTML `name` attribute for a field belonging to this feature.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public function field_name( $key ) {
		return sprintf( '%s[%s][%s]', Anker_Dev_Settings::OPTION_NAME, $this->id(), $key );
	}

	/**
	 * Build the HTML `id` attribute for a field belonging to this feature.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public function field_id( $key ) {
		return sprintf( 'anker_dev_%s_%s', $this->id(), $key );
	}
}
