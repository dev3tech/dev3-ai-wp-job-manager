<?php
/**
 * Rule engines: salary parser, title normaliser, type/tag suggestions, inclusive word list, writer template.
 *
 * @package Dev3\AiWpJobManager
 */

require_once __DIR__ . '/testcase.php';

use Dev3\AiWpJobManager\Ai\Inclusive;
use Dev3\AiWpJobManager\Ai\Writer;
use Dev3\AiWpJobManager\Text\Salary;
use Dev3\AiWpJobManager\Text\Suggest;
use Dev3\AiWpJobManager\Text\Title;

class TextTest extends Dev3jm_TestCase {

	/**
	 * Salary cases: input => [ min, max, currency, unit ].
	 *
	 * @return array
	 */
	public function salary_cases() {
		return array(
			array( '$50k-70k/yr', 50000, 70000, 'USD', 'YEAR' ),
			array( '$50k - $70k a year', 50000, 70000, 'USD', 'YEAR' ),
			array( '£30,000 - £35,000 per annum', 30000, 35000, 'GBP', 'YEAR' ),
			array( '20-25 USD/hour', 20, 25, 'USD', 'HOUR' ),
			array( '€4.5k monthly', 4500, 4500, 'EUR', 'MONTH' ),
			array( 'INR 12 LPA', 1200000, 1200000, 'INR', 'YEAR' ),
			array( 'up to 90000 EUR per year', null, 90000, 'EUR', 'YEAR' ),
			array( 'from 60k CAD annually', 60000, null, 'CAD', 'YEAR' ),
			array( '45.000 - 55.000 € pro Jahr p.a.', 45000, 55000, 'EUR', 'YEAR' ),
			array( '50 - 70k', 50000, 70000, 'USD', 'YEAR' ),
			array( '£450 per day', 450, 450, 'GBP', 'DAY' ),
		);
	}

	/**
	 * @dataProvider salary_cases
	 */
	public function test_salary_parser( $raw, $min, $max, $currency, $unit ) {
		$p = Salary::parse( $raw );
		$this->assertTrue( $p['ok'], $raw );
		$this->assertSame( $min, null === $p['min'] ? null : (int) $p['min'], $raw . ' min' );
		$this->assertSame( $max, null === $p['max'] ? null : (int) $p['max'], $raw . ' max' );
		$this->assertSame( $currency, $p['currency'], $raw . ' currency' );
		$this->assertSame( $unit, $p['unit'], $raw . ' unit' );
	}

	public function test_salary_edge_cases() {
		$c = Salary::parse( 'Competitive' );
		$this->assertFalse( $c['ok'] );
		$this->assertTrue( $c['negotiable'] );
		$this->assertFalse( Salary::parse( '' )['ok'] );
		// WPJM's own currency/unit fields win over guesses.
		$p = Salary::parse( '4000', 'eur', 'MONTH' );
		$this->assertSame( array( 'EUR', 'MONTH', false ), array( $p['currency'], $p['unit'], $p['guessed_unit'] ) );
		// No unit: guessed from the size of the number and flagged.
		$g = Salary::parse( '65000' );
		$this->assertSame( 'YEAR', $g['unit'] );
		$this->assertTrue( $g['guessed_unit'] );
		$this->assertSame( 'USD 50,000 – 70,000 per year', Salary::describe( Salary::parse( '$50k-70k/yr' ) ) );
		$this->assertSame( '50,000 – 70,000', Salary::label( 50000.0, 70000.0 ) );
		// A bare "$" follows the site currency when it is a dollar currency.
		update_option( 'job_manager_default_salary_currency', 'AUD' );
		$this->assertSame( 'AUD', Salary::parse( '$90k per year' )['currency'] );
		update_option( 'job_manager_default_salary_currency', 'GBP' );
		$this->assertSame( 'USD', Salary::parse( '$90k per year' )['currency'] );
		delete_option( 'job_manager_default_salary_currency' );
	}

	public function test_salary_write_updates_wpjm_fields_and_numbers() {
		$id = $this->listing( array( '_job_salary' => 'about 50-60k' ) );
		Salary::write(
			$id,
			array(
				'min'      => 60000,
				'max'      => '50000',
				'currency' => 'gbp',
				'unit'     => 'year',
			)
		);
		$this->assertSame( '50,000 – 60,000', get_post_meta( $id, '_job_salary', true ) );
		$this->assertSame( 'GBP', get_post_meta( $id, '_job_salary_currency', true ) );
		$this->assertSame( 'YEAR', get_post_meta( $id, '_job_salary_unit', true ) );
		$this->assertEquals( 50000, get_post_meta( $id, '_dev3jm_salary_min', true ) );
		$this->assertNull( Salary::clean( array( 'min' => '', 'max' => '' ) ) );
	}

	public function test_title_normaliser() {
		$t = Title::normalise( '🔥 URGENT!!! Sr. JS Dev Rockstar ($90k)' );
		$this->assertSame( 'Senior JavaScript Developer', $t['title'] );
		$this->assertSame( 'senior', $t['seniority'] );
		$this->assertTrue( $t['changed'] );
		$this->assertNotEmpty( $t['changes'] );
		$this->assertSame( 'Node.js Engineer', Title::normalise( 'Node.js Engineer' )['title'] );
		$this->assertFalse( Title::normalise( 'Product Designer' )['changed'] );
		$this->assertSame( 'Customer Support Manager', Title::normalise( 'CUSTOMER SUPPORT MANAGER' )['title'] );
		$this->assertSame( 'executive', Title::seniority( 'Head of Engineering' ) );
		$this->assertSame( 'entry', Title::seniority( 'Marketing Intern' ) );
	}

	public function test_type_and_tag_rules_use_existing_terms() {
		$s = Suggest::rules(
			array(
				'title'   => 'Part-time barista',
				'content' => '<p>Weekend shifts making coffee. Customer service skills and Excel are a plus. 20 hours per week.</p>',
			)
		);
		$this->assertSame( 'part-time', $s['types'][0]['slug'] );
		$this->assertContains( 'Customer service', $s['tags'] );
		$this->assertContains( 'Excel', $s['tags'] );
		// Short names match exact case only ("go" in prose is not the Go language).
		$this->assertNotContains( 'Go', Suggest::skills_in( 'You will go above and beyond' ) );
		$this->assertContains( 'Go', Suggest::skills_in( 'Backend in Go and SQL' ) );
		$f = Suggest::rules(
			array(
				'title'   => 'Engineer',
				'content' => 'Build things.',
			)
		);
		$this->assertSame( 'full-time', $f['types'][0]['slug'], 'Falls back to full time' );
	}

	public function test_inclusive_rules_and_apply() {
		$hits = wp_list_pluck( Inclusive::rule_hits( 'We want a young, energetic rockstar. He will join our guys.' ), 'phrase' );
		$this->assertContains( 'young', $hits );
		$this->assertContains( 'energetic', $hits );
		$this->assertContains( 'rockstar', $hits );
		$this->assertContains( 'He will', $hits );
		$out = Inclusive::apply(
			'<p>Young team. He will lead.</p>',
			array(
				array(
					'phrase'     => 'young',
					'suggestion' => 'motivated',
				),
				array(
					'phrase'     => 'he will',
					'suggestion' => 'you will',
				),
			)
		);
		$this->assertSame( '<p>Motivated team. You will lead.</p>', $out );
		$this->assertWPError( Inclusive::check( '' ) );
	}

	public function test_writer_template_without_ai() {
		$d = Writer::draft(
			array(
				'title' => 'sr dev',
				'notes' => "We build calm software.\n- Design onboarding flows\n- 3+ years of PHP experience\n- 30 days holiday",
			)
		);
		$this->assertSame( 'template', $d['source'] );
		$this->assertSame( 'Senior Developer', $d['title'] );
		$this->assertSame( array( 'Design onboarding flows.' ), $d['sections']['responsibilities'] );
		$this->assertSame( array( '3+ years of PHP experience.' ), $d['sections']['requirements'] );
		$this->assertSame( array( '30 days holiday.' ), $d['sections']['benefits'] );
		$this->assertStringContainsString( '<h3>What you will do</h3>', $d['content'] );
		$this->assertContains( 'PHP', $d['skills'] );
		$this->assertWPError( Writer::draft( array() ) );
		$this->assertCount( 0, Dev3jm_Http::$requests, 'No AI call without a key' );
	}
}
