<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager;

use Dev3\AiWpJobManager\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3jm_settings_defaults / dev3jm_settings_sanitize.
 */
class Settings {

	const TONES = array( 'professional', 'friendly', 'startup', 'formal' );

	const FLAGS = array( 'submit_writer', 'submit_inclusive', 'submit_suggest', 'salary_hint', 'schema_enhance', 'register_tags', 'quality_column', 'match_ai', 'auto_normalise' );

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'submit_writer'    => true,
				'submit_inclusive' => true,
				'submit_suggest'   => true,
				'salary_hint'      => true,
				'schema_enhance'   => true,
				'register_tags'    => true,
				'quality_column'   => true,
				'match_ai'         => true,
				'auto_normalise'   => false,
				'writer_tone'      => 'professional',
				'default_currency' => 'USD',
				'remote_region'    => '',
				'public_limit'     => 10,
				'bulk_chunk'       => 3,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( self::FLAGS as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['writer_tone'] ) && in_array( $input['writer_tone'], self::TONES, true ) ) {
			$out['writer_tone'] = $input['writer_tone'];
		}
		if ( isset( $input['default_currency'] ) ) {
			$cur = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $input['default_currency'] ) );
			if ( 3 === strlen( $cur ) ) {
				$out['default_currency'] = $cur;
			}
		}
		if ( isset( $input['remote_region'] ) ) {
			$out['remote_region'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['remote_region'] ) ), 0, 80 );
		}
		if ( isset( $input['public_limit'] ) ) {
			$out['public_limit'] = min( 120, max( 1, (int) $input['public_limit'] ) );
		}
		if ( isset( $input['bulk_chunk'] ) ) {
			$out['bulk_chunk'] = min( 20, max( 1, (int) $input['bulk_chunk'] ) );
		}
		return $out;
	}

	/**
	 * Default salary currency: WPJM's own setting first, then ours.
	 *
	 * @return string
	 */
	public static function currency() {
		$wpjm = strtoupper( (string) get_option( 'job_manager_default_salary_currency', '' ) );
		return 3 === strlen( $wpjm ) ? $wpjm : (string) self::get( 'default_currency', 'USD' );
	}
}
