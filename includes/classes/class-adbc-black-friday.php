<?php

if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Shared eligibility, schedule and content for the Black Friday 2026 plugin banner and Plugins page modal.
 * Remove this class and its callers after the campaign ends.
 */
class ADBC_Black_Friday {

	private const INSTALLED_BEFORE = '2026-11-19';
	private const DISCOUNT = 35;
	private const COUPON = ''; // Empty means no coupon is displayed.

	/**
	 * Return the offer for an eligible installation, or null outside the campaign.
	 * @param int|null $now Unix timestamp in seconds; defaults to the current time.
	 */
	public static function get_offer( $now = null ) {
		$now = ( $now ?? time() ) * 1000;
		$phases = self::get_phases();
		$active_phase = null;
		foreach ( $phases as $phase ) {
			if ( $now >= $phase['start'] && $now < $phase['end'] ) {
				$active_phase = $phase;
				break;
			}
		}
		if ( $active_phase === null )
			return null;

		$installed = (string) ADBC_Settings::instance()->get_setting( 'installed_on' );
		$date = DateTime::createFromFormat( '!d/m/Y', $installed, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'd/m/Y' ) !== $installed || $date->format( 'Y-m-d' ) >= self::INSTALLED_BEFORE )
			return null;

		// A paid edition takes precedence over Free, even when inactive. License status is irrelevant.
		$audience = self::get_audience();
		$license = self::get_saved_license( $audience );
		if ( self::is_agency_plan( $audience, $license['price_id'] ) )
			return null;
		$content = self::get_content( $audience, $license );
		$is_free = $audience === 'free';
		return array_merge( $content, [
			'audience' => $audience,
			'phase' => $active_phase,
			'phases' => $phases,
			'discount' => self::DISCOUNT,
			'coupon' => self::COUPON,
			'title' => sprintf(
				/* translators: %d: discount percentage. */
				$is_free ? __( 'Upgrade to Premium and save %d%%', 'advanced-database-cleaner' ) : __( 'Upgrade your license and save %d%%', 'advanced-database-cleaner' ),
				self::DISCOUNT
			),
			'cta' => $is_free ? __( 'Upgrade to Premium', 'advanced-database-cleaner' ) : __( 'Upgrade your license', 'advanced-database-cleaner' ),
			'url' => $content['url'] . '?utm_source=black-friday-2026&utm_medium=adbc-' . $audience . '&utm_campaign=bf2026',
		] );
	}

	/**
	 * The Plugins page modal shows once, on the first Plugins page visit after updating to this version.
	 * One impression for the entire campaign, across all phases.
	 */
	public static function get_plugins_modal( $now = null ) {
		global $pagenow;
		if ( $pagenow !== 'plugins.php' || ADBC_Settings::instance()->get_setting( 'bf2026_plugins_modal_pending' ) !== '1' )
			return null;

		return self::get_offer( $now );
	}

	/** UTC boundaries, with exclusive end dates. Timestamps use milliseconds for both renderers. */
	private static function get_phases() {
		return [
			[
				'id' => 'black_friday',
				'start' => strtotime( '2026-11-19 00:00:00 UTC' ) * 1000,
				'end' => strtotime( '2026-11-28 00:00:00 UTC' ) * 1000,
				'badge' => __( 'Black Friday', 'advanced-database-cleaner' ),
				'countdownLabel' => __( 'Offer ends in', 'advanced-database-cleaner' ),
			],
			[
				'id' => 'extended',
				'start' => strtotime( '2026-11-28 00:00:00 UTC' ) * 1000,
				'end' => strtotime( '2026-11-30 00:00:00 UTC' ) * 1000,
				'badge' => __( 'Black Friday extended', 'advanced-database-cleaner' ),
				'countdownLabel' => __( 'Extended offer ends in', 'advanced-database-cleaner' ),
			],
			[
				'id' => 'cyber_monday',
				'start' => strtotime( '2026-11-30 00:00:00 UTC' ) * 1000,
				'end' => strtotime( '2026-12-04 00:00:00 UTC' ) * 1000,
				'badge' => __( 'Cyber Monday', 'advanced-database-cleaner' ),
				'countdownLabel' => __( 'Last chance, ends in', 'advanced-database-cleaner' ),
			],
		];
	}

	public static function get_audience() {
		if ( ADBC_VERSION_TYPE !== 'FREE' )
			return ADBC_IS_PRO_VERSION ? 'pro' : 'premium';

		if ( ! function_exists( 'get_plugins' ) )
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$audience = 'free';
		foreach ( get_plugins() as $file => $plugin ) {
			// Recognize standard/suffixed folders and paid copies whose folder was renamed.
			$edition = null;
			if ( preg_match( '#^advanced-database-cleaner-(pro|premium)(?:-[^/]+)?/advanced-db-cleaner\.php$#', $file, $match ) ) {
				$edition = $match[1];
			} elseif ( ( $plugin['TextDomain'] ?? '' ) === 'advanced-database-cleaner'
				&& preg_match( '/^Advanced (?:Database|DB) Cleaner\s*-\s*(Pro|Premium)$/i', $plugin['Name'] ?? '', $match ) ) {
				$edition = strtolower( $match[1] );
			}
			if ( $edition === 'premium' )
				return 'premium';
			if ( $edition === 'pro' )
				$audience = 'pro';
		}
		return $audience;
	}

	/** Read the matching edition, including an inactive paid copy. Never localize the returned key. */
	public static function get_saved_license( $audience ) {
		if ( $audience === 'free' )
			return [ 'key' => '', 'active' => false, 'price_id' => '', 'expires' => '' ];

		$option = $audience === 'pro' ? 'adbc_plugin_license_key_pro' : 'adbc_plugin_license_key';
		$key = trim( (string) get_option( $option, '' ) );
		$data = (array) get_option( $option . '_license', [] );
		// Pro 3.x stores its key under the old option name.
		if ( $audience === 'pro' && $key === '' ) {
			$legacy_key = trim( (string) get_option( 'aDBc_edd_license_key', '' ) );
			if ( $legacy_key !== '' ) {
				$key = $legacy_key;
				$data = [ 'license' => get_option( 'aDBc_edd_license_status', '' ) ];
			}
		}
		return [
			'key' => $key,
			'active' => $key !== '' && ( $data['license'] ?? '' ) === 'valid',
			'price_id' => (string) ( $data['price_id'] ?? '' ),
			'expires' => trim( (string) ( $data['expires'] ?? '' ) ),
		];
	}

	public static function is_agency_plan( $audience, $price_id ) {
		return $audience !== 'free' && ( (int) $price_id === 4 || ( $audience === 'premium' && (int) $price_id === 8 ) );
	}

	/** Return the billing period when the saved license identifies it. */
	public static function get_license_term( $audience, $license ) {
		$expires = $license['expires'];
		if ( strcasecmp( $expires, 'lifetime' ) === 0 || $expires === '0' )
			return 'lifetime';

		// Premium has separate annual and lifetime price IDs. Classic Pro uses different IDs.
		if ( $audience === 'premium' ) {
			if ( in_array( $license['price_id'], [ '1', '2', '3', '4' ], true ) )
				return 'annual';
			if ( in_array( $license['price_id'], [ '5', '6', '7', '8' ], true ) )
				return 'lifetime';
		}

		// EDD may supply a date or Unix timestamp even when the price ID is missing.
		$timestamp = is_numeric( $expires ) ? (int) $expires : strtotime( $expires );
		return $timestamp > 0 ? 'annual' : null;
	}

	private static function get_content( $audience, $license ) {
		if ( $audience === 'free' ) {
			return [
				'description' => __( "Find and remove what the free version can't see. Choose an annual plan and keep the same discount on every renewal. Lifetime plans are also available.", 'advanced-database-cleaner' ),
				'benefits' => [
					__( 'Remote SmartScan™', 'advanced-database-cleaner' ),
					__( 'Unlimited scheduled tasks', 'advanced-database-cleaner' ),
					__( 'Database analytics', 'advanced-database-cleaner' ),
					__( 'Priority support', 'advanced-database-cleaner' ),
				],
				'url' => 'https://sigmaplugin.com/downloads/wordpress-advanced-database-cleaner',
			];
		}

		return [
			'description' => self::get_license_term( $audience, $license ) === 'annual'
				? __( "The discount stays on all your upcoming renewals, not just your first payment, so your license stays discounted for good. It's now or never.", 'advanced-database-cleaner' )
				: __( 'Black Friday is the perfect time to upgrade your license, use Advanced Database Cleaner on more sites, and enjoy additional plan benefits.', 'advanced-database-cleaner' ),
			'benefits' => [
				__( 'More sites', 'advanced-database-cleaner' ),
				__( 'Keep all your settings', 'advanced-database-cleaner' ),
				__( 'Priority support', 'advanced-database-cleaner' ),
			],
			'url' => 'https://sigmaplugin.com/login',
		];
	}
}
