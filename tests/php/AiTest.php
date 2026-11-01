<?php
/**
 * DEV3 AI features with Gemini mocked: writer, inclusive check, normaliser, suggestions, matching
 * (including the fairness payload) and bulk improve with the review queue.
 *
 * @package Dev3\AiWpJobManager
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiWpJobManager\Ai\Improver;
use Dev3\AiWpJobManager\Ai\Inclusive;
use Dev3\AiWpJobManager\Ai\Matcher;
use Dev3\AiWpJobManager\Ai\Normaliser;
use Dev3\AiWpJobManager\Ai\Suggester;
use Dev3\AiWpJobManager\Ai\Writer;
use Dev3\AiWpJobManager\Core3\Core\Jobs;
use Dev3\AiWpJobManager\Data\Drafts;
use Dev3\AiWpJobManager\Wpjm\Resumes;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

class AiTest extends Dev3jm_TestCase {

	public function test_writer_with_ai_returns_safe_structured_draft() {
		$this->with_key();
		Dev3jm_Http::on(
			'generativelanguage',
			Dev3jm_Http::gemini(
				array(
					'title'            => 'Senior Product Designer',
					'summary'          => 'Shape <script>x</script> our product.',
					'responsibilities' => array( 'Own the design system' ),
					'requirements'     => array( '5 years in product design' ),
					'nice_to_have'     => array(),
					'benefits'         => array(),
					'skills'           => array( 'Figma' ),
					'seniority'        => 'senior',
					'excerpt'          => 'Lead design.',
				)
			)
		);
		$job = Wpjm::get( $this->listing( array( '_job_salary' => '$90k' ), array( 'post_title' => 'Designer' ) ) );
		$d   = Writer::draft( Writer::input_from_listing( $job, 'remote friendly', 'friendly' ) );
		$this->assertSame( 'ai', $d['source'] );
		$this->assertSame( 'Senior Product Designer', $d['title'] );
		$this->assertSame( 'senior', $d['seniority'] );
		$this->assertStringNotContainsString( '<script>', $d['content'] );
		$this->assertStringContainsString( '<li>Own the design system</li>', $d['content'] );
		$body = Dev3jm_Http::$requests[0]['raw'];
		$this->assertStringContainsString( 'friendly tone', $body );
		$this->assertStringContainsString( 'Acme', $body, 'Company from WPJM meta is sent' );
		$this->assertStringContainsString( '$90k', $body );
		$this->assertStringContainsString( 'maxLength', $body, 'Schema strings are bounded' );
		$this->assertSame( 'Designer', get_the_title( $job['id'] ), 'Drafts never save' );
	}

	public function test_writer_falls_back_when_ai_is_off() {
		$this->with_key();
		$this->settings( array( 'ai_enabled' => false ) );
		$d = Writer::draft(
			array(
				'title' => 'Chef',
				'notes' => '- Cook',
			)
		);
		$this->assertSame( 'template', $d['source'] );
		$this->assertCount( 0, Dev3jm_Http::$requests );
	}

	public function test_inclusive_ai_phrases_must_exist_in_the_text() {
		$this->with_key();
		Dev3jm_Http::on(
			'generativelanguage',
			Dev3jm_Http::gemini(
				array(
					'issues' => array(
						array(
							'phrase'     => 'culture of hustle',
							'reason'     => 'Suggests long hours',
							'suggestion' => 'focused culture',
						),
						array(
							'phrase'     => 'invented phrase',
							'reason'     => 'x',
							'suggestion' => 'y',
						),
					),
				)
			)
		);
		$res     = Inclusive::check( '<p>A young team with a culture of hustle.</p>' );
		$phrases = wp_list_pluck( $res['issues'], 'phrase' );
		$this->assertSame( 'ai', $res['source'] );
		$this->assertContains( 'young', $phrases );
		$this->assertContains( 'culture of hustle', $phrases );
		$this->assertNotContains( 'invented phrase', $phrases );
	}

	public function test_normaliser_uses_ai_only_when_rules_give_up() {
		$this->with_key();
		$rules = Normaliser::run(
			array(
				'title'  => 'Sr Dev',
				'salary' => '$50k-70k/yr',
			)
		);
		$this->assertSame( 'rules', $rules['source'] );
		$this->assertSame( 50000.0, $rules['salary']['min'] );
		$this->assertCount( 0, Dev3jm_Http::$requests );

		Dev3jm_Http::on(
			'generativelanguage',
			Dev3jm_Http::gemini(
				array(
					'title'      => 'Senior Developer',
					'seniority'  => 'senior',
					'salary_min' => 3000,
					'salary_max' => 3500,
					'currency'   => 'eur',
					'unit'       => 'MONTH',
					'note'       => 'Read "drei tausend" as 3000.',
				)
			)
		);
		$ai = Normaliser::run(
			array(
				'title'  => 'Sr Dev',
				'salary' => 'drei bis dreieinhalb tausend Euro',
			)
		);
		$this->assertSame( 'ai', $ai['source'] );
		$this->assertSame( array( 3000.0, 3500.0, 'EUR', 'MONTH' ), array( $ai['salary']['min'], $ai['salary']['max'], $ai['salary']['currency'], $ai['salary']['unit'] ) );
		$this->assertStringContainsString( 'drei', $ai['changes'][ count( $ai['changes'] ) - 1 ] );
	}

	public function test_suggester_only_returns_existing_types() {
		$this->with_key();
		Dev3jm_Http::on(
			'generativelanguage',
			Dev3jm_Http::gemini(
				array(
					'types'      => array( 'part-time', 'made-up-type' ),
					'categories' => array(),
					'tags'       => array( 'Latte Art', 'Customer Service' ),
					'reason'     => 'Weekend shifts.',
				)
			)
		);
		$s = Suggester::run(
			array(
				'title'   => 'Barista',
				'content' => 'Weekend shifts.',
			)
		);
		$this->assertSame( 'ai', $s['source'] );
		$this->assertSame( array( 'part-time' ), wp_list_pluck( $s['types'], 'slug' ) );
		$this->assertContains( 'Latte Art', $s['tags'] );
		$schema = Dev3jm_Http::$requests[0]['json']['generationConfig']['responseSchema'];
		$this->assertContains( 'full-time', $schema['properties']['types']['items']['enum'], 'The model is constrained to existing type slugs' );
	}

	public function test_matching_rules_rank_resumes_and_never_send_protected_data() {
		$job = $this->listing( array(), array( 'post_title' => 'Senior Frontend Developer' ) );
		wp_set_object_terms( $job, array( 'React', 'TypeScript', 'CSS' ), 'job_listing_tag' );
		$strong = $this->resume(
			'Alex Morgan',
			array(
				'_candidate_title'      => 'Senior Frontend Developer',
				'_candidate_location'   => 'Berlin',
				'_candidate_email'      => 'alex@example.com',
				'_candidate_photo'      => 'https://example.com/alex.jpg',
				'_candidate_experience' => array(
					array(
						'employer'  => 'Brightwave',
						'job_title' => 'Frontend Lead',
						'date'      => '2018 - Present',
						'notes'     => 'Alex Morgan led React work. Contact alex@example.com or +1 555 123 4567.',
					),
				),
			),
			array( 'React', 'TypeScript', 'CSS' ),
			'Gender: female. Date of birth 1990.'
		);
		$weak   = $this->resume( 'Sam Lee', array( '_candidate_title' => 'Accountant' ), array( 'Excel' ) );

		$ranked = Matcher::ranked( $job );
		$this->assertSame( $strong, $ranked[0]['resume_id'] );
		$this->assertSame( $weak, $ranked[1]['resume_id'] );
		$this->assertGreaterThan( $ranked[1]['score'], $ranked[0]['score'] );
		$this->assertEqualsCanonicalizing( array( 'React', 'TypeScript', 'CSS' ), $ranked[0]['matched_skills'] );

		$this->with_key();
		Dev3jm_Http::on(
			'generativelanguage',
			Dev3jm_Http::gemini(
				array(
					'score'     => 90,
					'criteria'  => array(
						'skills'     => 100,
						'experience' => 90,
						'role'       => 80,
						'location'   => 100,
					),
					'strengths' => array( 'Covers React, TypeScript and CSS from the requirements.' ),
					'gaps'      => array(),
					'summary'   => 'Strong match on the stated requirements.',
				)
			)
		);
		$res = Matcher::match( $job, $strong );
		$this->assertSame( 'ai', $res['source'] );
		$this->assertSame( (int) round( ( $res['rule_score'] + 90 ) / 2 ), $res['score'] );
		$sent = Dev3jm_Http::$requests[0]['raw'];
		foreach ( array( 'Alex', 'Morgan', 'alex@example.com', '555 123', 'alex.jpg' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $sent, "Protected data leaked: {$secret}" );
		}
		$this->assertStringNotContainsString( 'female', $sent, 'Protected attributes in free text are scrubbed' );
		$this->assertStringNotContainsString( '1990', $sent );
		$this->assertStringContainsString( 'protected attributes', $sent );
		// Stored and returned first time on the next ranking.
		$again = Matcher::ranked( $job );
		$this->assertTrue( $again[0]['stored'] );
		$this->assertSame( 'ai', $again[0]['source'] );
		// The audit filter can strip more keys.
		$p = Matcher::anonymise( Resumes::profile( $strong ), 'Alex Morgan' );
		$this->assertArrayNotHasKey( 'name', $p );
		$this->assertStringNotContainsString( 'Alex', wp_json_encode( $p ) );
		$this->assertEqualsWithDelta( (float) gmdate( 'Y' ) - 2018, $p['years'], 1.0 );
	}

	public function test_bulk_improve_creates_drafts_and_changes_nothing_until_accepted() {
		$this->admin();
		$a = $this->listing( array( '_job_salary' => '$50k-70k/yr' ), array( 'post_title' => 'Sr. JS Dev Ninja!!!', 'post_content' => '<p>We need a young rockstar who knows React.</p>' ) );
		$b = $this->listing( array( '_job_salary' => '£30,000 - £35,000 per annum' ), array( 'post_title' => 'Barista', 'post_content' => '<p>Part time coffee job for an energetic person.</p>' ) );
		$job = Improver::start( 'selected', array( $a, $b ), array( 'salary', 'title', 'inclusive', 'taxonomy' ), false );
		$this->assertIsArray( $job );
		Jobs::run( $job['id'], 20 );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );
		$this->assertSame( 'Sr. JS Dev Ninja!!!', get_the_title( $a ), 'Nothing changes before review' );
		$list = Drafts::query( array( 'batch_id' => $job['id'] ) );
		$this->assertSame( 2, $list['total'] );
		$da = Drafts::pending_for( $a );
		$this->assertSame( 'Senior JavaScript Developer', $da['draft']['title'] );
		$this->assertSame( 'USD', $da['draft']['salary']['currency'] );
		$this->assertNotEmpty( $da['draft']['fixes'] );
		$this->assertSame( array( 'full-time' ), $da['draft']['types'] );
		$this->assertContains( 'React', $da['draft']['tags'] );

		// Reject leaves B untouched.
		$db = Drafts::pending_for( $b );
		$this->assertTrue( Drafts::reject( $db['id'] ) );
		$this->assertSame( 'Barista', get_the_title( $b ) );
		$this->assertWPError( Drafts::reject( $db['id'] ) );

		// Accept-safe applies title/salary/taxonomy and keeps the wording fixes pending.
		$res = Drafts::accept_safe( $job['id'] );
		$this->assertSame(
			array(
				'applied' => 1,
				'kept'    => 1,
			),
			$res
		);
		$this->assertSame( 'Senior JavaScript Developer', get_the_title( $a ) );
		$this->assertSame( '50,000 – 70,000', get_post_meta( $a, '_job_salary', true ) );
		$left = Drafts::pending_for( $a );
		$this->assertSame( array( 'fixes' ), array_keys( $left['draft'] ) );
		// Accept the wording fixes with an edit.
		$fixes = $left['draft']['fixes'];
		foreach ( $fixes as $i => $f ) {
			if ( 'young' === strtolower( $f['phrase'] ) ) {
				$fixes[ $i ]['suggestion'] = 'curious';
			}
		}
		$out = Drafts::accept( $left['id'], array( 'fixes' => $fixes ) );
		$this->assertStringContainsString( 'curious', $out['content'] );
		$this->assertStringNotContainsString( 'rockstar', $out['content'] );
		$this->assertSame( 0, (int) get_post_meta( $a, '_dev3jm_inclusive', true ) );
	}

	public function test_bulk_targets_filters() {
		$this->admin();
		$no_salary = $this->listing();
		$salary    = $this->listing(
			array(
				'_job_salary'          => '60000',
				'_job_salary_currency' => 'USD',
				'_job_salary_unit'     => 'YEAR',
			)
		);
		\Dev3\AiWpJobManager\Quality\Quality::refresh( $no_salary );
		\Dev3\AiWpJobManager\Quality\Quality::refresh( $salary );
		$ids = Improver::targets( 'no_salary' );
		$this->assertContains( $no_salary, $ids );
		$this->assertNotContains( $salary, $ids );
		$this->assertWPError( Improver::start( 'all', array(), array( 'nope' ) ) );
	}
}
