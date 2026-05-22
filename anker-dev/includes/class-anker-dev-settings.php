<?php
/**
 * Settings storage for the Anker Dev plugin.
 *
 * Keeps all options in a single `anker_dev_settings` array, organized per feature id.
 *
 * @package Anker_Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Anker_Dev_Settings
 */
class Anker_Dev_Settings {

	/**
	 * Option name in `wp_options`.
	 */
	const OPTION_NAME = 'anker_dev_settings';

	/**
	 * Settings group used by the Settings API.
	 */
	const OPTION_GROUP = 'anker_dev_settings_group';

	/**
	 * Cached settings array.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $cache = null;

	/**
	 * Default settings, merged with whatever is stored in the database.
	 *
	 * Each feature contributes its own defaults via Anker_Dev_Feature::default_settings().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults() {
		$defaults = array();

		foreach ( Anker_Dev_Plugin::instance()->get_features() as $feature ) {
			$defaults[ $feature->id() ] = $feature->default_settings();
		}

		/**
		 * Allow third-parties to modify the default settings.
		 *
		 * @param array $defaults
		 */
		return apply_filters( 'anker_dev_default_settings', $defaults );
	}

	/**
	 * Get all settings (with defaults merged in).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();

		$merged = array();
		foreach ( $defaults as $feature_id => $feature_defaults ) {
			$feature_stored          = isset( $stored[ $feature_id ] ) && is_array( $stored[ $feature_id ] ) ? $stored[ $feature_id ] : array();
			$merged[ $feature_id ]   = array_merge( $feature_defaults, $feature_stored );
		}

		// Preserve any stored data for features not currently registered (forward-compat).
		foreach ( $stored as $feature_id => $feature_stored ) {
			if ( ! isset( $merged[ $feature_id ] ) && is_array( $feature_stored ) ) {
				$merged[ $feature_id ] = $feature_stored;
			}
		}

		self::$cache = $merged;
		return self::$cache;
	}

	/**
	 * Get a single feature's settings.
	 *
	 * @param string $feature_id Feature id.
	 * @return array<string, mixed>
	 */
	public static function for_feature( $feature_id ) {
		$all = self::all();
		return isset( $all[ $feature_id ] ) && is_array( $all[ $feature_id ] ) ? $all[ $feature_id ] : array();
	}

	/**
	 * Get a single value out of a feature's settings.
	 *
	 * @param string $feature_id Feature id.
	 * @param string $key        Setting key.
	 * @param mixed  $default    Fallback value.
	 * @return mixed
	 */
	public static function get( $feature_id, $key, $default = null ) {
		$settings = self::for_feature( $feature_id );
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Reset the in-memory cache. Call after writing the option.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitize the full options array before saving.
	 *
	 * Each feature gets a chance to sanitize its own slice via `sanitize_settings()`.
	 *
	 * @param mixed $input Raw input from the form.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();

		foreach ( Anker_Dev_Plugin::instance()->get_features() as $feature ) {
			$id              = $feature->id();
			$feature_input   = isset( $input[ $id ] ) && is_array( $input[ $id ] ) ? $input[ $id ] : array();
			$output[ $id ]   = $feature->sanitize_settings( $feature_input );
		}

		self::flush_cache();

		/**
		 * After settings are sanitized but before they are stored, allow features
		 * to react (e.g. clear or reschedule background actions).
		 *
		 * @param array $output The sanitized settings array.
		 */
		do_action( 'anker_dev_settings_updated', $output );

		return $output;
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}
}
