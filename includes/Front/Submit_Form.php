<?php
/**
 * WPJM front-end submit form integration: a writing assistant panel under the description
 * (write, check wording, suggest type & tags), a live salary reading, and a tags field when the
 * plugin provides the tags taxonomy. Visitor-facing text is neutral (no DEV3 branding).
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Front;

use Dev3\AiWpJobManager\Core3\Api\Ai;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Text\Salary;
use Dev3\AiWpJobManager\Wpjm\Tags;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static hooks.
 */
class Submit_Form {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ), 15 );
		add_action( 'submit_job_form_start', array( __CLASS__, 'enqueue' ) );
		add_action( 'submit_job_form_job_fields_end', array( __CLASS__, 'panel' ) );
		add_filter( 'submit_job_form_fields', array( __CLASS__, 'fields' ), 20 );
		add_filter( 'submit_job_form_fields_get_job_data', array( __CLASS__, 'fill_tags' ), 10, 2 );
		add_action( 'job_manager_update_job_data', array( __CLASS__, 'save' ), 20, 2 );
	}

	/**
	 * Whether any assistant feature is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return Settings::get( 'submit_writer', true ) || Settings::get( 'submit_inclusive', true ) || Settings::get( 'submit_suggest', true ) || Settings::get( 'salary_hint', true );
	}

	/**
	 * Registers the front-end script and style.
	 */
	public static function register_assets() {
		$asset_file = DEV3JM_PATH . 'build/frontend.asset.php';
		if ( ! is_readable( $asset_file ) || wp_script_is( 'dev3jm-frontend', 'registered' ) ) {
			return;
		}
		$asset = include $asset_file;
		wp_register_script( 'dev3jm-frontend', DEV3JM_URL . 'build/frontend.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'dev3jm-frontend', 'dev3-ai-wp-job-manager', DEV3JM_PATH . 'languages' );
		if ( is_readable( DEV3JM_PATH . 'build/frontend.css' ) ) {
			wp_register_style( 'dev3jm-frontend', DEV3JM_URL . 'build/frontend.css', array(), $asset['version'] );
		}
	}

	/**
	 * Boot data for the panel (no secrets, no nonce: endpoints are public + rate limited).
	 *
	 * @return array
	 */
	public static function config() {
		$status = Ai::status();
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'dev3jm/v1/public/' ) ),
			'writer'    => (bool) Settings::get( 'submit_writer', true ),
			'inclusive' => (bool) Settings::get( 'submit_inclusive', true ),
			'suggest'   => (bool) Settings::get( 'submit_suggest', true ),
			'salary'    => (bool) Settings::get( 'salary_hint', true ),
			'ai'        => ! empty( $status['enabled'] ) && ! empty( $status['public'] ) && ! empty( $status['key_set'] ) && empty( $status['cap_reached'] ),
			'tone'      => (string) Settings::get( 'writer_tone', 'professional' ),
			'currency'  => Settings::currency(),
			'tags'      => taxonomy_exists( Wpjm::TAX_TAG ),
		);
	}

	/**
	 * Enqueues on the submit form.
	 */
	public static function enqueue() {
		if ( ! self::enabled() ) {
			return;
		}
		self::register_assets();
		wp_enqueue_script( 'dev3jm-frontend' );
		wp_add_inline_script( 'dev3jm-frontend', 'window.dev3jmSubmit = ' . wp_json_encode( self::config() ) . ';', 'before' );
		if ( wp_style_is( 'dev3jm-frontend', 'registered' ) ) {
			wp_enqueue_style( 'dev3jm-frontend' );
		}
	}

	/**
	 * Panel mount (moved under the description field by the script; works without JS as nothing).
	 */
	public static function panel() {
		if ( ! self::enabled() ) {
			return;
		}
		echo '<div id="dev3jm-assistant" class="dev3jm-assist" hidden></div>';
	}

	/**
	 * Adds a tags field when we provide the taxonomy (the Job Tags add-on adds its own).
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function fields( $fields ) {
		if ( ! Tags::ours() || isset( $fields['job']['job_tags'] ) ) {
			return $fields;
		}
		$fields['job']['job_tags'] = array(
			'label'       => __( 'Tags', 'dev3-ai-wp-job-manager' ),
			'description' => __( 'Comma-separated skills, e.g. "PHP, WordPress, Customer support".', 'dev3-ai-wp-job-manager' ),
			'type'        => 'text',
			'required'    => false,
			'placeholder' => __( 'e.g. PHP, WordPress', 'dev3-ai-wp-job-manager' ),
			'priority'    => 5.5,
		);
		return $fields;
	}

	/**
	 * Fills the tags field when an employer edits a listing.
	 *
	 * @param array    $fields Fields.
	 * @param \WP_Post $job    Listing.
	 * @return array
	 */
	public static function fill_tags( $fields, $job ) {
		if ( isset( $fields['job']['job_tags'] ) && Tags::ours() ) {
			$names                              = wp_get_object_terms( $job->ID, Wpjm::TAX_TAG, array( 'fields' => 'names' ) );
			$fields['job']['job_tags']['value'] = is_array( $names ) ? implode( ', ', $names ) : '';
		}
		return $fields;
	}

	/**
	 * Saves tags and (optionally) the normalised salary after a front-end submission.
	 *
	 * @param int   $job_id Listing.
	 * @param array $values Form values.
	 */
	public static function save( $job_id, $values ) {
		if ( Tags::ours() && isset( $values['job']['job_tags'] ) ) {
			$names = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $values['job']['job_tags'] ) ) ) );
			wp_set_object_terms( $job_id, Wpjm::resolve_terms( Wpjm::TAX_TAG, array_slice( $names, 0, 12 ), true ), Wpjm::TAX_TAG );
		}
		if ( Settings::get( 'auto_normalise', false ) ) {
			$p = Salary::parse( (string) get_post_meta( $job_id, '_job_salary', true ), (string) get_post_meta( $job_id, '_job_salary_currency', true ), (string) get_post_meta( $job_id, '_job_salary_unit', true ) );
			if ( $p['ok'] && ! $p['guessed_unit'] ) {
				Salary::write( $job_id, $p );
			}
		}
		\Dev3\AiWpJobManager\Quality\Quality::refresh( (int) $job_id );
	}
}
