<?php
/**
 * Candidate matching against WPJM Resume Manager resumes: decision support only.
 *
 * 1. A transparent rule score from four criteria: skills 40, experience 25, role 20, location 15.
 * 2. With DEV3 AI on, the model reviews the anonymised resume against the job and writes strengths,
 *    gaps and a summary; the final score is the average of both, so the rules keep it grounded.
 *
 * Fairness: profiles are built from an allow-list (Resumes::profile), free text is PII-masked and
 * the candidate name is scrubbed; protected keys are stripped again before any AI call. Nothing here
 * changes a resume, an application or its status.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Api\Prompts;
use Dev3\AiWpJobManager\Core3\Core\Privacy;
use Dev3\AiWpJobManager\Data\Tables;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Text\Suggest;
use Dev3\AiWpJobManager\Text\Title;
use Dev3\AiWpJobManager\Wpjm\Resumes;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Matcher {

	const WEIGHTS = array(
		'skills'     => 40,
		'experience' => 25,
		'role'       => 20,
		'location'   => 15,
	);

	const LEVEL_YEARS = array(
		''          => 0,
		'entry'     => 0,
		'mid'       => 2,
		'senior'    => 5,
		'lead'      => 8,
		'executive' => 10,
	);

	/**
	 * Keys never sent to AI or scored (normalised: lowercase letters only).
	 */
	const PROTECTED_KEYS = array( 'name', 'firstname', 'lastname', 'fullname', 'candidatename', 'email', 'candidateemail', 'phone', 'mobile', 'photo', 'candidatephoto', 'picture', 'avatar', 'image', 'video', 'candidatevideo', 'age', 'dob', 'dateofbirth', 'birthdate', 'birthday', 'gender', 'sex', 'pronouns', 'maritalstatus', 'married', 'children', 'family', 'pregnancy', 'nationality', 'citizenship', 'ethnicity', 'race', 'religion', 'disability', 'health', 'medical', 'sexualorientation', 'orientation', 'union', 'political', 'address', 'street', 'postcode', 'zip', 'ssn', 'passport', 'visa', 'links' );

	/**
	 * Protected keys (filterable for audits).
	 *
	 * @return string[]
	 */
	public static function protected_keys() {
		/**
		 * Filters the keys removed before matching.
		 *
		 * @param string[] $keys Normalised keys.
		 */
		return (array) apply_filters( 'dev3jm_protected_attributes', self::PROTECTED_KEYS );
	}

	/**
	 * Removes protected keys recursively.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function strip_protected( array $data ) {
		$keys = self::protected_keys();
		$out  = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( preg_replace( '/[^a-z]/', '', strtolower( $key ) ), $keys, true ) ) {
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip_protected( $value ) : $value;
		}
		return $out;
	}

	/**
	 * Anonymised payload for scoring and AI.
	 *
	 * @param array  $profile Resumes::profile().
	 * @param string $name    Candidate name to scrub.
	 * @return array
	 */
	public static function anonymise( array $profile, $name = '' ) {
		$names = array_filter( array_unique( array_merge( array( $name ), preg_split( '/\s+/', trim( (string) $name ) ) ) ), static fn( $n ) => mb_strlen( (string) $n ) >= 3 );
		$scrub = static function ( $text ) use ( $names ) {
			$text = Privacy::mask( (string) $text );
			// Self-declared protected attributes ("Gender: female", "Date of birth 1990") never reach the model.
			$text = (string) preg_replace( '/\b(gender|sex|pronouns|date of birth|d\.?o\.?b\.?|birth ?date|age|nationality|citizenship|ethnicity|race|religion|marital status|sexual orientation|disability|health)\s*[:\-]\s*[^.;\n|]*[.;|]?/iu', '', $text );
			$text = (string) preg_replace( '/\b(date of birth|born(?: in| on)?)\s+[^.;\n|]*\d{4}[^.;\n|]*[.;|]?/iu', '', $text );
			$text = (string) preg_replace( '/\b(married|divorced|widowed|pregnant)\b/iu', '', $text );
			foreach ( $names as $n ) {
				$text = str_ireplace( $n, '[candidate]', $text );
			}
			return $text;
		};
		$out   = array(
			'headline'   => $scrub( $profile['headline'] ),
			'location'   => mb_substr( (string) $profile['location'], 0, 120 ),
			'summary'    => $scrub( $profile['summary'] ),
			'skills'     => array_values( (array) $profile['skills'] ),
			'years'      => (float) $profile['years'],
			'experience' => array(),
			'education'  => array(),
		);
		foreach ( (array) $profile['experience'] as $e ) {
			$out['experience'][] = array(
				'title'   => $e['title'],
				'company' => $e['company'],
				'date'    => $e['date'],
				'notes'   => $scrub( $e['notes'] ),
			);
		}
		foreach ( (array) $profile['education'] as $e ) {
			$out['education'][] = array(
				'qualification' => $e['qualification'],
				'school'        => $e['school'],
				'date'          => $e['date'],
			);
		}
		/**
		 * Filters the anonymised candidate payload used for matching (audit hook).
		 *
		 * @param array $out     Payload.
		 * @param array $profile Profile.
		 */
		return self::strip_protected( (array) apply_filters( 'dev3jm_match_payload', $out, $profile ) );
	}

	/**
	 * Candidate payload as text.
	 *
	 * @param array $p Anonymised payload.
	 * @return string
	 */
	public static function to_text( array $p ) {
		$lines = array(
			'Headline: ' . $p['headline'],
			'Location: ' . $p['location'],
			'Years of experience: ' . $p['years'],
			'Skills: ' . implode( ', ', $p['skills'] ),
			'Summary: ' . mb_substr( $p['summary'], 0, 1200 ),
		);
		foreach ( $p['experience'] as $e ) {
			$lines[] = '- ' . $e['title'] . ' at ' . $e['company'] . ' (' . $e['date'] . '): ' . mb_substr( $e['notes'], 0, 300 );
		}
		foreach ( $p['education'] as $e ) {
			$lines[] = '- Education: ' . $e['qualification'] . ', ' . $e['school'] . ' (' . $e['date'] . ')';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Skills a job asks for: its tags, else vocabulary hits in the text.
	 *
	 * @param array $job Listing.
	 * @return array norm => label
	 */
	public static function job_skills( array $job ) {
		$out = array();
		foreach ( $job['tags'] as $t ) {
			$out[ Suggest::norm( $t['name'] ) ] = $t['name'];
		}
		if ( ! $out ) {
			foreach ( Suggest::skills_in( $job['title'] . "\n" . Wpjm::plain( $job['content'] ), 12 ) as $s ) {
				$out[ Suggest::norm( $s ) ] = $s;
			}
		}
		unset( $out[''] );
		return $out;
	}

	/**
	 * Years the job asks for.
	 *
	 * @param array $job Listing.
	 * @return int
	 */
	public static function required_years( array $job ) {
		$level = '' !== $job['seniority'] ? $job['seniority'] : Title::seniority( $job['title'] );
		$years = isset( self::LEVEL_YEARS[ $level ] ) ? self::LEVEL_YEARS[ $level ] : 0;
		if ( preg_match_all( '/(\d{1,2})\s*\+?\s*(?:years?|yrs?)/i', Wpjm::plain( $job['content'] ), $m ) ) {
			$years = max( $years, min( 15, max( array_map( 'intval', $m[1] ) ) ) );
		}
		return (int) $years;
	}

	/**
	 * Words of a title for overlap.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( $text ) {
		$stop  = array( 'and', 'the', 'for', 'with', 'senior', 'junior', 'lead', 'mid', 'level', 'remote', 'hybrid', 'of', 'in', 'at', 'a', 'an', 'to' );
		$words = preg_split( '/[^a-z0-9#+]+/', mb_strtolower( (string) $text ) );
		return array_values( array_diff( array_filter( (array) $words, static fn( $w ) => strlen( $w ) > 1 ), $stop ) );
	}

	/**
	 * Rule score.
	 *
	 * @param array $job     Listing.
	 * @param array $profile Anonymised payload.
	 * @return array
	 */
	public static function rule_score( array $job, array $profile ) {
		$need = self::job_skills( $job );
		$have = array();
		foreach ( $profile['skills'] as $s ) {
			$have[ Suggest::norm( $s ) ] = $s;
		}
		$exp_text = mb_strtolower( implode( ' ', wp_list_pluck( $profile['experience'], 'notes' ) ) . ' ' . $profile['summary'] );
		$matched  = array();
		$missing  = array();
		foreach ( $need as $key => $label ) {
			if ( isset( $have[ $key ] ) || ( mb_strlen( $label ) > 2 && false !== mb_strpos( $exp_text, mb_strtolower( $label ) ) ) ) {
				$matched[] = $label;
			} else {
				$missing[] = $label;
			}
		}
		$skills     = $need ? (int) round( 100 * count( $matched ) / count( $need ) ) : 50;
		$req        = self::required_years( $job );
		$years      = (float) $profile['years'];
		$experience = $req <= 0 ? ( $years > 0 ? 100 : 70 ) : (int) round( 100 * min( 1, $years / $req ) );
		$jw         = self::words( $job['title'] );
		$cw         = self::words( implode( ' ', array_merge( array( $profile['headline'] ), wp_list_pluck( $profile['experience'], 'title' ) ) ) );
		$role       = $jw ? (int) round( 100 * count( array_intersect( $jw, $cw ) ) / count( $jw ) ) : 50;
		$location   = self::location_fit( $job, $profile );
		$criteria   = array(
			'skills'     => min( 100, max( 0, $skills ) ),
			'experience' => min( 100, max( 0, $experience ) ),
			'role'       => min( 100, max( 0, $role ) ),
			'location'   => $location,
		);
		$score      = 0;
		foreach ( self::WEIGHTS as $k => $w ) {
			$score += $criteria[ $k ] * $w;
		}
		$score     = (int) round( $score / 100 );
		$strengths = array();
		$gaps      = array();
		if ( $matched ) {
			/* translators: %s: skills */
			$strengths[] = sprintf( __( 'Has %s, which the job asks for.', 'dev3-ai-wp-job-manager' ), implode( ', ', array_slice( $matched, 0, 6 ) ) );
		}
		if ( $missing ) {
			/* translators: %s: skills */
			$gaps[] = sprintf( __( 'Resume does not show: %s.', 'dev3-ai-wp-job-manager' ), implode( ', ', array_slice( $missing, 0, 6 ) ) );
		}
		if ( $req > 0 ) {
			if ( $years >= $req ) {
				/* translators: 1: years, 2: required years */
				$strengths[] = sprintf( __( '%1$s years of experience (the job asks for about %2$d).', 'dev3-ai-wp-job-manager' ), number_format_i18n( $years, 1 ), $req );
			} else {
				/* translators: 1: years, 2: required years */
				$gaps[] = sprintf( __( '%1$s years of experience; the job asks for about %2$d.', 'dev3-ai-wp-job-manager' ), number_format_i18n( $years, 1 ), $req );
			}
		}
		if ( $role >= 60 ) {
			$strengths[] = __( 'Has held a similar role before.', 'dev3-ai-wp-job-manager' );
		} elseif ( $role < 30 && $jw ) {
			$gaps[] = __( 'Previous job titles differ from this role; check transferable experience.', 'dev3-ai-wp-job-manager' );
		}
		if ( $location >= 90 ) {
			$strengths[] = $job['remote'] ? __( 'Remote role: location is not a barrier.', 'dev3-ai-wp-job-manager' ) : __( 'Location fits.', 'dev3-ai-wp-job-manager' );
		} elseif ( $location < 50 ) {
			$gaps[] = __( 'Location may not fit; ask the candidate.', 'dev3-ai-wp-job-manager' );
		}
		return array(
			'score'          => $score,
			'criteria'       => $criteria,
			'strengths'      => $strengths,
			'gaps'           => $gaps,
			'summary'        => $score >= 75 ? __( 'Strong fit on the stated requirements.', 'dev3-ai-wp-job-manager' ) : ( $score >= 50 ? __( 'Partial fit: review the gaps.', 'dev3-ai-wp-job-manager' ) : __( 'Few stated requirements are visible in the resume.', 'dev3-ai-wp-job-manager' ) ),
			'matched_skills' => $matched,
			'missing_skills' => $missing,
			'required_years' => $req,
			'source'         => 'rules',
		);
	}

	/**
	 * Location fit 0-100.
	 *
	 * @param array $job     Listing.
	 * @param array $profile Payload.
	 * @return int
	 */
	public static function location_fit( array $job, array $profile ) {
		if ( $job['remote'] ) {
			return 100;
		}
		$loc  = mb_strtolower( (string) $profile['location'] );
		$want = array_filter( array_map( 'trim', explode( ',', mb_strtolower( $job['location'] ) ) ) );
		if ( '' === $loc || ! $want ) {
			return 70;
		}
		foreach ( $want as $w ) {
			if ( mb_strlen( $w ) > 1 && false !== mb_strpos( $loc, $w ) ) {
				return 100;
			}
		}
		return 40;
	}

	/**
	 * Match one job and resume.
	 *
	 * @param int    $job_id    Listing.
	 * @param int    $resume_id Resume.
	 * @param bool   $use_ai    Use AI.
	 * @param string $context   Context.
	 * @return array|\WP_Error
	 */
	public static function match( $job_id, $resume_id, $use_ai = true, $context = 'admin' ) {
		$job     = Wpjm::get( $job_id );
		$profile = Resumes::profile( $resume_id );
		if ( ! $job || ! $profile ) {
			return new \WP_Error( 'dev3jm_not_found', __( 'Job or resume not found.', 'dev3-ai-wp-job-manager' ), array( 'status' => 404 ) );
		}
		$anon  = self::anonymise( $profile, Resumes::display_name( $resume_id ) );
		$rules = self::rule_score( $job, $anon );
		if ( ! $use_ai || ! Settings::get( 'match_ai', true ) || ! Writer::ai_available( $context ) ) {
			return self::store( $job_id, $resume_id, $rules );
		}
		$res = Prompts::run(
			Prompts_Library::MATCH,
			array(
				'job'       => Wpjm::to_text( $job ),
				'candidate' => self::to_text( $anon ),
				'precheck'  => wp_json_encode(
					array(
						'criteria'       => $rules['criteria'],
						'matched_skills' => $rules['matched_skills'],
						'missing_skills' => $rules['missing_skills'],
						'required_years' => $rules['required_years'],
					)
				),
				'language'  => Prompts_Library::language(),
			),
			$context
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$ai       = is_array( $res['data'] ) ? $res['data'] : array();
		$clamp    = static fn( $v ) => min( 100, max( 0, (int) $v ) );
		$criteria = array();
		foreach ( array_keys( self::WEIGHTS ) as $k ) {
			$criteria[ $k ] = isset( $ai['criteria'][ $k ] ) ? (int) round( ( $rules['criteria'][ $k ] + $clamp( $ai['criteria'][ $k ] ) ) / 2 ) : $rules['criteria'][ $k ];
		}
		$list     = static function ( $key ) use ( $ai ) {
			return array_slice( array_values( array_filter( array_map( 'sanitize_text_field', isset( $ai[ $key ] ) && is_array( $ai[ $key ] ) ? array_map( 'strval', $ai[ $key ] ) : array() ) ) ), 0, 4 );
		};
		$ai_score = isset( $ai['score'] ) ? $clamp( $ai['score'] ) : $rules['score'];
		return self::store(
			$job_id,
			$resume_id,
			array_merge(
				$rules,
				array(
					'score'      => (int) round( ( $rules['score'] + $ai_score ) / 2 ),
					'rule_score' => $rules['score'],
					'ai_score'   => $ai_score,
					'criteria'   => $criteria,
					'strengths'  => $list( 'strengths' ) ? $list( 'strengths' ) : $rules['strengths'],
					'gaps'       => $list( 'gaps' ) ? $list( 'gaps' ) : $rules['gaps'],
					'summary'    => isset( $ai['summary'] ) && '' !== trim( (string) $ai['summary'] ) ? sanitize_text_field( (string) $ai['summary'] ) : $rules['summary'],
					'source'     => 'ai',
					'model'      => $res['model'],
					'cached'     => ! empty( $res['cached'] ),
				)
			)
		);
	}

	/**
	 * Stores a match result.
	 *
	 * @param int   $job_id    Listing.
	 * @param int   $resume_id Resume.
	 * @param array $result    Result.
	 * @return array
	 */
	private static function store( $job_id, $resume_id, array $result ) {
		global $wpdb;
		$table = Tables::name( 'matches' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (job_id, resume_id, score, source, result, created_at) VALUES (%d, %d, %d, %s, %s, %s)", $job_id, $resume_id, $result['score'], $result['source'], wp_json_encode( $result ), current_time( 'mysql', true ) ) );
		$result['resume_id'] = (int) $resume_id;
		$result['scored_at'] = mysql_to_rfc3339( current_time( 'mysql', true ) );
		return $result;
	}

	/**
	 * Ranked resumes for a job: stored results first, the rest scored by rules on the fly.
	 *
	 * @param int $job_id Listing.
	 * @param int $limit  Max resumes considered.
	 * @return array [ { resume_id, name, headline, location, years, skills, score, criteria, strengths, gaps, summary, source, edit_url } ]
	 */
	public static function ranked( $job_id, $limit = 200 ) {
		global $wpdb;
		$job = Wpjm::get( $job_id );
		if ( ! $job ) {
			return array();
		}
		$table  = Tables::name( 'matches' );
		$stored = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT resume_id, result, created_at FROM {$table} WHERE job_id = %d", $job_id ) ) as $r ) {
			$res = json_decode( (string) $r->result, true );
			if ( is_array( $res ) ) {
				$res['scored_at']              = mysql_to_rfc3339( $r->created_at );
				$stored[ (int) $r->resume_id ] = $res;
			}
		}
		$rows = array();
		foreach ( Resumes::ids( $limit ) as $rid ) {
			$profile = Resumes::profile( $rid );
			if ( ! $profile ) {
				continue;
			}
			$result = isset( $stored[ $rid ] ) ? $stored[ $rid ] : self::rule_score( $job, self::anonymise( $profile, Resumes::display_name( $rid ) ) );
			$rows[] = array_merge(
				$result,
				array(
					'resume_id' => $rid,
					'name'      => Resumes::display_name( $rid ),
					'headline'  => $profile['headline'],
					'location'  => $profile['location'],
					'years'     => $profile['years'],
					'skills'    => array_slice( $profile['skills'], 0, 8 ),
					'stored'    => isset( $stored[ $rid ] ),
					'edit_url'  => (string) get_edit_post_link( $rid, 'raw' ),
				)
			);
		}
		usort( $rows, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return $rows;
	}
}
