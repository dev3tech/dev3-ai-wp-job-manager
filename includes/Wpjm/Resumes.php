<?php
/**
 * WPJM Resume Manager bridge (paid add-on). Reads its documented data model: post type `resume`,
 * taxonomies `resume_skill` / `resume_category`, meta `_candidate_title`, `_candidate_location`,
 * `_candidate_experience` (employer, job_title, date, notes), `_candidate_education` (location,
 * qualification, date, notes). Only job-relevant fields are read; name, email and photo are not.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers.
 */
class Resumes {

	const PT        = 'resume';
	const TAX_SKILL = 'resume_skill';
	const TAX_CAT   = 'resume_category';

	/**
	 * Whether Resume Manager (or a compatible resume post type) is active.
	 *
	 * @return bool
	 */
	public static function active() {
		/**
		 * Filters Resume Manager detection.
		 *
		 * @param bool $active Active.
		 */
		return (bool) apply_filters( 'dev3jm_resumes_active', class_exists( 'WP_Resume_Manager' ) || post_type_exists( self::PT ) );
	}

	/**
	 * Resume ids (published + hidden; never drafts).
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	public static function ids( $limit = 500 ) {
		if ( ! self::active() ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::PT,
					'post_status'    => array( 'publish', 'hidden' ),
					'posts_per_page' => min( 1000, max( 1, (int) $limit ) ),
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			)
		);
	}

	/**
	 * Count.
	 *
	 * @return int
	 */
	public static function count() {
		if ( ! self::active() ) {
			return 0;
		}
		$c = wp_count_posts( self::PT );
		return (int) ( isset( $c->publish ) ? $c->publish : 0 ) + (int) ( isset( $c->hidden ) ? $c->hidden : 0 );
	}

	/**
	 * Job-relevant resume profile (allow-list; no name, email, photo, video or links).
	 *
	 * @param int $id Resume.
	 * @return array|null { id, headline, location, summary, skills[], experience[], education[], years, categories[] }
	 */
	public static function profile( $id ) {
		$post = get_post( $id );
		if ( ! $post || self::PT !== $post->post_type ) {
			return null;
		}
		$exp = array();
		foreach ( (array) get_post_meta( $id, '_candidate_experience', true ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$exp[] = array(
				'title'   => isset( $e['job_title'] ) ? sanitize_text_field( (string) $e['job_title'] ) : '',
				'company' => isset( $e['employer'] ) ? sanitize_text_field( (string) $e['employer'] ) : '',
				'date'    => isset( $e['date'] ) ? sanitize_text_field( (string) $e['date'] ) : '',
				'notes'   => isset( $e['notes'] ) ? Wpjm::plain( (string) $e['notes'], 800 ) : '',
			);
		}
		$edu = array();
		foreach ( (array) get_post_meta( $id, '_candidate_education', true ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$edu[] = array(
				'qualification' => isset( $e['qualification'] ) ? sanitize_text_field( (string) $e['qualification'] ) : '',
				'school'        => isset( $e['location'] ) ? sanitize_text_field( (string) $e['location'] ) : '',
				'date'          => isset( $e['date'] ) ? sanitize_text_field( (string) $e['date'] ) : '',
			);
		}
		$skills = taxonomy_exists( self::TAX_SKILL ) ? wp_get_post_terms( $id, self::TAX_SKILL, array( 'fields' => 'names' ) ) : array();
		$cats   = taxonomy_exists( self::TAX_CAT ) ? wp_get_post_terms( $id, self::TAX_CAT, array( 'fields' => 'names' ) ) : array();
		return array(
			'id'         => (int) $id,
			'headline'   => sanitize_text_field( (string) get_post_meta( $id, '_candidate_title', true ) ),
			'location'   => sanitize_text_field( (string) get_post_meta( $id, '_candidate_location', true ) ),
			'summary'    => Wpjm::plain( (string) $post->post_content, 1500 ),
			'skills'     => is_array( $skills ) ? array_values( array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES, 'UTF-8' ), $skills ) ) : array(),
			'experience' => $exp,
			'education'  => $edu,
			'years'      => self::years( $exp ),
			'categories' => is_array( $cats ) ? array_values( $cats ) : array(),
			'modified'   => $post->post_modified_gmt,
		);
	}

	/**
	 * Name shown to the hiring team in the admin (from WP; never sent to AI).
	 *
	 * @param int $id Resume.
	 * @return string
	 */
	public static function display_name( $id ) {
		return html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Total years of experience from free-text date ranges ("2016 - 2019", "Jan 2020 - Present").
	 *
	 * @param array $experience Entries with `date`.
	 * @return float
	 */
	public static function years( array $experience ) {
		$now    = (int) gmdate( 'Y' ) + ( (int) gmdate( 'n' ) - 1 ) / 12;
		$ranges = array();
		foreach ( $experience as $e ) {
			$date = isset( $e['date'] ) ? (string) $e['date'] : '';
			if ( ! preg_match_all( '/\b(19[6-9]\d|20\d{2})\b/', $date, $m ) ) {
				continue;
			}
			$years = array_map( 'intval', $m[1] );
			$start = min( $years );
			$end   = preg_match( '/\b(present|current|now|today|ongoing)\b/i', $date ) ? $now : ( count( $years ) > 1 ? max( $years ) : $start + 1 );
			if ( $end > $start ) {
				$ranges[] = array( $start, $end );
			}
		}
		// Merge overlapping ranges so parallel jobs are not counted twice.
		usort( $ranges, static fn( $a, $b ) => $a[0] <=> $b[0] );
		$total = 0;
		$cur   = null;
		foreach ( $ranges as $r ) {
			if ( null === $cur ) {
				$cur = $r;
			} elseif ( $r[0] <= $cur[1] ) {
				$cur[1] = max( $cur[1], $r[1] );
			} else {
				$total += $cur[1] - $cur[0];
				$cur    = $r;
			}
		}
		if ( null !== $cur ) {
			$total += $cur[1] - $cur[0];
		}
		return round( min( 50, $total ), 1 );
	}
}
