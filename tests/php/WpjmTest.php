<?php
/**
 * Integration with the real WP Job Manager: quality on save, JobPosting schema filter, tags,
 * listing apply, the submit-form hooks and the admin list column.
 *
 * @package Dev3\AiWpJobManager
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiWpJobManager\Front\Submit_Form;
use Dev3\AiWpJobManager\Quality\Quality;
use Dev3\AiWpJobManager\Wpjm\Schema_Enhancer;
use Dev3\AiWpJobManager\Wpjm\Tags;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

class WpjmTest extends Dev3jm_TestCase {

	public function test_wp_job_manager_is_loaded_for_real() {
		$this->assertTrue( class_exists( 'WP_Job_Manager' ), 'WP Job Manager must be installed in wp-env' );
		$this->assertTrue( Wpjm::active() );
		$this->assertTrue( post_type_exists( 'job_listing' ) );
		$this->assertTrue( taxonomy_exists( 'job_listing_type' ) );
		$this->assertTrue( taxonomy_exists( 'job_listing_tag' ) );
		$this->assertTrue( Tags::ours(), 'No Job Tags add-on: the plugin provides job_listing_tag' );
		$this->assertNotEmpty( get_term_by( 'slug', 'full-time', 'job_listing_type' ) );
		$status = Wpjm::status();
		$this->assertTrue( $status['supported'] );
		$this->assertTrue( $status['resumes'], 'Resume Manager stub is registered' );
	}

	public function test_quality_is_scored_on_save_and_rewards_complete_listings() {
		$poor = $this->listing( array( '_job_salary' => 'Competitive' ), array( 'post_title' => 'ROCKSTAR NINJA!!!', 'post_content' => '<p>Young team.</p>' ) );
		$q    = (int) get_post_meta( $poor, '_dev3jm_quality', true );
		$this->assertGreaterThan( 0, $q, 'Scored by the save_post hook' );
		$issues = (array) get_post_meta( $poor, '_dev3jm_quality_issues', true );
		$this->assertContains( 'salary', $issues );
		$this->assertContains( 'title', $issues );
		$this->assertContains( 'inclusive', $issues );
		$this->assertSame( 1, (int) get_post_meta( $poor, '_dev3jm_inclusive', true ) );

		$body = '<p>' . str_repeat( 'We build tools that help clinics run smoothly every day. ', 30 ) . '</p><h3>What you will do</h3><ul><li>Build features</li><li>Review code</li><li>Mentor</li></ul><h3>What you bring</h3><ul><li>PHP</li></ul>';
		$good = $this->listing(
			array(
				'_job_salary'          => '50,000 - 60,000',
				'_job_salary_currency' => 'EUR',
				'_job_salary_unit'     => 'YEAR',
			),
			array(
				'post_title'   => 'Senior PHP Developer',
				'post_content' => $body,
			),
			array( 'full-time' )
		);
		wp_set_object_terms( $good, array( 'PHP', 'WordPress', 'MySQL' ), 'job_listing_tag' );
		$res = Quality::refresh( $good );
		$this->assertGreaterThanOrEqual( 90, $res['score'] );
		$this->assertSame( 'good', $res['grade'] );
		$this->assertLessThan( $res['score'], $q );
		$this->assertEquals( 50000, get_post_meta( $good, '_dev3jm_salary_min', true ) );
		$this->assertSame( 'senior', get_post_meta( $good, '_dev3jm_seniority', true ) );
	}

	public function test_schema_filter_fixes_salary_remote_and_types() {
		$id   = $this->listing(
			array(
				'_job_salary'      => '$50k - $70k a year',
				'_remote_position' => 1,
				'_job_location'    => '',
				'_job_expires'     => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			),
			array( 'post_title' => 'Senior Frontend Developer' ),
			array( 'full-time' )
		);
		wp_set_object_terms( $id, array( 'React', 'TypeScript' ), 'job_listing_tag' );
		$this->settings( array( 'remote_region' => 'United States' ) );
		$data = wpjm_get_job_listing_structured_data( $id );
		$this->assertSame( 'MonetaryAmount', $data['baseSalary']['@type'] );
		$this->assertSame( 'USD', $data['baseSalary']['currency'] );
		$this->assertSame( 50000.0, $data['baseSalary']['value']['minValue'] );
		$this->assertSame( 70000.0, $data['baseSalary']['value']['maxValue'] );
		$this->assertSame( 'YEAR', $data['baseSalary']['value']['unitText'] );
		$this->assertArrayNotHasKey( 'value', $data['baseSalary']['value'] );
		$this->assertSame( 'TELECOMMUTE', $data['jobLocationType'] );
		$this->assertSame( 'United States', $data['applicantLocationRequirements']['name'] );
		$this->assertContains( 'FULL_TIME', (array) $data['employmentType'] );
		$this->assertSame( 60, $data['experienceRequirements']['monthsOfExperience'] );
		$this->assertSame( 'React, TypeScript', $data['skills'] );
		$this->assertSame( (string) $id, $data['identifier']['value'] );
		$checks = wp_list_pluck( Schema_Enhancer::validate( $data ), 'ok', 'key' );
		$this->assertTrue( $checks['baseSalary'] );
		$this->assertTrue( $checks['jobLocation'] );

		// Unreadable salary: WPJM's invalid string value is removed rather than printed.
		update_post_meta( $id, '_job_salary', 'Competitive' );
		$this->assertArrayNotHasKey( 'baseSalary', wpjm_get_job_listing_structured_data( $id ) );
		// Setting off: WPJM output is untouched.
		$this->settings( array( 'schema_enhance' => false ) );
		$raw = wpjm_get_job_listing_structured_data( $id );
		$this->assertSame( 'Competitive', $raw['baseSalary']['value']['value'] );
	}

	public function test_plain_location_becomes_a_postal_address() {
		$id   = $this->listing( array( '_job_location' => 'Leeds, United Kingdom' ) );
		$data = wpjm_get_job_listing_structured_data( $id );
		$this->assertSame( 'Leeds', $data['jobLocation']['address']['addressLocality'] );
		$this->assertSame( 'United Kingdom', $data['jobLocation']['address']['addressCountry'] );
	}

	public function test_apply_writes_wpjm_fields_and_terms() {
		$id  = $this->listing( array( '_job_salary' => '50k' ) );
		$job = Wpjm::apply(
			$id,
			array(
				'title'   => 'Senior Frontend Developer',
				'content' => '<p>New <script>x</script>copy</p>',
				'salary'  => array(
					'min'      => 50000,
					'max'      => 65000,
					'currency' => 'EUR',
					'unit'     => 'YEAR',
				),
				'types'   => array( 'part-time' ),
				'tags'    => array( 'React', 'Brand New Tag' ),
			)
		);
		$this->assertSame( 'Senior Frontend Developer', $job['title'] );
		$this->assertStringNotContainsString( '<script>', $job['content'] );
		$this->assertSame( '50,000 – 65,000', $job['salary'] );
		$this->assertSame( 'EUR', $job['salary_currency'] );
		$this->assertSame( array( 'part-time' ), wp_list_pluck( $job['types'], 'slug' ) );
		$this->assertContains( 'Brand New Tag', wp_list_pluck( $job['tags'], 'name' ) );
		$this->assertWPError( Wpjm::apply( 999999, array( 'title' => 'x' ) ) );
	}

	public function test_submit_form_hooks_add_tags_field_and_save() {
		$fields = apply_filters( 'submit_job_form_fields', array( 'job' => array() ) );
		$this->assertArrayHasKey( 'job_tags', $fields['job'] );
		$id = $this->listing( array( '_job_salary' => '$40k-45k per year' ) );
		$this->settings( array( 'auto_normalise' => true ) );
		Submit_Form::save( $id, array( 'job' => array( 'job_tags' => 'PHP, Laravel , ,PHP' ) ) );
		$this->assertEqualsCanonicalizing( array( 'PHP', 'Laravel' ), wp_get_object_terms( $id, 'job_listing_tag', array( 'fields' => 'names' ) ) );
		$this->assertSame( '40,000 – 45,000', get_post_meta( $id, '_job_salary', true ) );
		$this->assertSame( 'USD', get_post_meta( $id, '_job_salary_currency', true ) );
		$filled = Submit_Form::fill_tags( $fields, get_post( $id ) );
		$this->assertStringContainsString( 'Laravel', $filled['job']['job_tags']['value'] );
	}

	public function test_submit_form_panel_and_config_are_neutral() {
		ob_start();
		Submit_Form::panel();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="dev3jm-assistant"', $html );
		$cfg = Submit_Form::config();
		$this->assertFalse( $cfg['ai'], 'No key: the panel uses fallbacks' );
		$this->assertStringContainsString( 'dev3jm/v1/public/', $cfg['restUrl'] );
		$this->assertArrayNotHasKey( 'nonce', $cfg );
		$this->settings(
			array(
				'submit_writer'    => false,
				'submit_inclusive' => false,
				'submit_suggest'   => false,
				'salary_hint'      => false,
			)
		);
		ob_start();
		Submit_Form::panel();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_admin_column_and_row_action() {
		$this->admin();
		$id   = $this->listing();
		$page = new \Dev3\AiWpJobManager\Admin\Admin_Page();
		$cols = $page->columns(
			array(
				'cb'           => '',
				'job_position' => 'Position',
				'date'         => 'Date',
			)
		);
		$this->assertSame( array( 'cb', 'job_position', 'dev3jm_quality', 'date' ), array_keys( $cols ) );
		ob_start();
		$page->column( 'dev3jm_quality', $id );
		$this->assertMatchesRegularExpression( '/dev3jm-q--(good|fair|poor)/', ob_get_clean() );
		$actions = $page->row_actions( array(), get_post( $id ) );
		$this->assertStringContainsString( 'Improve with DEV3 AI', $actions['dev3jm'] );
	}
}
