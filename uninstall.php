<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiWpJobManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3jm_settings = get_option( 'dev3jm_settings', array() );
if ( empty( $dev3jm_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3jm_usage', 'dev3jm_core_cache', 'dev3jm_core_jobs', 'dev3jm_core_embeddings', 'dev3jm_drafts', 'dev3jm_matches' ) as $dev3jm_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3jm_table}" );
}
foreach ( array( 'dev3jm_settings', 'dev3jm_license', 'dev3jm_db_version' ) as $dev3jm_option ) {
	delete_option( $dev3jm_option );
}
delete_transient( 'dev3jm_models' );
// Our listing meta (WPJM's own salary fields are left as the admin saved them).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_dev3jm\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_unschedule_hook( 'dev3jm_run_job' );
wp_unschedule_hook( 'dev3jm_daily' );
