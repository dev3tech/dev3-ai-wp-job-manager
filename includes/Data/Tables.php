<?php
/**
 * Plugin tables: AI drafts (review queue) and candidate match results.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Static schema helpers.
 */
class Tables {

	const NAMES = array( 'drafts', 'matches' );

	/**
	 * Table name.
	 *
	 * @param string $name drafts|matches.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3jm_' . $name;
	}

	/**
	 * Table statements for dbDelta (hooked to dev3jm_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset.
	 * @return array
	 */
	public static function sql( $sql, $charset ) {
		$drafts  = self::name( 'drafts' );
		$matches = self::name( 'matches' );
		$sql[]   = "CREATE TABLE {$drafts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ops varchar(120) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			original longtext NOT NULL,
			draft longtext NOT NULL,
			meta longtext NOT NULL,
			message varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status_batch (status,batch_id)
		) {$charset};";
		$sql[]   = "CREATE TABLE {$matches} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resume_id bigint(20) unsigned NOT NULL DEFAULT 0,
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			source varchar(10) NOT NULL DEFAULT 'rules',
			result longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_resume (job_id,resume_id),
			KEY job_score (job_id,score)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::NAMES as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::name( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
