<?php
/**
 * Public Functions and Developer API
 *
 * Exposes core Instagram data retrieval and cache management functions
 * so themes, custom blocks, or alternative frontends can consume the service.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve public Instagram profile data by handle or profile URL.
 *
 * This is the primary programmatic API for fetching Instagram profiles.
 * It automatically handles transient caching, session authentication (if configured),
 * and fallback scraping.
 *
 * Example:
 *   $profile = insta_lookup_profile( 'natgeo' );
 *   if ( ! is_wp_error( $profile ) ) {
 *       echo esc_html( $profile['full_name'] );
 *   }
 *
 * @param string $username   Instagram username, @handle, or profile URL.
 * @param bool   $skip_cache Optional. Set to true to bypass cache and force fresh lookup. Default false.
 * @return array|WP_Error Normalized profile data array or WP_Error on failure.
 */
function insta_lookup_profile( $username, $skip_cache = false ) {
	$result = Insta_Scraper::get_profile( $username, $skip_cache );

	/**
	 * Filter the profile lookup result before returning.
	 *
	 * @param array|WP_Error $result     The profile data array or WP_Error.
	 * @param string         $username   The requested username.
	 * @param bool           $skip_cache Whether the cache was bypassed.
	 */
	$result = apply_filters( 'insta_lookup_profile_data', $result, $username, $skip_cache );

	if ( ! is_wp_error( $result ) ) {
		/**
		 * Action triggered after a profile is successfully retrieved.
		 *
		 * @param array  $result     The profile data array.
		 * @param string $username   The requested username.
		 * @param bool   $skip_cache Whether the cache was bypassed.
		 */
		do_action( 'insta_lookup_profile_retrieved', $result, $username, $skip_cache );
	}

	return $result;
}

/**
 * Check if a profile is currently stored in the cache.
 *
 * @param string $username Instagram username.
 * @return bool True if cached, false otherwise.
 */
function insta_lookup_is_cached( $username ) {
	return false !== Insta_Cache::get( $username );
}

/**
 * Clear cached data for a specific profile or all profiles.
 *
 * @param string|null $username Optional. Specific username to purge, or null to purge all.
 * @return bool True on success, false on failure.
 */
function insta_lookup_clear_cache( $username = null ) {
	if ( ! empty( $username ) ) {
		return Insta_Cache::delete( $username );
	}

	return Insta_Cache::clear_all();
}

/**
 * Sanitize and extract a clean Instagram username from arbitrary input.
 *
 * @param string $input Username, handle, or full Instagram profile URL.
 * @return string|false Sanitized username or false if invalid.
 */
function insta_lookup_clean_username( $input ) {
	return Insta_Scraper::clean_username( $input );
}

/**
 * Get configured cache TTL duration in seconds.
 *
 * @return int Cache TTL in seconds.
 */
function insta_lookup_get_cache_ttl() {
	$ttl = (int) get_option( 'insta_lookup_cache_ttl', Insta_Cache::DEFAULT_TTL );
	return $ttl > 0 ? $ttl : Insta_Cache::DEFAULT_TTL;
}

/**
 * Get configured Instagram session ID (decrypted).
 *
 * @return string Decrypted session ID or empty string if not configured.
 */
function insta_lookup_get_session_id() {
	return Insta_Admin::get_session_id();
}
