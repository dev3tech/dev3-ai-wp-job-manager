<?php
/**
 * REST permissions and flows: admin routes, editor-level AI routes, public submit-form routes
 * (same origin, rate limit, kill switches, fallbacks), review queue and matching, privacy.
 *
 * @package Dev3\AiWpJobManager
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiWpJobManager\Data\Drafts;
use Dev3\AiWpJobManager\Privacy_Data;

class RestTest extends Dev3jm_TestCase {

	public function set_up() {
		parent::set_up();
		rest_get_server();
		do_action( 'rest_api_init' );
	}

	public function test_admin_routes_need_manage_and_a_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'overview' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', 'ai/write', array( 'title' => 'x' ) )->get_status() );
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'overview', array(), false )->get_status(), 'Nonce required' );
		$res = $this->rest( 'GET', 'overview' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['wpjm']['active'] );
	}

	public function test_listings_route_scores_and_filters() {
		$this->admin();
		$poor = $this->listing( array(), array( 'post_title' => 'NINJA!!!', 'post_content' => '<p>Young.</p>' ) );
		$this->listing(
			array(
				'_job_salary'          => '50000-60000',
				'_job_salary_currency' => 'USD',
				'_job_salary_unit'     => 'YEAR',
			),
			array( 'post_content' => '<p>' . str_repeat( 'Clear description words here for testing. ', 50 ) . '</p><h3>Duties</h3><ul><li>a</li><li>b</li><li>c</li></ul><h3>You</h3>' ),
			array( 'full-time' )
		);
		$res = $this->rest(
			'GET',
			'listings',
			array(
				'grade'   => 'poor',
				'orderby' => 'quality',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$ids = wp_list_pluck( $res->get_data()['items'], 'id' );
		$this->assertSame( array( $poor ), $ids );
		$detail = $this->rest( 'GET', "listings/{$poor}" )->get_data();
		$this->assertArrayHasKey( 'checks', $detail['quality'] );
		$this->assertSame( 'JobPosting', $detail['schema']['@type'] );
		$this->assertNotEmpty( $detail['checks'] );
	}

	public function test_editor_can_use_ai_routes_on_listings_they_can_edit() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$id     = $this->listing( array( '_job_salary' => '£30k-35k pa' ), array( 'post_title' => 'sr dev' ) );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->rest( 'POST', 'ai/normalise', array( 'id' => $id ) )->get_status(), 'Authors cannot edit job listings' );
		get_role( 'editor' )->add_cap( 'edit_others_job_listings' );
		get_role( 'editor' )->add_cap( 'edit_published_job_listings' );
		get_role( 'editor' )->add_cap( 'edit_job_listings' );
		wp_set_current_user( $editor );
		$res = $this->rest( 'POST', 'ai/normalise', array( 'id' => $id ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Senior Developer', $res->get_data()['title'] );
		$this->assertSame( 'GBP', $res->get_data()['salary']['currency'] );
		// Unsaved editor content is used when sent along.
		$check = $this->rest(
			'POST',
			'ai/inclusive',
			array(
				'id'      => $id,
				'content' => '<p>Digital natives wanted.</p>',
				'use_ai'  => false,
			)
		)->get_data();
		$this->assertSame( 'Digital natives', $check['issues'][0]['phrase'] );
		$this->assertSame( 'sr dev', get_the_title( $id ), 'AI routes never save' );
		$apply = $this->rest(
			'POST',
			"listings/{$id}/apply",
			array(
				'fields' => array( 'title' => 'Senior Developer' ),
			)
		);
		$this->assertSame( 200, $apply->get_status() );
		$this->assertSame( 'Senior Developer', get_the_title( $id ) );
		foreach ( array( 'edit_others_job_listings', 'edit_published_job_listings', 'edit_job_listings' ) as $cap ) {
			get_role( 'editor' )->remove_cap( $cap );
		}
	}

	public function test_public_routes_same_origin_rate_limit_and_switches() {
		wp_set_current_user( 0 );
		$body = array(
			'title' => 'Barista',
			'notes' => "- Make coffee\n- 1 year of experience",
		);
		$res  = $this->rest( 'POST', 'public/write', $body, false );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'template', $res->get_data()['source'], 'No key: template fallback' );
		$this->assertArrayNotHasKey( 'meta', $res->get_data() );
		$this->assertSame( 403, $this->rest( 'POST', 'public/write', $body, false, array( 'Origin' => 'https://evil.example' ) )->get_status() );

		// Kill switch for the visitor AI: still answers with the fallback, no HTTP call.
		$this->with_key();
		$this->settings( array( 'public_ai' => false ) );
		Dev3jm_Http::on( 'generativelanguage', Dev3jm_Http::gemini( array() ) );
		$res = $this->rest( 'POST', 'public/inclusive', array( 'content' => 'Young rockstar wanted.' ), false );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'rules', $res->get_data()['source'] );
		$this->assertCount( 0, Dev3jm_Http::$requests );

		$salary = $this->rest( 'POST', 'public/salary', array( 'salary' => '$20-25 per hour' ), false )->get_data();
		$this->assertSame( 'HOUR', $salary['salary']['unit'] );

		$this->settings( array( 'submit_writer' => false ) );
		$this->assertSame( 403, $this->rest( 'POST', 'public/write', $body, false )->get_status() );

		$this->settings( array( 'public_limit' => 2 ) );
		$codes = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$codes[] = $this->rest( 'POST', 'public/suggest', array( 'title' => 'Intern' ), false )->get_status();
		}
		$this->assertContains( 429, $codes );
	}

	public function test_public_writer_uses_ai_with_submission_context_and_falls_back_on_error() {
		wp_set_current_user( 0 );
		$this->with_key();
		Dev3jm_Http::on( 'generativelanguage', array( 500, array( 'error' => array( 'message' => 'boom' ) ) ) );
		$res = $this->rest(
			'POST',
			'public/write',
			array(
				'title' => 'Chef',
				'notes' => '- Cook',
			),
			false
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'template', $res->get_data()['source'] );
		$this->assertCount( 1, Dev3jm_Http::$requests, 'Visitor context does not retry' );
		global $wpdb;
		$ctx = $wpdb->get_var( "SELECT context FROM {$wpdb->prefix}dev3jm_usage ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
		$this->assertSame( 'submission', $ctx );
	}

	public function test_review_and_bulk_routes() {
		$this->admin();
		$id    = $this->listing( array( '_job_salary' => '$50k-70k/yr' ), array( 'post_title' => 'Sr Dev' ) );
		$start = $this->rest(
			'POST',
			'bulk',
			array(
				'filter' => 'selected',
				'ids'    => array( $id ),
				'ops'    => array( 'salary', 'title' ),
				'use_ai' => false,
			)
		);
		$this->assertSame( 201, $start->get_status() );
		$this->assertSame( 409, $this->rest( 'POST', 'bulk', array( 'ops' => array( 'title' ) ) )->get_status(), 'One run at a time' );
		\Dev3\AiWpJobManager\Core3\Core\Jobs::run( $start->get_data()['id'], 20 );
		$drafts = $this->rest( 'GET', 'drafts', array( 'status' => 'pending' ) )->get_data();
		$this->assertSame( 1, $drafts['total'] );
		$draft = $drafts['items'][0];
		$edit  = $draft['draft'];
		$edit['title'] = 'Senior Software Developer';
		$res   = $this->rest( 'POST', "drafts/{$draft['id']}/accept", array( 'draft' => $edit ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Senior Software Developer', get_the_title( $id ) );
		$this->assertSame( 'accepted', Drafts::get( $draft['id'] )['status'] );
		$this->assertSame( 404, $this->rest( 'POST', "drafts/{$draft['id']}/reject" )->get_status() );
		$recent = $this->rest( 'GET', 'bulk/recent' )->get_data();
		$this->assertNotEmpty( $recent['recent'] );
	}

	public function test_matching_routes_and_privacy_tools() {
		$this->admin();
		$job = $this->listing();
		wp_set_object_terms( $job, array( 'React' ), 'job_listing_tag' );
		$rid = $this->resume(
			'Jamie Doe',
			array(
				'_candidate_title' => 'React Developer',
				'_candidate_email' => 'jamie@example.com',
			),
			array( 'React' )
		);
		$meta = $this->rest( 'GET', 'matching' )->get_data();
		$this->assertTrue( $meta['active'] );
		$this->assertSame( 1, $meta['resumes'] );
		$list = $this->rest( 'GET', "matching/{$job}" )->get_data();
		$this->assertSame( $rid, $list['items'][0]['resume_id'] );
		$this->assertSame( 'Jamie Doe', $list['items'][0]['name'], 'Admins see the name; it is never scored' );
		$scored = $this->rest( 'POST', "matching/{$job}/score", array( 'use_ai' => false ) )->get_data();
		$this->assertSame( 1, $scored['scored'] );
		$this->assertTrue( $scored['items'][0]['stored'] );

		$export = Privacy_Data::export( 'jamie@example.com' );
		$this->assertCount( 1, $export['data'] );
		$erase = Privacy_Data::erase( 'jamie@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertCount( 0, Privacy_Data::export( 'jamie@example.com' )['data'] );

		// Without Resume Manager the route explains the requirement.
		add_filter( 'dev3jm_resumes_active', '__return_false' );
		$this->assertSame( 400, $this->rest( 'GET', "matching/{$job}" )->get_status() );
		remove_filter( 'dev3jm_resumes_active', '__return_false' );
	}
}
