<?php
/**
 * Bulk improve: a core background job that builds one review draft per listing from the chosen
 * operations. It never writes to a listing; the Review queue does that on Accept.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Core\Jobs;
use Dev3\AiWpJobManager\Data\Drafts;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Improver {

	const JOB = 'dev3jm_bulk';

	const OPS = array( 'description', 'inclusive', 'salary', 'title', 'taxonomy' );

	const FILTERS = array( 'all', 'low', 'no_salary', 'no_type', 'inclusive', 'selected' );

	/**
	 * Listing ids for a filter.
	 *
	 * @param string $filter Filter.
	 * @param int[]  $ids    Selected ids (filter "selected").
	 * @return int[]
	 */
	public static function targets( $filter, array $ids = array() ) {
		if ( 'selected' === $filter ) {
			return array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => Wpjm::PT === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) );
		}
		$args = array(
			'post_type'      => Wpjm::PT,
			'post_status'    => array( 'publish', 'pending', 'draft', 'expired' ),
			'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$all  = array_map( 'intval', get_posts( $args ) );
		if ( 'all' === $filter ) {
			return $all;
		}
		$out = array();
		foreach ( $all as $id ) {
			$q = get_post_meta( $id, '_dev3jm_quality', true );
			if ( '' === $q ) {
				\Dev3\AiWpJobManager\Quality\Quality::refresh( $id );
				$q = get_post_meta( $id, '_dev3jm_quality', true );
			}
			$issues = (array) get_post_meta( $id, '_dev3jm_quality_issues', true );
			$hit    = false;
			if ( 'low' === $filter ) {
				$hit = (int) $q < 70;
			} elseif ( 'no_salary' === $filter ) {
				$hit = in_array( 'salary', $issues, true );
			} elseif ( 'no_type' === $filter ) {
				$hit = in_array( 'type', $issues, true ) || in_array( 'tags', $issues, true );
			} elseif ( 'inclusive' === $filter ) {
				$hit = (int) get_post_meta( $id, '_dev3jm_inclusive', true ) > 0;
			}
			if ( $hit ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Starts a batch.
	 *
	 * @param string $filter Filter.
	 * @param int[]  $ids    Selected.
	 * @param array  $ops    Operations.
	 * @param bool   $use_ai Use AI where available.
	 * @return array|\WP_Error Job.
	 */
	public static function start( $filter, array $ids, array $ops, $use_ai = true ) {
		$ops = array_values( array_intersect( self::OPS, $ops ) );
		if ( ! $ops ) {
			return new \WP_Error( 'dev3jm_no_ops', __( 'Pick at least one improvement.', 'dev3-ai-wp-job-manager' ), array( 'status' => 400 ) );
		}
		$targets = self::targets( in_array( $filter, self::FILTERS, true ) ? $filter : 'all', $ids );
		if ( ! $targets ) {
			return new \WP_Error( 'dev3jm_nothing', __( 'No listings match this selection.', 'dev3-ai-wp-job-manager' ), array( 'status' => 400 ) );
		}
		$id = Jobs::create(
			self::JOB,
			$targets,
			array(
				'ops'    => $ops,
				'use_ai' => (bool) $use_ai,
				'filter' => $filter,
			),
			(int) Settings::get( 'bulk_chunk', 3 )
		);
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Listing ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$ops    = isset( $job['args']['ops'] ) ? (array) $job['args']['ops'] : self::OPS;
		$use_ai = ! empty( $job['args']['use_ai'] );
		$out    = array();
		foreach ( $items as $id ) {
			$res = self::draft_for( (int) $id, $ops, $use_ai, 'cron' );
			if ( is_wp_error( $res ) ) {
				$out[ $id ] = $res;
				continue;
			}
			if ( ! $res['draft'] ) {
				$out[ $id ] = true; // Nothing to improve.
				continue;
			}
			Drafts::create( (int) $id, $res['draft'], $res['original'], $res['meta'], (int) $job['id'] );
			$out[ $id ] = true;
		}
		return $out;
	}

	/**
	 * Builds the draft for one listing.
	 *
	 * @param int    $id      Listing.
	 * @param array  $ops     Operations.
	 * @param bool   $use_ai  Use AI.
	 * @param string $context Context.
	 * @return array|\WP_Error { draft, original, meta }
	 */
	public static function draft_for( $id, array $ops, $use_ai = true, $context = 'cron' ) {
		$job = Wpjm::get( $id );
		if ( ! $job ) {
			return new \WP_Error( 'dev3jm_not_found', __( 'Job listing not found.', 'dev3-ai-wp-job-manager' ) );
		}
		$draft    = array();
		$meta     = array(
			'sources' => array(),
			'tokens'  => 0,
			'notes'   => array(),
		);
		$original = array(
			'title'   => $job['title'],
			'content' => $job['content'],
			'salary'  => array(
				'label'    => $job['salary'],
				'currency' => $job['salary_currency'],
				'unit'     => $job['salary_unit'],
			),
			'types'   => wp_list_pluck( $job['types'], 'slug' ),
			'tags'    => wp_list_pluck( $job['tags'], 'name' ),
		);
		$track    = static function ( $key, array $res ) use ( &$meta ) {
			$meta['sources'][ $key ] = isset( $res['source'] ) ? $res['source'] : 'rules';
			if ( ! empty( $res['meta']['tokens'] ) ) {
				$meta['tokens'] += (int) $res['meta']['tokens'];
			}
			if ( ! empty( $res['meta']['model'] ) ) {
				$meta['model'] = $res['meta']['model'];
			}
		};

		if ( in_array( 'title', $ops, true ) || in_array( 'salary', $ops, true ) ) {
			$n = Normaliser::run( $job, $context, $use_ai );
			$track( 'normalise', $n );
			if ( in_array( 'title', $ops, true ) && $n['title'] !== $job['title'] ) {
				$draft['title'] = $n['title'];
			}
			if ( in_array( 'title', $ops, true ) && '' !== $n['seniority'] && $n['seniority'] !== $job['seniority'] ) {
				$draft['seniority'] = $n['seniority'];
			}
			if ( in_array( 'salary', $ops, true ) && $n['salary'] && ( $n['salary']['label'] !== $job['salary'] || $n['salary']['currency'] !== $job['salary_currency'] || $n['salary']['unit'] !== $job['salary_unit'] ) ) {
				$draft['salary'] = $n['salary'];
			}
			if ( in_array( 'salary', $ops, true ) && ! $n['salary'] ) {
				$meta['notes'][] = '' === trim( $job['salary'] ) ? __( 'No salary given: add one by hand.', 'dev3-ai-wp-job-manager' ) : __( 'Salary has no amount to normalise.', 'dev3-ai-wp-job-manager' );
			}
		}
		if ( in_array( 'description', $ops, true ) ) {
			$w = Writer::draft( Writer::input_from_listing( $job ), $context, ! $use_ai );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
			$track( 'description', $w );
			$draft['content'] = $w['content'];
		}
		if ( in_array( 'inclusive', $ops, true ) ) {
			$check = Inclusive::check( isset( $draft['content'] ) ? $draft['content'] : $job['content'], $context, $use_ai );
			if ( ! is_wp_error( $check ) ) {
				$track( 'inclusive', $check );
				$fixes = array();
				foreach ( $check['issues'] as $issue ) {
					$fixes[] = array(
						'phrase'     => $issue['phrase'],
						'suggestion' => $issue['suggestion'],
						'reason'     => $issue['reason'],
					);
				}
				if ( $fixes ) {
					if ( isset( $draft['content'] ) ) {
						$draft['content'] = Inclusive::apply( $draft['content'], $fixes );
					} else {
						$draft['fixes'] = $fixes;
					}
				}
			}
		}
		if ( in_array( 'taxonomy', $ops, true ) ) {
			$s = Suggester::run( $job, $context, $use_ai );
			$track( 'taxonomy', $s );
			$types = wp_list_pluck( $s['types'], 'slug' );
			if ( $types && ! $job['types'] ) {
				$draft['types'] = $types;
			}
			$new_tags = array_values( array_diff( array_map( 'strtolower', $s['tags'] ), array_map( 'strtolower', $original['tags'] ) ) );
			if ( $new_tags && taxonomy_exists( Wpjm::TAX_TAG ) ) {
				$draft['tags'] = array_values( array_unique( array_merge( $original['tags'], array_values( array_filter( $s['tags'], static fn( $t ) => in_array( strtolower( $t ), $new_tags, true ) ) ) ) ) );
			}
			$cats = wp_list_pluck( $s['categories'], 'slug' );
			if ( $cats && ! $job['categories'] ) {
				$draft['categories'] = $cats;
			}
		}
		return array(
			'draft'    => $draft,
			'original' => $original,
			'meta'     => $meta,
		);
	}
}
