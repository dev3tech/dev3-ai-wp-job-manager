<?php
/**
 * Job tags. Uses the `job_listing_tag` taxonomy of the WPJM Job Tags add-on when it is active;
 * otherwise registers a lightweight taxonomy with the same name (so data carries over if the
 * add-on is installed later) and shows tags on the single job page.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Wpjm;

use Dev3\AiWpJobManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Tags {

	/**
	 * Whether we registered the taxonomy.
	 *
	 * @var bool
	 */
	private static $ours = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		add_action( 'single_job_listing_meta_end', array( __CLASS__, 'render' ) );
	}

	/**
	 * Whether the taxonomy is ours.
	 *
	 * @return bool
	 */
	public static function ours() {
		return self::$ours;
	}

	/**
	 * Registers the fallback taxonomy.
	 */
	public static function register() {
		if ( taxonomy_exists( Wpjm::TAX_TAG ) || class_exists( 'WP_Job_Manager_Job_Tags' ) || ! post_type_exists( Wpjm::PT ) || ! Settings::get( 'register_tags', true ) ) {
			return;
		}
		register_taxonomy(
			Wpjm::TAX_TAG,
			array( Wpjm::PT ),
			array(
				'labels'            => array(
					'name'          => __( 'Job tags', 'dev3-ai-wp-job-manager' ),
					'singular_name' => __( 'Job tag', 'dev3-ai-wp-job-manager' ),
					'search_items'  => __( 'Search job tags', 'dev3-ai-wp-job-manager' ),
					'all_items'     => __( 'All job tags', 'dev3-ai-wp-job-manager' ),
					'edit_item'     => __( 'Edit job tag', 'dev3-ai-wp-job-manager' ),
					'add_new_item'  => __( 'Add new job tag', 'dev3-ai-wp-job-manager' ),
					'menu_name'     => __( 'Job tags', 'dev3-ai-wp-job-manager' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'job-tag' ),
				'capabilities'      => array(
					'manage_terms' => 'manage_job_listing_terms',
					'edit_terms'   => 'edit_job_listing_terms',
					'delete_terms' => 'delete_job_listing_terms',
					'assign_terms' => 'assign_job_listing_terms',
				),
			)
		);
		self::$ours = true;
	}

	/**
	 * Tags line on the single job page (only for our taxonomy; the add-on renders its own).
	 */
	public static function render() {
		if ( ! self::$ours ) {
			return;
		}
		$terms = get_the_terms( get_the_ID(), Wpjm::TAX_TAG );
		if ( ! is_array( $terms ) || ! $terms ) {
			return;
		}
		$links = array();
		foreach ( $terms as $t ) {
			$url     = get_term_link( $t );
			$links[] = is_wp_error( $url ) ? esc_html( $t->name ) : '<a href="' . esc_url( $url ) . '">' . esc_html( $t->name ) . '</a>';
		}
		echo '<li class="dev3jm-tags"><span class="screen-reader-text">' . esc_html__( 'Tags:', 'dev3-ai-wp-job-manager' ) . '</span> ' . wp_kses_post( implode( ', ', $links ) ) . '</li>';
	}
}
