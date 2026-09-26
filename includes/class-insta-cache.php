<?php
/**
 * Instagram Cache Manager
 *
 * Handles transient caching for Instagram profile lookups to prevent rate limiting
 * and optimize performance for repeated queries.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insta_Cache {

	const TRANSIENT_PREFIX = 'insta_prof_';
	const DEFAULT_TTL      = 3600; // 1 hour

	/**
	 * Get cached profile data by username.
	 *
	 * @param string $username
	 * @return array|false
	 */
	public static function get( $username ) {
		$sanitized_user = self::sanitize_key( $username );
		if ( empty( $sanitized_user ) ) {
			return false;
		}

		$cached = get_transient( self::get_transient_key( $sanitized_user ) );
		if ( $cached && is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		return false;
	}

	/**
	 * Save profile data to transient cache.
	 *
	 * @param string $username
	 * @param array  $data
	 * @param int    $ttl
	 * @return bool
	 */
	public static function set( $username, $data, $ttl = null ) {
		$sanitized_user = self::sanitize_key( $username );
		if ( empty( $sanitized_user ) || ! is_array( $data ) ) {
			return false;
		}

		if ( null === $ttl ) {
			$ttl = (int) get_option( 'insta_lookup_cache_ttl', self::DEFAULT_TTL );
			if ( $ttl <= 0 ) {
				$ttl = self::DEFAULT_TTL;
			}
		}

		// Ensure we don't store transient with cached=true flag
		$data['cached'] = false;

		return set_transient( self::get_transient_key( $sanitized_user ), $data, $ttl );
	}

	/**
	 * Delete a cached profile.
	 *
	 * @param string $username
	 * @return bool
	 */
	public static function delete( $username ) {
		$sanitized_user = self::sanitize_key( $username );
		if ( empty( $sanitized_user ) ) {
			return false;
		}

		return delete_transient( self::get_transient_key( $sanitized_user ) );
	}

	/**
	 * Get full transient key with cache versioning.
	 * Versioning guarantees O(1) instant invalidation across Redis, Memcached,
	 * APCu, and database transients.
	 *
	 * @param string $sanitized_user
	 * @return string
	 */
	public static function get_transient_key( $sanitized_user ) {
		$version = (int) get_option( 'insta_lookup_cache_version', 1 );
		return self::TRANSIENT_PREFIX . 'v' . $version . '_' . $sanitized_user;
	}

	/**
	 * Clear all cached profiles across both persistent object caches and DB.
	 * Increments the version counter to invalidate object caches instantly,
	 * then cleans up expired/legacy transient options in the database.
	 *
	 * @return bool
	 */
	public static function clear_all() {
		$version = (int) get_option( 'insta_lookup_cache_version', 1 );
		update_option( 'insta_lookup_cache_version', $version + 1 );

		global $wpdb;
		if ( isset( $wpdb->options ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%',
					$wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%'
				)
			);
		}

		return true;
	}

	/**
	 * Sanitize cache key aligned strictly with Instagram username format.
	 * Avoids stripping characters into collisions.
	 *
	 * @param string $username
	 * @return string Sanitized username or empty string if invalid.
	 */
	public static function sanitize_key( $username ) {
		if ( ! is_string( $username ) ) {
			return '';
		}

		if ( class_exists( 'Insta_Scraper' ) ) {
			$clean = Insta_Scraper::clean_username( $username );
			return false !== $clean ? $clean : '';
		}

		$clean = strtolower( trim( ltrim( trim( $username ), '@' ) ) );
		if ( preg_match( '/^[a-z0-9._]{1,30}$/', $clean ) ) {
			return $clean;
		}

		return '';
	}
}
