<?php
/**
 * Review queue: AI/rule drafts for listings. A draft changes nothing until it is accepted.
 *
 * Draft JSON keys: title, seniority, content, salary{min,max,currency,unit,label}, types[slugs],
 * categories[slugs], tags[names], fixes[{phrase,suggestion}] (inclusive fixes applied to content).
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Data;

use Dev3\AiWpJobManager\Ai\Inclusive;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static repository.
 */
class Drafts {

	const STATUSES = array( 'pending', 'accepted', 'rejected', 'failed' );

	/**
	 * Keys accepted by "Accept safe changes" (no text rewrite).
	 */
	const SAFE_KEYS = array( 'title', 'seniority', 'salary', 'types', 'categories', 'tags' );

	/**
	 * Creates a draft.
	 *
	 * @param int    $post_id  Listing.
	 * @param array  $draft    Draft.
	 * @param array  $original Original values.
	 * @param array  $meta     Meta (source, model, tokens, notes).
	 * @param int    $batch_id Core job id.
	 * @param string $status   Status.
	 * @param string $message  Message (failed drafts).
	 * @return int Id.
	 */
	public static function create( $post_id, array $draft, array $original, array $meta = array(), $batch_id = 0, $status = 'pending', $message = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// Replace an older pending draft for the same listing, so the queue holds one per listing.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Tables::name( 'drafts' ) . " WHERE post_id = %d AND status = 'pending'", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Tables::name( 'drafts' ),
			array(
				'post_id'    => (int) $post_id,
				'batch_id'   => (int) $batch_id,
				'ops'        => implode( ',', array_keys( $draft ) ),
				'status'     => in_array( $status, self::STATUSES, true ) ? $status : 'pending',
				'original'   => (string) wp_json_encode( $original ),
				'draft'      => (string) wp_json_encode( $draft ),
				'meta'       => (string) wp_json_encode( $meta ),
				'message'    => mb_substr( (string) $message, 0, 250 ),
				'user_id'    => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Row → array.
	 *
	 * @param object|array|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row = (array) $row;
		return array(
			'id'         => (int) $row['id'],
			'post_id'    => (int) $row['post_id'],
			'batch_id'   => (int) $row['batch_id'],
			'ops'        => array_values( array_filter( explode( ',', (string) $row['ops'] ) ) ),
			'status'     => $row['status'],
			'original'   => (array) json_decode( (string) $row['original'], true ),
			'draft'      => (array) json_decode( (string) $row['draft'], true ),
			'meta'       => (array) json_decode( (string) $row['meta'], true ),
			'message'    => (string) $row['message'],
			'user_id'    => (int) $row['user_id'],
			'created_at' => mysql_to_rfc3339( $row['created_at'] ),
			'updated_at' => mysql_to_rfc3339( $row['updated_at'] ),
			'title'      => html_entity_decode( get_the_title( (int) $row['post_id'] ), ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * One draft.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'drafts' ) . ' WHERE id = %d', $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Pending draft of a listing.
	 *
	 * @param int $post_id Listing.
	 * @return array|null
	 */
	public static function pending_for( $post_id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( 'drafts' ) . " WHERE post_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1", $post_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Query.
	 *
	 * @param array $args { status, batch_id, page, per_page }.
	 * @return array { items, total, counts }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = Tables::name( 'drafts' );
		$status = isset( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : 'pending';
		$batch  = isset( $args['batch_id'] ) ? (int) $args['batch_id'] : 0;
		$per    = isset( $args['per_page'] ) ? min( 100, max( 1, (int) $args['per_page'] ) ) : 20;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$where  = $wpdb->prepare( 'status = %s', $status );
		if ( $batch ) {
			$where .= $wpdb->prepare( ' AND batch_id = %d', $batch );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) );
		// phpcs:enable
		return array(
			'items'  => array_values( array_filter( array_map( array( __CLASS__, 'hydrate' ), (array) $rows ) ) ),
			'total'  => $total,
			'counts' => self::counts(),
		);
	}

	/**
	 * Count per status.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . Tables::name( 'drafts' ) . ' GROUP BY status' ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Updates a draft row.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Columns.
	 */
	private static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Tables::name( 'drafts' ), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Turns a draft into fields for Wpjm::apply (fixes become content).
	 *
	 * @param array $draft Draft JSON.
	 * @param int   $post_id Listing.
	 * @return array
	 */
	public static function to_fields( array $draft, $post_id ) {
		$fields = array_intersect_key( $draft, array_flip( array( 'title', 'content', 'salary', 'seniority', 'types', 'categories', 'tags' ) ) );
		if ( ! empty( $draft['fixes'] ) && is_array( $draft['fixes'] ) ) {
			$base              = isset( $fields['content'] ) ? (string) $fields['content'] : (string) get_post_field( 'post_content', $post_id );
			$fields['content'] = Inclusive::apply( $base, $draft['fixes'] );
		}
		return $fields;
	}

	/**
	 * Accepts a draft (optionally with the admin's edits).
	 *
	 * @param int        $id     Draft.
	 * @param array|null $edited Edited draft.
	 * @return array|\WP_Error Updated listing.
	 */
	public static function accept( $id, $edited = null ) {
		$d = self::get( $id );
		if ( ! $d || 'pending' !== $d['status'] ) {
			return new \WP_Error( 'dev3jm_not_found', __( 'This draft is no longer pending.', 'dev3-ai-wp-job-manager' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $d['post_id'] ) ) {
			return new \WP_Error( 'dev3jm_forbidden', __( 'You cannot edit this listing.', 'dev3-ai-wp-job-manager' ), array( 'status' => 403 ) );
		}
		$draft = is_array( $edited ) ? array_intersect_key( $edited, $d['draft'] ) : $d['draft'];
		$res   = Wpjm::apply( $d['post_id'], self::to_fields( $draft, $d['post_id'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::update(
			$id,
			array(
				'status' => 'accepted',
				'draft'  => (string) wp_json_encode( $draft ),
			)
		);
		return $res;
	}

	/**
	 * Rejects a draft (the listing is untouched).
	 *
	 * @param int $id Draft.
	 * @return bool|\WP_Error
	 */
	public static function reject( $id ) {
		$d = self::get( $id );
		if ( ! $d || 'pending' !== $d['status'] ) {
			return new \WP_Error( 'dev3jm_not_found', __( 'This draft is no longer pending.', 'dev3-ai-wp-job-manager' ), array( 'status' => 404 ) );
		}
		self::update( $id, array( 'status' => 'rejected' ) );
		return true;
	}

	/**
	 * Applies the safe parts (title, salary, taxonomy) of every pending draft of a batch (or all).
	 * Drafts that also rewrite the description stay pending with only that part left.
	 *
	 * @param int $batch_id Batch (0 = all).
	 * @return array { applied, kept }
	 */
	public static function accept_safe( $batch_id = 0 ) {
		$applied = 0;
		$kept    = 0;
		$page    = self::query(
			array(
				'status'   => 'pending',
				'batch_id' => $batch_id,
				'per_page' => 100,
			)
		);
		foreach ( $page['items'] as $d ) {
			if ( ! current_user_can( 'edit_post', $d['post_id'] ) ) {
				continue;
			}
			$safe = array_intersect_key( $d['draft'], array_flip( self::SAFE_KEYS ) );
			$rest = array_diff_key( $d['draft'], $safe );
			if ( ! $safe ) {
				++$kept;
				continue;
			}
			$res = Wpjm::apply( $d['post_id'], self::to_fields( $safe, $d['post_id'] ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			++$applied;
			if ( $rest ) {
				++$kept;
				self::update(
					$d['id'],
					array(
						'draft' => (string) wp_json_encode( $rest ),
						'ops'   => implode( ',', array_keys( $rest ) ),
					)
				);
			} else {
				self::update( $d['id'], array( 'status' => 'accepted' ) );
			}
		}
		return array(
			'applied' => $applied,
			'kept'    => $kept,
		);
	}
}
