<?php
/**
 * Test dependencies: the real WP Job Manager (installed by wp-env from wordpress.org), its options
 * switched on, a Resume Manager stub (paid add-on: documented data model only), a global HTTP guard
 * and shared helpers.
 *
 * @package Dev3\AiWpJobManager
 */

tests_add_filter(
	'dev3jm_test_dependencies',
	static function ( $deps ) {
		$deps[] = 'wp-job-manager/wp-job-manager.php';
		return $deps;
	}
);

// WPJM features used by the add-on.
foreach ( array( 'job_manager_enable_types', 'job_manager_enable_categories', 'job_manager_enable_salary', 'job_manager_enable_salary_currency', 'job_manager_enable_salary_unit', 'job_manager_enable_remote_position', 'job_manager_multi_job_type' ) as $dev3jm_opt ) {
	tests_add_filter( 'pre_option_' . $dev3jm_opt, static fn() => '1' );
}

// WPJM default job types (Full Time, Part Time…) once taxonomies exist.
tests_add_filter(
	'init',
	static function () {
		if ( class_exists( 'WP_Job_Manager_Install' ) && ! get_term_by( 'slug', 'full-time', 'job_listing_type' ) ) {
			delete_option( 'job_manager_installed_terms' );
			WP_Job_Manager_Install::install();
		}
	},
	99
);

// Resume Manager stub: registers its post type and taxonomies with the same names.
tests_add_filter(
	'init',
	static function () {
		if ( ! post_type_exists( 'resume' ) ) {
			register_post_type(
				'resume',
				array(
					'public'   => false,
					'show_ui'  => true,
					'supports' => array( 'title', 'editor' ),
				)
			);
			register_post_status( 'hidden', array( 'public' => false ) );
			register_taxonomy( 'resume_skill', 'resume' );
			register_taxonomy( 'resume_category', 'resume' );
		}
	},
	5
);

tests_add_filter( 'dev3jm_retry_delay_ms', '__return_zero' );
tests_add_filter( 'dev3jm_jobs_use_action_scheduler', '__return_false' );
tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'wordpress@example.org';
	}
);

require_once __DIR__ . '/helpers.php';

// No real network in tests: anything not answered by a test's own mock is refused.
tests_add_filter( 'pre_http_request', array( 'Dev3jm_Http', 'handle' ), 99, 3 );
