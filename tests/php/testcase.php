<?php
/**
 * Base test case (loaded by each test file after the WordPress test suite).
 *
 * @package Dev3\AiWpJobManager
 */

/**
 * Base case: fresh tables, settings and mocks for every test.
 */
abstract class Dev3jm_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		\Dev3\AiWpJobManager\Core3\Core\Schema::install( DEV3JM_DB_VERSION );
		global $wpdb;
		foreach ( \Dev3\AiWpJobManager\Data\Tables::NAMES as $t ) {
			$wpdb->query( 'DELETE FROM ' . \Dev3\AiWpJobManager\Data\Tables::name( $t ) ); // phpcs:ignore
		}
		update_option( \Dev3\AiWpJobManager\Core3\Core\Settings_Store::OPTION, array() );
		\Dev3\AiWpJobManager\Core3\Core\Settings_Store::flush();
		\Dev3\AiWpJobManager\Core3\Core\Cache::reset_memo();
		\Dev3\AiWpJobManager\Core3\Api\Ai::$client = null;
		\Dev3\AiWpJobManager\Core3\Data\Usage_Repository::flush();
		\Dev3\AiWpJobManager\Ai\Prompts_Library::register();
		Dev3jm_Http::reset();
		add_filter( 'dev3jm_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3jm_jobs_use_action_scheduler', '__return_false' );
		// WPJM's default job types (the suite deletes terms between test classes).
		foreach ( array(
			'Full Time'  => 'FULL_TIME',
			'Part Time'  => 'PART_TIME',
			'Temporary'  => 'TEMPORARY',
			'Freelance'  => 'CONTRACTOR',
			'Internship' => 'INTERN',
		) as $name => $type ) {
			if ( ! get_term_by( 'slug', sanitize_title( $name ), 'job_listing_type' ) ) {
				$t = wp_insert_term( $name, 'job_listing_type' );
				if ( is_array( $t ) ) {
					add_term_meta( $t['term_id'], 'employment_type', $type );
				}
			}
		}
	}

	public function tear_down() {
		Dev3jm_Http::reset();
		parent::tear_down();
	}

	/**
	 * Sets a fake key so AI features run (against the mocked endpoint).
	 */
	protected function with_key() {
		\Dev3\AiWpJobManager\Core3\Core\Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		\Dev3\AiWpJobManager\Core3\Core\Settings_Store::flush();
	}

	/**
	 * Settings patch.
	 *
	 * @param array $patch Patch.
	 */
	protected function settings( array $patch ) {
		\Dev3\AiWpJobManager\Core3\Core\Settings_Store::update( $patch );
		\Dev3\AiWpJobManager\Core3\Core\Settings_Store::flush();
	}

	/**
	 * Admin user (logged in).
	 *
	 * @return int
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * WPJM job listing.
	 *
	 * @param array  $meta    Meta (without leading underscore is fine).
	 * @param array  $post    Post overrides.
	 * @param array  $types   Job type slugs.
	 * @return int
	 */
	protected function listing( array $meta = array(), array $post = array(), array $types = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'job_listing',
					'post_status'  => 'publish',
					'post_title'   => 'Frontend Developer',
					'post_content' => '<p>Build our web app with React and TypeScript. 3+ years of experience.</p>',
				),
				$post
			)
		);
		$meta = array_merge(
			array(
				'_company_name' => 'Acme',
				'_job_location' => 'Berlin, Germany',
			),
			$meta
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		if ( $types ) {
			wp_set_object_terms( $id, $types, 'job_listing_type' );
		}
		return $id;
	}

	/**
	 * Resume (Resume Manager data model).
	 *
	 * @param string $name   Candidate name (post title).
	 * @param array  $meta   Meta.
	 * @param array  $skills Skills.
	 * @param string $content Content.
	 * @return int
	 */
	protected function resume( $name, array $meta = array(), array $skills = array(), $content = '' ) {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'resume',
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => $content,
			)
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		if ( $skills ) {
			wp_set_object_terms( $id, $skills, 'resume_skill' );
		}
		return $id;
	}

	/**
	 * REST request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below dev3jm/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send a nonce.
	 * @param array  $headers Headers.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true, array $headers = array() ) {
		$req = new WP_REST_Request( $method, '/dev3jm/v1/' . $route );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}
}
