<?php
/**
 * Personal data: match scores linked to Resume Manager resumes (found by the resume's
 * `_candidate_email`). Exported and erased with the WordPress privacy tools; removed when a
 * resume or a job listing is deleted.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager;

use Dev3\AiWpJobManager\Data\Tables;
use Dev3\AiWpJobManager\Wpjm\Resumes;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static callbacks.
 */
class Privacy_Data {

	/**
	 * Resume ids of an email.
	 *
	 * @param string $email Email.
	 * @return int[]
	 */
	public static function resume_ids( $email ) {
		if ( ! post_type_exists( Resumes::PT ) || ! is_email( $email ) ) {
			return array();
		}
		$ids  = get_posts(
			array(
				'post_type'      => Resumes::PT,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_key'       => '_candidate_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge(
				$ids,
				get_posts(
					array(
						'post_type'      => Resumes::PT,
						'post_status'    => 'any',
						'posts_per_page' => 50,
						'fields'         => 'ids',
						'author'         => $user->ID,
					)
				)
			);
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$items = array();
		foreach ( self::resume_ids( $email ) as $rid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT job_id, score, source, created_at FROM ' . Tables::name( 'matches' ) . ' WHERE resume_id = %d', $rid ) );
			foreach ( (array) $rows as $r ) {
				$items[] = array(
					'group_id'    => 'dev3jm-matches',
					'group_label' => __( 'Candidate match scores', 'dev3-ai-wp-job-manager' ),
					'item_id'     => 'dev3jm-match-' . $rid . '-' . $r->job_id,
					'data'        => array(
						array(
							'name'  => __( 'Job', 'dev3-ai-wp-job-manager' ),
							'value' => get_the_title( (int) $r->job_id ),
						),
						array(
							'name'  => __( 'Score', 'dev3-ai-wp-job-manager' ),
							'value' => (int) $r->score,
						),
						array(
							'name'  => __( 'Method', 'dev3-ai-wp-job-manager' ),
							'value' => 'ai' === $r->source ? __( 'Rules + DEV3 AI explanation', 'dev3-ai-wp-job-manager' ) : __( 'Rules', 'dev3-ai-wp-job-manager' ),
						),
						array(
							'name'  => __( 'Date', 'dev3-ai-wp-job-manager' ),
							'value' => $r->created_at,
						),
					),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Eraser.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$removed = 0;
		foreach ( self::resume_ids( $email ) as $rid ) {
			$removed += (int) $wpdb->delete( Tables::name( 'matches' ), array( 'resume_id' => $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Cleans match rows and drafts when a resume or job is deleted.
	 *
	 * @param int $post_id Post.
	 */
	public static function on_delete( $post_id ) {
		global $wpdb;
		$type = get_post_type( $post_id );
		if ( Resumes::PT === $type ) {
			$wpdb->delete( Tables::name( 'matches' ), array( 'resume_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( Wpjm::PT === $type ) {
			$wpdb->delete( Tables::name( 'matches' ), array( 'job_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Tables::name( 'drafts' ), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
