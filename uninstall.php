<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Links the plugin added to posts stay: they're ordinary links in the posts'
 * content now, and removing them would change the site's pages.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove one site's tables, options and post meta.
 *
 * @return void
 */
function internal_link_suggestions_uninstall_site() {
	global $wpdb;

	foreach ( array( 'posts', 'links', 'terms', 'log' ) as $name ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ils_' . $name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuerySchemaChange -- Fixed table names.
	}

	foreach ( array( 'internal_link_suggestions_settings', 'internal_link_suggestions_db_version', 'internal_link_suggestions_scan', 'internal_link_suggestions_scan_lock' ) as $option ) {
		delete_option( $option );
	}

	delete_post_meta_by_key( '_ils_keywords' );
	delete_post_meta_by_key( '_ils_exclude' );
	delete_metadata( 'user', 0, 'internal_link_suggestions_per_page', '', true );

	wp_clear_scheduled_hook( 'internal_link_suggestions_scan' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $internal_link_suggestions_site ) {
		switch_to_blog( $internal_link_suggestions_site );
		internal_link_suggestions_uninstall_site();
		restore_current_blog();
	}
} else {
	internal_link_suggestions_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-internal-link-suggestions' );
