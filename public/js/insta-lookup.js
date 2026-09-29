/**
 * Instagram Public Profile Lookup JavaScript
 * Handles dynamic async requests, state transitions, and responsive DOM rendering.
 */

(function () {
	'use strict';

	/**
	 * Initialize all lookup instances on DOM ready.
	 */
	function initLookupInstances() {
		var containers = document.querySelectorAll('.insta-lookup-container');
		containers.forEach(function (container) {
			setupContainer(container);
		});
	}

	/**
	 * Bind events and state management for a container.
	 *
	 * @param {HTMLElement} container
	 */
	function setupContainer(container) {
		var form = container.querySelector('.insta-lookup-form');
		var input = container.querySelector('.insta-username-input');
		var submitBtn = container.querySelector('.insta-submit-btn');

		var emptyBox = container.querySelector('.insta-state-empty');
		var loadingBox = container.querySelector('.insta-state-loading');
		var errorBox = container.querySelector('.insta-state-error');
		var errorMessage = container.querySelector('.insta-error-message');
		var resultBox = container.querySelector('.insta-state-result');
		var currentController = null;

		var settings = window.instaLookupSettings || {
			apiUrl: '/wp-json/insta-lookup/v1/profile',
			proxyUrl: '/wp-json/insta-lookup/v1/image-proxy',
			nonce: '',
			strings: {}
		};

		/**
		 * Switch UI State: 'empty', 'loading', 'error', 'result'
		 */
		function setState(state, errorText) {
			if (emptyBox) emptyBox.style.display = (state === 'empty') ? 'block' : 'none';
			if (loadingBox) loadingBox.style.display = (state === 'loading') ? 'block' : 'none';
			if (errorBox) {
				errorBox.style.display = (state === 'error') ? 'block' : 'none';
				if (errorMessage && errorText) {
					errorMessage.textContent = errorText;
				}
			}
			if (resultBox) resultBox.style.display = (state === 'result') ? 'block' : 'none';

			if (submitBtn) {
				if (state === 'loading') {
					submitBtn.classList.add('is-loading');
					submitBtn.setAttribute('disabled', 'disabled');
				} else {
					submitBtn.classList.remove('is-loading');
					submitBtn.removeAttribute('disabled');
				}
			}
		}

		/**
		 * Execute lookup request.
		 */
		function performLookup(username, forceRefresh) {
			var clean = cleanUsernameInput(username);
			if (!clean) {
				setState('error', (settings.strings && settings.strings.enterUsername) || 'Please enter a valid Instagram username.');
				return;
			}

			setState('loading');

			// Cancel any in-flight request to prevent race conditions
			if (currentController) {
				currentController.abort();
			}
			currentController = new AbortController();

			var requestUrl = new URL(settings.apiUrl, window.location.origin);
			requestUrl.searchParams.set('username', clean);
			if (forceRefresh) {
				requestUrl.searchParams.set('refresh', '1');
			}

			fetch(requestUrl.toString(), {
				method: 'GET',
				signal: currentController.signal,
				headers: {
					'Accept': 'application/json',
					'X-WP-Nonce': settings.nonce || ''
				}
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, status: res.status, data: data };
					});
				})
				.then(function (response) {
					if (!response.ok) {
						var msg = (response.data && response.data.message) ?
							response.data.message :
							((settings.strings && settings.strings.errorGeneric) || 'Could not retrieve profile.');
						setState('error', msg);
						return;
					}

					var profile = null;
					if (response.data) {
						if (response.data.data && response.data.data.username) {
							profile = response.data.data;
						} else if (response.data.username) {
							profile = response.data;
						}
					}
					if (!profile || !profile.username) {
						setState('error', (settings.strings && settings.strings.errorNotFound) || 'Profile not found.');
						return;
					}

					renderProfileCard(resultBox, profile, settings, function () {
						// On refresh click
						performLookup(profile.username, true);
					});

					setState('result');
				})
				.catch(function (err) {
					if (err.name === 'AbortError') {
						return; // Superseded by a newer request
					}
					console.error('Instagram lookup error:', err);
					setState('error', (settings.strings && settings.strings.errorGeneric) || 'Connection error. Please try again.');
				});
		}

		if (form && input) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				if (submitBtn && submitBtn.hasAttribute('disabled')) {
					return;
				}
				performLookup(input.value.trim(), false);
			});
		}
	}

	/**
	 * Sanitize raw input to extract handle.
	 */
	function cleanUsernameInput(val) {
		if (!val) return '';
		var s = val.trim();
		s = s.replace(/\/+$/, '');
		var urlMatch = s.match(/instagram\.com\/([a-zA-Z0-9._]+)/i);
		if (urlMatch && urlMatch[1]) {
			s = urlMatch[1];
		}
		if (s.indexOf('?') !== -1) {
			s = s.split('?')[0];
		}
		s = s.replace(/^@+/, '').trim();
		if (/^[a-zA-Z0-9._]{1,30}$/.test(s)) {
			return s.toLowerCase();
		}
		return '';
	}

	/**
	 * Format number with abbreviations or separators.
	 */
	function formatNumber(num) {
		if (num === null || num === undefined) return '0';
		var n = Number(num);
		if (isNaN(n)) return String(num);

		if (n >= 1000000000) {
			return (n / 1000000000).toFixed(1).replace(/\.0$/, '') + 'B';
		}
		if (n >= 1000000) {
			return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
		}
		if (n >= 10000) {
			return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
		}
		return n.toLocaleString();
	}

	/**
	 * Escape string for safe HTML injection.
	 */
	function escapeHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	/**
	 * Render the full interactive Profile Card.
	 */
	function renderProfileCard(targetElement, profile, settings, onRefresh) {
		var strings = settings.strings || {};
		var followersFormatted = formatNumber(profile.followers_count);
		var followingFormatted = formatNumber(profile.following_count);
		var postsFormatted = formatNumber(profile.posts_count);

		var verifiedBadgeHtml = profile.is_verified ?
			'<span class="insta-badge-verified" title="' + escapeHtml(strings.verified || 'Verified') + '">' +
			'<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">' +
			'<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1.12 14.54l-3.9-3.9 1.41-1.41 2.49 2.49 6.08-6.08 1.41 1.41-7.49 7.49z"/>' +
			'</svg>' +
			'</span>' : '';

		var privacyBadgeHtml = profile.is_private ?
			'<span class="insta-badge-pill insta-pill-private">' +
			'<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>' +
			escapeHtml(strings.private || 'Private') +
			'</span>' :
			'<span class="insta-badge-pill insta-pill-public">' +
			escapeHtml(strings.public || 'Public') +
			'</span>';

		var cacheBadgeHtml = profile.cached ?
			'<span class="insta-badge-pill insta-pill-cache" title="' + escapeHtml(profile.retrieved_at || '') + '">' +
			escapeHtml(strings.cached || 'Cached') +
			'</span>' :
			'<span class="insta-badge-pill insta-pill-cache">' +
			escapeHtml(strings.live || 'Live') +
			'</span>';

		var profileUrl = 'https://www.instagram.com/' + encodeURIComponent(profile.username) + '/';

		// Media grid HTML
		var mediaSectionHtml = '';
		if (profile.is_private) {
			mediaSectionHtml =
				'<div class="insta-private-notice">' +
				'<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">' +
				'<path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>' +
				'</svg>' +
				'<div>' + escapeHtml(strings.privateNotice || 'This account is private. Posts and media are protected.') + '</div>' +
				'</div>';
		} else if (profile.media && profile.media.length > 0) {
			var mediaCardsHtml = '';
			profile.media.forEach(function (m, idx) {
				var videoBadge = m.is_video ?
					'<span class="insta-video-badge"><svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>' : '';
				var captionPreview = m.caption ?
					'<p class="insta-media-caption-preview">' + escapeHtml(m.caption) + '</p>' : '';

				mediaCardsHtml +=
					'<div class="insta-media-card" role="button" tabindex="0"' +
					' data-media-src="' + escapeHtml(m.thumbnail) + '"' +
					' data-media-isvideo="' + (m.is_video ? '1' : '0') + '"' +
					' data-media-caption="' + escapeHtml(m.caption || '') + '"' +
					' data-media-instaurl="' + escapeHtml(m.url || profileUrl) + '"' +
					'>' +
					'<img class="insta-media-thumb" src="' + escapeHtml(m.thumbnail) + '" alt="' + escapeHtml(m.caption || profile.username) + '" referrerpolicy="no-referrer" loading="lazy" data-raw-url="' + escapeHtml(m.thumbnail) + '" />' +
					videoBadge +
					'<div class="insta-media-overlay">' +
					captionPreview +
					'</div>' +
					'</div>';
			});

			mediaSectionHtml =
				'<div class="insta-media-section">' +
				'<div class="insta-media-header">' +
				'<h4>' + escapeHtml(strings.posts || 'Recent Posts') + ' (' + profile.media.length + ')</h4>' +
				'</div>' +
				'<div class="insta-media-grid">' + mediaCardsHtml + '</div>' +
				'</div>';
		} else {
			mediaSectionHtml =
				'<div class="insta-media-section">' +
				'<p class="insta-search-subtitle" style="text-align: center; padding: 20px 0;">' +
				escapeHtml(strings.noMedia || 'No public posts or photos found.') +
				'</p>' +
				'</div>';
		}

		var extUrlHtml = '';
		if (profile.external_url) {
			extUrlHtml =
				'<a class="insta-external-link" href="' + escapeHtml(profile.external_url) + '" target="_blank" rel="noopener noreferrer">' +
				'<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>' +
				escapeHtml(profile.external_url) +
				'</a>';
		}

		var html =
			'<div class="insta-profile-card">' +
			'<div class="insta-profile-header-banner"></div>' +
			'<div class="insta-profile-body">' +
			'<div class="insta-profile-avatar-row">' +
			'<div class="insta-avatar-ring insta-avatar-clickable" role="button" tabindex="0" title="' + escapeHtml(strings.viewProfilePic || 'View profile picture') + '"' +
			' data-media-src="' + escapeHtml(profile.profile_pic_url) + '"' +
			' data-media-isvideo="0"' +
			' data-media-caption="' + escapeHtml((profile.full_name || profile.username) + ' \u2014 Profile Picture') + '"' +
			' data-media-instaurl="' + escapeHtml(profileUrl) + '"' +
			'>' +
			'<img class="insta-avatar-img" src="' + escapeHtml(profile.profile_pic_url) + '" alt="' + escapeHtml(profile.username) + '" referrerpolicy="no-referrer" data-raw-url="' + escapeHtml(profile.profile_pic_url) + '" />' +
			'</div>' +
			'<div class="insta-profile-actions">' +
			'<button type="button" class="insta-refresh-btn" title="' + escapeHtml(strings.refresh || 'Refresh Data') + '">' +
			'<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;"><path d="M23 4v6h-6"></path><path d="M1 20v-6h6"></path><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>' +
			escapeHtml(strings.refresh || 'Refresh') +
			'</button>' +
			'<a class="insta-ext-link-btn" href="' + escapeHtml(profileUrl) + '" target="_blank" rel="noopener noreferrer">' +
			escapeHtml(strings.viewOnInsta || 'View on Instagram') +
			'<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: 4px;"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>' +
			'</a>' +
			'</div>' +
			'</div>' +

			'<div class="insta-user-meta">' +
			'<div class="insta-name-row">' +
			'<h3 class="insta-full-name">' + escapeHtml(profile.full_name || profile.username) + '</h3>' +
			verifiedBadgeHtml +
			'</div>' +
			'<div class="insta-handle-row">' +
			'<span class="insta-handle">@' + escapeHtml(profile.username) + '</span>' +
			privacyBadgeHtml +
			cacheBadgeHtml +
			'</div>' +
			'</div>' +

			'<div class="insta-stats-grid">' +
			'<div class="insta-stat-item" title="' + Number(profile.followers_count).toLocaleString() + '">' +
			'<span class="insta-stat-number">' + followersFormatted + '</span>' +
			'<span class="insta-stat-label">' + escapeHtml(strings.followers || 'Followers') + '</span>' +
			'</div>' +
			'<div class="insta-stat-item" title="' + Number(profile.following_count).toLocaleString() + '">' +
			'<span class="insta-stat-number">' + followingFormatted + '</span>' +
			'<span class="insta-stat-label">' + escapeHtml(strings.following || 'Following') + '</span>' +
			'</div>' +
			'<div class="insta-stat-item" title="' + Number(profile.posts_count).toLocaleString() + '">' +
			'<span class="insta-stat-number">' + postsFormatted + '</span>' +
			'<span class="insta-stat-label">' + escapeHtml(strings.posts || 'Posts') + '</span>' +
			'</div>' +
			'</div>' +

			(profile.biography ?
				'<div class="insta-bio-box">' +
				'<p class="insta-bio-text">' + escapeHtml(profile.biography) + '</p>' +
				extUrlHtml +
				'</div>' : (extUrlHtml ? '<div class="insta-bio-box">' + extUrlHtml + '</div>' : '')) +

			mediaSectionHtml +
			'</div>' +
			'</div>';

		targetElement.innerHTML = html;

		// Bind refresh button
		var refreshBtn = targetElement.querySelector('.insta-refresh-btn');
		if (refreshBtn && typeof onRefresh === 'function') {
			refreshBtn.addEventListener('click', onRefresh);
		}

		// Setup image fallback to proxy if direct hotlink is blocked
		var allImgs = targetElement.querySelectorAll('img[data-raw-url]');
		allImgs.forEach(function (img) {
			img.addEventListener('error', function () {
				if (!img.getAttribute('data-proxied') && settings.proxyUrl) {
					img.setAttribute('data-proxied', 'true');
					var proxySrc = settings.proxyUrl + '?url=' + encodeURIComponent(img.getAttribute('data-raw-url'));
					img.src = proxySrc;
				}
			});
		});

		// Bind lightbox triggers on media cards and profile picture
		var lightboxTriggers = targetElement.querySelectorAll('.insta-media-card[data-media-src], .insta-avatar-clickable[data-media-src]');
		lightboxTriggers.forEach(function (el) {
			el.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				openLightbox({
					src: el.getAttribute('data-media-src'),
					isVideo: el.getAttribute('data-media-isvideo') === '1',
					caption: el.getAttribute('data-media-caption') || '',
					instaUrl: el.getAttribute('data-media-instaurl') || '',
					proxyUrl: settings.proxyUrl || '',
					isProfilePic: el.classList.contains('insta-avatar-clickable')
				});
			});
			// Also handle keyboard (Enter/Space)
			el.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					el.click();
				}
			});
		});
	}

	/* ======================================================================
	   Lightbox Controller
	   ====================================================================== */

	/**
	 * Open lightbox with provided media data.
	 *
	 * @param {Object} data
	 * @param {string} data.src        - Direct media URL.
	 * @param {boolean} data.isVideo   - Whether this is a video.
	 * @param {string} data.caption    - Caption text.
	 * @param {string} data.instaUrl   - Instagram post URL.
	 * @param {string} data.proxyUrl   - WP proxy endpoint URL.
	 */
	function openLightbox(data) {
		var lightbox = document.getElementById('insta-lightbox');
		if (!lightbox) return;

		var mediaContainer = lightbox.querySelector('.insta-lightbox-media');
		var downloadBtn = lightbox.querySelector('.insta-lightbox-download');
		var instaBtn = lightbox.querySelector('.insta-lightbox-instagram');
		var captionEl = lightbox.querySelector('.insta-lightbox-caption');

		// Reset previous content
		mediaContainer.innerHTML = '<div class="insta-lightbox-spinner"></div>';
		if (data.isProfilePic) {
			mediaContainer.classList.add('is-profile-pic');
		} else {
			mediaContainer.classList.remove('is-profile-pic');
		}

		// Determine the best URL to use (try proxy first for cross-origin download support)
		var displaySrc = data.src;
		var proxiedSrc = data.proxyUrl ? data.proxyUrl + '?url=' + encodeURIComponent(data.src) : data.src;

		if (data.isVideo) {
			var video = document.createElement('video');
			video.controls = true;
			video.autoplay = true;
			video.playsInline = true;
			video.preload = 'auto';
			video.src = proxiedSrc;
			video.addEventListener('loadeddata', function () {
				var spinner = mediaContainer.querySelector('.insta-lightbox-spinner');
				if (spinner) spinner.remove();
			});
			video.addEventListener('error', function () {
				// Fallback to direct src if proxy fails
				if (video.src !== displaySrc) {
					video.src = displaySrc;
				}
				var spinner = mediaContainer.querySelector('.insta-lightbox-spinner');
				if (spinner) spinner.remove();
			});
			mediaContainer.appendChild(video);
		} else {
			var img = document.createElement('img');
			img.alt = data.caption || 'Instagram media';
			img.src = proxiedSrc;
			img.addEventListener('load', function () {
				var spinner = mediaContainer.querySelector('.insta-lightbox-spinner');
				if (spinner) spinner.remove();
			});
			img.addEventListener('error', function () {
				// Fallback to direct src if proxy fails
				if (img.src !== displaySrc) {
					img.src = displaySrc;
				}
				var spinner = mediaContainer.querySelector('.insta-lightbox-spinner');
				if (spinner) spinner.remove();
			});
			mediaContainer.appendChild(img);
		}

		// Download button
		if (downloadBtn) {
			downloadBtn.onclick = function (e) {
				e.preventDefault();
				downloadMedia(proxiedSrc, displaySrc, data.isVideo);
			};
		}

		// Open on Instagram button
		if (instaBtn) {
			if (data.instaUrl) {
				instaBtn.href = data.instaUrl;
				instaBtn.style.display = '';
			} else {
				instaBtn.style.display = 'none';
			}
		}

		// Caption
		if (captionEl) {
			captionEl.textContent = data.caption || '';
		}

		// Show lightbox
		lightbox.style.display = 'flex';
		document.body.style.overflow = 'hidden';
	}

	/**
	 * Close the lightbox and clean up.
	 */
	function closeLightbox() {
		var lightbox = document.getElementById('insta-lightbox');
		if (!lightbox) return;

		// Stop any playing video
		var video = lightbox.querySelector('video');
		if (video) {
			video.pause();
			video.src = '';
		}

		lightbox.style.display = 'none';
		document.body.style.overflow = '';

		var mediaContainer = lightbox.querySelector('.insta-lightbox-media');
		if (mediaContainer) {
			mediaContainer.innerHTML = '';
		}
	}

	/**
	 * Download media file to user's device.
	 * Uses fetch+blob for cross-origin files (through the proxy).
	 *
	 * @param {string}  primaryUrl  - Proxied URL (same-origin).
	 * @param {string}  fallbackUrl - Direct CDN URL (fallback).
	 * @param {boolean} isVideo     - Whether file is a video.
	 */
	function downloadMedia(primaryUrl, fallbackUrl, isVideo) {
		var ext = isVideo ? 'mp4' : 'jpg';
		var filename = 'instagram_' + Date.now() + '.' + ext;

		fetch(primaryUrl)
			.then(function (res) {
				if (!res.ok) throw new Error('Fetch failed');
				return res.blob();
			})
			.then(function (blob) {
				triggerBlobDownload(blob, filename);
			})
			.catch(function () {
				// Fallback: try direct URL fetch, or open in new tab as last resort
				if (fallbackUrl && fallbackUrl !== primaryUrl) {
					fetch(fallbackUrl)
						.then(function (res) {
							if (!res.ok) throw new Error('Fallback failed');
							return res.blob();
						})
						.then(function (blob) {
							triggerBlobDownload(blob, filename);
						})
						.catch(function () {
							window.open(primaryUrl, '_blank');
						});
				} else {
					window.open(primaryUrl, '_blank');
				}
			});
	}

	/**
	 * Create a temporary anchor to trigger browser download from a Blob.
	 */
	function triggerBlobDownload(blob, filename) {
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = filename;
		a.style.display = 'none';
		document.body.appendChild(a);
		a.click();
		setTimeout(function () {
			URL.revokeObjectURL(url);
			a.remove();
		}, 100);
	}

	/**
	 * Bind global lightbox events (close button, backdrop, ESC key).
	 * Called once on init.
	 */
	function bindLightboxGlobalEvents() {
		document.addEventListener('click', function (e) {
			// Close button
			if (e.target.closest('.insta-lightbox-close')) {
				closeLightbox();
				return;
			}
			// Backdrop click
			if (e.target.classList.contains('insta-lightbox-backdrop')) {
				closeLightbox();
				return;
			}
		});

		// ESC key to close
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				var lightbox = document.getElementById('insta-lightbox');
				if (lightbox && lightbox.style.display !== 'none') {
					closeLightbox();
				}
			}
		});
	}

	// Initialize on page load
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initLookupInstances();
			bindLightboxGlobalEvents();
		});
	} else {
		initLookupInstances();
		bindLightboxGlobalEvents();
	}
})();
