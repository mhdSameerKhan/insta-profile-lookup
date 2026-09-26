<?php
/**
 * Instagram Lookup Admin Settings
 *
 * Provides a clean settings screen in WordPress Admin under Settings > Instagram Lookup.
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insta_Admin {

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_insta_lookup_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
	}

	/**
	 * Add options page under Settings.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'Instagram Lookup Settings', 'insta-profile-lookup' ),
			__( 'Instagram Lookup', 'insta-profile-lookup' ),
			'manage_options',
			'insta-lookup-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings and fields.
	 */
	public static function register_settings() {
		register_setting( 'insta_lookup_options_group', 'insta_lookup_cache_ttl', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 3600,
		) );

		register_setting( 'insta_lookup_options_group', 'insta_lookup_session_id', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_session_id' ),
			'default'           => '',
		) );
	}

	/**
	 * Handle clear cache action.
	 */
	public static function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'insta-profile-lookup' ) );
		}
		check_admin_referer( 'insta_clear_cache_action', 'insta_cache_nonce' );

		// Clear transients across object caches and database
		Insta_Cache::clear_all();

		wp_safe_redirect( add_query_arg( array( 'page' => 'insta-lookup-settings', 'cache_cleared' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache_ttl      = (int) get_option( 'insta_lookup_cache_ttl', 3600 );
		$is_const_set   = defined( 'INSTA_SESSION_ID' ) && ! empty( INSTA_SESSION_ID );
		$has_stored_sid = ! empty( get_option( 'insta_lookup_session_id', '' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Instagram Public Profile Lookup Settings', 'insta-profile-lookup' ); ?></h1>

			<?php if ( isset( $_GET['cache_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'All cached Instagram profile data has been cleared successfully.', 'insta-profile-lookup' ); ?></p>
				</div>
			<?php endif; ?>

			<div style="display: flex; gap: 24px; margin-top: 20px;">
				<div style="flex: 2; background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px;">
					<form method="post" action="options.php">
						<?php
						settings_fields( 'insta_lookup_options_group' );
						do_settings_sections( 'insta_lookup_options_group' );
						?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="insta_lookup_cache_ttl"><?php esc_html_e( 'Cache Duration', 'insta-profile-lookup' ); ?></label>
								</th>
								<td>
									<select name="insta_lookup_cache_ttl" id="insta_lookup_cache_ttl">
										<option value="900" <?php selected( $cache_ttl, 900 ); ?>><?php esc_html_e( '15 Minutes', 'insta-profile-lookup' ); ?></option>
										<option value="3600" <?php selected( $cache_ttl, 3600 ); ?>><?php esc_html_e( '1 Hour (Recommended)', 'insta-profile-lookup' ); ?></option>
										<option value="21600" <?php selected( $cache_ttl, 21600 ); ?>><?php esc_html_e( '6 Hours', 'insta-profile-lookup' ); ?></option>
										<option value="86400" <?php selected( $cache_ttl, 86400 ); ?>><?php esc_html_e( '24 Hours', 'insta-profile-lookup' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'How long looked-up profile data should be cached to prevent Instagram rate limits.', 'insta-profile-lookup' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="insta_lookup_session_id"><?php esc_html_e( 'Instagram Session ID (Optional)', 'insta-profile-lookup' ); ?></label>
								</th>
								<td>
									<?php if ( $is_const_set ) : ?>
										<input
											type="password"
											id="insta_lookup_session_id"
											value="••••••••••••••••••••"
											class="regular-text"
											disabled="disabled"
										/>
										<p class="description">
											<?php esc_html_e( 'Configured securely via INSTA_SESSION_ID in wp-config.php. Stored database option is bypassed.', 'insta-profile-lookup' ); ?>
										</p>
									<?php else : ?>
										<input
											type="password"
											name="insta_lookup_session_id"
											id="insta_lookup_session_id"
											value=""
											placeholder="<?php echo $has_stored_sid ? esc_attr__( '•••••••••••••••• (Configured — leave blank to keep)', 'insta-profile-lookup' ) : esc_attr__( 'Enter session ID...', 'insta-profile-lookup' ); ?>"
											class="regular-text"
											autocomplete="new-password"
										/>
										<?php if ( $has_stored_sid ) : ?>
											<p style="margin-top: 6px;">
												<label>
													<input type="checkbox" name="insta_lookup_clear_session_id" value="1" />
													<?php esc_html_e( 'Remove saved session ID', 'insta-profile-lookup' ); ?>
												</label>
											</p>
										<?php endif; ?>
										<p class="description">
											<?php esc_html_e( 'Optional. Enter an active Instagram "sessionid" cookie to bypass public scraping limits and unlock full Web API access. If left blank, anonymous public crawler scraping is used. Values are encrypted at rest using AES-256 and never output in the page markup. Alternatively, define INSTA_SESSION_ID in wp-config.php.', 'insta-profile-lookup' ); ?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<?php submit_button(); ?>
					</form>

					<hr style="margin: 24px 0; border: 0; border-top: 1px solid #eee;" />

					<h3><?php esc_html_e( 'Clear Cached Data', 'insta-profile-lookup' ); ?></h3>
					<p><?php esc_html_e( 'Flush all temporarily stored profile lookups and force fresh live queries on next search.', 'insta-profile-lookup' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="insta_lookup_clear_cache" />
						<?php wp_nonce_field( 'insta_clear_cache_action', 'insta_cache_nonce' ); ?>
						<?php submit_button( __( 'Purge Profile Cache', 'insta-profile-lookup' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div style="flex: 1; background: #fafafa; padding: 24px; border: 1px solid #ccd0d4; border-radius: 4px;">
					<h3><?php esc_html_e( 'How to Display', 'insta-profile-lookup' ); ?></h3>
					<p><?php esc_html_e( 'Add the following shortcode to any WordPress page, post, or widget area:', 'insta-profile-lookup' ); ?></p>
					<p><code>[instagram_profile_lookup]</code></p>
					<p class="description"><?php esc_html_e( 'Or use the shorthand [insta_lookup].', 'insta-profile-lookup' ); ?></p>

					<hr style="margin: 16px 0; border: 0; border-top: 1px solid #ddd;" />

					<h4><?php esc_html_e( 'REST API Endpoints', 'insta-profile-lookup' ); ?></h4>
					<p><code>GET /wp-json/insta-lookup/v1/profile/{handle}</code></p>
					<p><code>GET /wp-json/insta-lookup/v1/profile?username={handle}</code></p>
					<p class="description"><?php esc_html_e( 'Returns structured JSON with followers, posts, verification, bio, media, and proxied image URLs.', 'insta-profile-lookup' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize and encrypt session ID before storing in wp_options.
	 *
	 * @param string $value Raw session ID from form submission.
	 * @return string Encrypted session ID or empty string.
	 */
	public static function sanitize_session_id( $value ) {
		// If user checked "Remove saved session ID"
		if ( ! empty( $_POST['insta_lookup_clear_session_id'] ) ) {
			return '';
		}

		$clean = sanitize_text_field( $value );

		// If blank was submitted, retain the existing stored value
		if ( '' === $clean ) {
			return get_option( 'insta_lookup_session_id', '' );
		}

		return self::encrypt_value( $clean );
	}

	/**
	 * Get the decrypted Instagram session ID for use in API calls.
	 * Checks for a PHP constant override first, then falls back to the
	 * encrypted wp_options value.
	 *
	 * @return string Decrypted session ID.
	 */
	public static function get_session_id() {
		if ( defined( 'INSTA_SESSION_ID' ) ) {
			return INSTA_SESSION_ID;
		}
		$stored = get_option( 'insta_lookup_session_id', '' );
		return self::decrypt_value( $stored );
	}

	/**
	 * Encrypt a value using AES-256-CBC with the site auth salt as key.
	 * The IV is prepended to the ciphertext and the whole blob is base64-encoded
	 * and prefixed with 'enc:' to distinguish it from legacy plaintext values.
	 *
	 * @param string $value Plaintext value to encrypt.
	 * @return string Encrypted value prefixed with 'enc:', or original value on failure.
	 */
	private static function encrypt_value( $value ) {
		if ( empty( $value ) || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$key       = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
		$iv        = function_exists( 'random_bytes' ) ? random_bytes( $iv_length ) : openssl_random_pseudo_bytes( $iv_length );
		$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $value;
		}
		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a stored value. Handles both encrypted ('enc:' prefix) and
	 * legacy plaintext values for backward compatibility during migration.
	 *
	 * @param string $stored Stored value from database.
	 * @return string Decrypted plaintext value.
	 */
	private static function decrypt_value( $stored ) {
		if ( empty( $stored ) ) {
			return '';
		}
		// Legacy plaintext values (no enc: prefix) — return as-is
		if ( 0 !== strpos( $stored, 'enc:' ) ) {
			return $stored;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$key       = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$raw       = base64_decode( substr( $stored, 4 ) );
		$iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
		if ( strlen( $raw ) <= $iv_length ) {
			return '';
		}
		$iv        = substr( $raw, 0, $iv_length );
		$encrypted = substr( $raw, $iv_length );
		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false !== $decrypted ? $decrypted : '';
	}
}
