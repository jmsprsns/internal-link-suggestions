<?php
/**
 * Plugin Name:       Internal Link Suggestions
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Suggests internal links while you write, finds older posts that should link to new ones, and reports orphaned content.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       internal-link-suggestions
 * Update URI:        https://github.com/jmsprsns/internal-link-suggestions
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$internal_link_suggestions_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'INTERNAL_LINK_SUGGESTIONS_VERSION', $internal_link_suggestions_headers['version'] );
define( 'INTERNAL_LINK_SUGGESTIONS_REPO', $internal_link_suggestions_headers['repo'] );
define( 'INTERNAL_LINK_SUGGESTIONS_FILE', __FILE__ );
define( 'INTERNAL_LINK_SUGGESTIONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'INTERNAL_LINK_SUGGESTIONS_URL', plugin_dir_url( __FILE__ ) );

unset( $internal_link_suggestions_headers );

require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-icons.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-updater.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-menu.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-feedback.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-text.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-db.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-indexer.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-matcher.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-linker.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-rest.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-editor.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-list-table.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-admin.php';
require_once INTERNAL_LINK_SUGGESTIONS_DIR . 'includes/class-internal-link-suggestions-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Internal_Link_Suggestions_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Internal_Link_Suggestions_Plugin
 */
function internal_link_suggestions_plugin() {
	return Internal_Link_Suggestions_Plugin::instance();
}
add_action( 'plugins_loaded', 'internal_link_suggestions_plugin' );

/**
 * Create the index tables and start the first scan.
 *
 * Runs again by itself after an update that changes the tables (see
 * Internal_Link_Suggestions_DB::maybe_upgrade()), so activation isn't the
 * only way in.
 *
 * @return void
 */
function internal_link_suggestions_activate() {
	Internal_Link_Suggestions_DB::install();
	Internal_Link_Suggestions_Indexer::start_scan();
}
register_activation_hook( INTERNAL_LINK_SUGGESTIONS_FILE, 'internal_link_suggestions_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and the index stay put (uninstall.php removes them); only the
 * background scan stops.
 *
 * @return void
 */
function internal_link_suggestions_deactivate() {
	wp_clear_scheduled_hook( Internal_Link_Suggestions_Indexer::SCAN_HOOK );
	delete_option( Internal_Link_Suggestions_Indexer::LOCK_OPTION );
}
register_deactivation_hook( INTERNAL_LINK_SUGGESTIONS_FILE, 'internal_link_suggestions_deactivate' );
