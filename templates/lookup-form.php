<?php
/**
 * Frontend Lookup Form Template
 *
 * @package Insta_Profile_Lookup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wrapper_id = 'insta-lookup-' . wp_unique_id();
?>
<div class="insta-lookup-container" id="<?php echo esc_attr( $wrapper_id ); ?>">
	<!-- Search Box Card -->
	<div class="insta-lookup-card insta-search-card">
		<div class="insta-search-header">
			<div class="insta-brand-badge">
				<svg class="insta-icon-gradient" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
					<path fill="currentColor" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
				</svg>
				<span class="insta-brand-title"><?php esc_html_e( 'Instagram Public Profile Lookup', 'insta-profile-lookup' ); ?></span>
			</div>
			<p class="insta-search-subtitle">
				<?php esc_html_e( 'Look up any public Instagram account details, statistics, and recent posts without logging in.', 'insta-profile-lookup' ); ?>
			</p>
		</div>

		<form class="insta-lookup-form" action="" method="get">
			<div class="insta-input-wrapper">
				<span class="insta-input-at">@</span>
				<input
					type="text"
					name="insta_username"
					class="insta-username-input"
					placeholder="<?php esc_attr_e( 'username or profile URL...', 'insta-profile-lookup' ); ?>"
					autocomplete="off"
					spellcheck="false"
					required
				/>
				<button type="submit" class="insta-submit-btn">
					<span class="btn-text"><?php esc_html_e( 'Search', 'insta-profile-lookup' ); ?></span>
					<span class="btn-spinner" aria-hidden="true"></span>
				</button>
			</div>
		</form>
	</div>

	<!-- Results / Dynamic Mount Area -->
	<div class="insta-mount-area">
		<!-- Initial Empty State -->
		<div class="insta-state-box insta-state-empty">
			<div class="insta-state-icon">
				<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5">
					<circle cx="11" cy="11" r="8"></circle>
					<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
				</svg>
			</div>
			<h4><?php esc_html_e( 'No Profile Loaded', 'insta-profile-lookup' ); ?></h4>
			<p><?php esc_html_e( 'Enter an Instagram username (e.g. natgeo, cristiano) and click Search.', 'insta-profile-lookup' ); ?></p>
		</div>

		<!-- Loading Skeleton State -->
		<div class="insta-state-box insta-state-loading" style="display: none;">
			<div class="insta-skeleton-card">
				<div class="insta-skeleton-header">
					<div class="insta-skeleton-avatar insta-shimmer"></div>
					<div class="insta-skeleton-lines">
						<div class="insta-skeleton-line short insta-shimmer"></div>
						<div class="insta-skeleton-line medium insta-shimmer"></div>
						<div class="insta-skeleton-line long insta-shimmer"></div>
					</div>
				</div>
				<div class="insta-skeleton-stats">
					<div class="insta-skeleton-stat insta-shimmer"></div>
					<div class="insta-skeleton-stat insta-shimmer"></div>
					<div class="insta-skeleton-stat insta-shimmer"></div>
				</div>
				<div class="insta-skeleton-grid">
					<div class="insta-skeleton-item insta-shimmer"></div>
					<div class="insta-skeleton-item insta-shimmer"></div>
					<div class="insta-skeleton-item insta-shimmer"></div>
					<div class="insta-skeleton-item insta-shimmer"></div>
					<div class="insta-skeleton-item insta-shimmer"></div>
					<div class="insta-skeleton-item insta-shimmer"></div>
				</div>
			</div>
		</div>

		<!-- Error State -->
		<div class="insta-state-box insta-state-error" style="display: none;">
			<div class="insta-error-card">
				<div class="insta-error-icon">
					<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"></circle>
						<line x1="12" y1="8" x2="12" y2="12"></line>
						<line x1="12" y1="16" x2="12.01" y2="16"></line>
					</svg>
				</div>
				<div class="insta-error-content">
					<h5 class="insta-error-title"><?php esc_html_e( 'Profile Lookup Failed', 'insta-profile-lookup' ); ?></h5>
					<p class="insta-error-message"></p>
				</div>
			</div>
		</div>

		<!-- Success Result Card Area -->
		<div class="insta-state-box insta-state-result" style="display: none;"></div>
	</div>
</div>
