<?php
/**
 * Fired when the plugin is uninstalled via WordPress admin.
 *
 * Cleans up options and transients stored in the database.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear all transient caches
require_once plugin_dir_path( __FILE__ ) . 'includes/class-insta-cache.php';
Insta_Cache::clear_all();

// Delete stored plugin options
delete_option( 'insta_lookup_cache_ttl' );
delete_option( 'insta_lookup_session_id' );
delete_option( 'insta_lookup_cache_version' );
