<?php
/**
 * Listing quality score (0-100) with fix-it issues. Rule-based and free: it runs on every save.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Quality;

use Dev3\AiWpJobManager\Ai\Inclusive;
use Dev3\AiWpJobManager\Text\Salary;
use Dev3\AiWpJobManager\Text\Title;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Quality {

	/**
	 * Check weights (sum 100).
	 */
	const WEIGHTS = array(
		'description' => 25,
		'structure'   => 10,
		'salary'      => 15,
		'type'        => 10,
		'location'    => 10,
		'company'     => 5,
		'title'       => 10,
		'tags'        => 5,
		'inclusive'   => 10,
	);

	/**
	 * Scores a listing.
	 *
	 * @param array $job Listing (Wpjm::get).
	 * @return array { score, grade, checks{key:{ok,points,max,label,fix}}, issues[], inclusive }
	 */
	public static function score( array $job ) {
		$text   = Wpjm::plain( $job['content'] );
		$words  = str_word_count( $text );
		$checks = array();
		$add    = static function ( $key, $ratio, $label, $fix = '' ) use ( &$checks ) {
			$ratio          = max( 0, min( 1, $ratio ) );
			$checks[ $key ] = array(
				'ok'     => $ratio >= 0.99,
				'points' => (int) round( self::WEIGHTS[ $key ] * $ratio ),
				'max'    => self::WEIGHTS[ $key ],
				'label'  => $label,
				'fix'    => $ratio >= 0.99 ? '' : $fix,
			);
		};

		/* translators: %d: word count */
		$add( 'description', $words >= 250 ? 1 : ( $words >= 120 ? 0.7 : ( $words >= 50 ? 0.4 : 0 ) ), sprintf( __( 'Description: %d words', 'dev3-ai-wp-job-manager' ), $words ), __( 'Aim for 250-700 words: summary, duties, requirements and benefits.', 'dev3-ai-wp-job-manager' ) );
		$lists = preg_match_all( '/<li\b/i', $job['content'] );
		$heads = preg_match_all( '/<h[2-4]\b/i', $job['content'] );
		$add( 'structure', ( $lists >= 3 ? 0.6 : ( $lists > 0 ? 0.3 : 0 ) ) + ( $heads >= 2 ? 0.4 : ( $heads > 0 ? 0.2 : 0 ) ), __( 'Headings and bullet lists', 'dev3-ai-wp-job-manager' ), __( 'Use short headings and bullet points so candidates can scan the ad.', 'dev3-ai-wp-job-manager' ) );
		$salary = Salary::parse( $job['salary'], $job['salary_currency'], $job['salary_unit'] );
		if ( $salary['ok'] && ! $salary['guessed_unit'] ) {
			$add( 'salary', 1, __( 'Salary is clear', 'dev3-ai-wp-job-manager' ) );
		} elseif ( $salary['ok'] ) {
			$add( 'salary', 0.7, __( 'Salary has no pay period', 'dev3-ai-wp-job-manager' ), __( 'Add the pay period (per hour, month or year).', 'dev3-ai-wp-job-manager' ) );
		} elseif ( '' !== trim( $job['salary'] ) ) {
			$add( 'salary', 0.3, __( 'Salary is not a number', 'dev3-ai-wp-job-manager' ), __( 'Listings with a salary range get more applicants and richer Google results.', 'dev3-ai-wp-job-manager' ) );
		} else {
			$add( 'salary', 0, __( 'No salary', 'dev3-ai-wp-job-manager' ), __( 'Add a salary range; Google for Jobs shows it in results.', 'dev3-ai-wp-job-manager' ) );
		}
		$add( 'type', $job['types'] ? 1 : 0, __( 'Job type set', 'dev3-ai-wp-job-manager' ), __( 'Pick a job type (full time, part time…).', 'dev3-ai-wp-job-manager' ) );
		$add( 'location', ( '' !== trim( $job['location'] ) || $job['remote'] ) ? 1 : 0, __( 'Location or remote', 'dev3-ai-wp-job-manager' ), __( 'Add a location, or mark the job as remote.', 'dev3-ai-wp-job-manager' ) );
		$add( 'company', '' !== trim( $job['company'] ) ? 1 : 0, __( 'Company name', 'dev3-ai-wp-job-manager' ), __( 'Add the hiring company name.', 'dev3-ai-wp-job-manager' ) );
		$title = Title::normalise( $job['title'] );
		$add( 'title', $title['changed'] ? 0.4 : 1, __( 'Clear job title', 'dev3-ai-wp-job-manager' ), __( 'Use a standard, searchable title without hype words or pay.', 'dev3-ai-wp-job-manager' ) );
		$tags = count( $job['tags'] ) + count( $job['categories'] );
		$add( 'tags', $tags >= 3 ? 1 : ( $tags > 0 ? 0.5 : 0 ), __( 'Tags or categories', 'dev3-ai-wp-job-manager' ), __( 'Add skills as tags so candidates can find the job.', 'dev3-ai-wp-job-manager' ) );
		$hits = Inclusive::rule_hits( $text );
		/* translators: %d: count */
		$add( 'inclusive', max( 0, 1 - 0.34 * count( $hits ) ), $hits ? sprintf( _n( '%d exclusionary phrase', '%d exclusionary phrases', count( $hits ), 'dev3-ai-wp-job-manager' ), count( $hits ) ) : __( 'Inclusive wording', 'dev3-ai-wp-job-manager' ), __( 'Replace the flagged phrases with neutral wording.', 'dev3-ai-wp-job-manager' ) );

		$score  = (int) array_sum( wp_list_pluck( $checks, 'points' ) );
		$issues = array();
		foreach ( $checks as $key => $c ) {
			if ( ! $c['ok'] ) {
				$issues[] = $key;
			}
		}
		/**
		 * Filters a listing quality result.
		 *
		 * @param array $result Result.
		 * @param array $job    Listing.
		 */
		return apply_filters(
			'dev3jm_quality',
			array(
				'score'     => min( 100, $score ),
				'grade'     => self::grade( $score ),
				'checks'    => $checks,
				'issues'    => $issues,
				'inclusive' => count( $hits ),
			),
			$job
		);
	}

	/**
	 * Grade from a score.
	 *
	 * @param int $score Score.
	 * @return string good|fair|poor
	 */
	public static function grade( $score ) {
		return $score >= 80 ? 'good' : ( $score >= 55 ? 'fair' : 'poor' );
	}

	/**
	 * Recalculates and stores the score, issue list and salary numbers.
	 *
	 * @param int $id Listing.
	 * @return array|null
	 */
	public static function refresh( $id ) {
		$job = Wpjm::get( $id );
		if ( ! $job ) {
			return null;
		}
		Salary::sync( $id );
		$q = self::score( $job );
		update_post_meta( $id, '_dev3jm_quality', $q['score'] );
		update_post_meta( $id, '_dev3jm_quality_issues', $q['issues'] );
		update_post_meta( $id, '_dev3jm_inclusive', $q['inclusive'] );
		update_post_meta( $id, '_dev3jm_checked_at', time() );
		if ( '' === (string) get_post_meta( $id, '_dev3jm_seniority', true ) ) {
			$level = Title::seniority( $job['title'] );
			if ( '' !== $level ) {
				update_post_meta( $id, '_dev3jm_seniority', $level );
			}
		}
		return $q;
	}

	/**
	 * Save hooks (admin, front-end submission, REST, imports).
	 */
	public static function init() {
		add_action( 'save_post_' . Wpjm::PT, array( __CLASS__, 'on_save' ), 30 );
		add_action( 'job_manager_save_job_listing', array( __CLASS__, 'on_save' ), 30 );
		add_action( 'job_manager_update_job_data', array( __CLASS__, 'on_save' ), 30 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
	}

	/**
	 * Save handler.
	 *
	 * @param int $id Post.
	 */
	public static function on_save( $id ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
			return;
		}
		self::refresh( (int) $id );
	}

	/**
	 * Re-parse when WPJM's salary meta changes outside a save (bulk edit, imports).
	 *
	 * @param int    $meta_id Meta id.
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 */
	public static function on_meta( $meta_id, $post_id, $key ) {
		unset( $meta_id );
		if ( in_array( $key, array( '_job_salary', '_job_salary_currency', '_job_salary_unit' ), true ) && Wpjm::PT === get_post_type( $post_id ) ) {
			Salary::sync( (int) $post_id );
		}
	}
}
