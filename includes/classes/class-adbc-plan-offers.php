<?php

if ( ! defined( 'ABSPATH' ) )
	exit;

/** Server-to-server bridge. The store calculates prices; license keys never reach JavaScript. */
class ADBC_Plan_Offers {

	private const ENDPOINT = 'https://sigmaplugin.com/?rest_route=/sigma/v1/adbc-plans';

	public static function enqueue_style() {
		wp_enqueue_style( 'adbc-plan-picker', ADBC_PLUGIN_ABSOLUTE_URL . '/assets/css/plan-picker.css', [ 'dashicons' ], ADBC_Common_Utils::get_asset_version( 'css/plan-picker.css' ) );
	}

	public static function get_plans() {
		if ( ADBC_Black_Friday::get_offer() === null )
			return self::error( __( 'This offer is no longer available for your installation.', 'advanced-database-cleaner' ), 410, 'offer_unavailable' );

		$audience = ADBC_Black_Friday::get_audience();
		$license = ADBC_Black_Friday::get_saved_license( $audience );
		if ( $audience !== 'free' && ! $license['active'] )
			return self::error( __( 'Your license is not active on this site.', 'advanced-database-cleaner' ), 400 );

		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => 20,
			'sslverify' => true,
			'redirection' => 0,
			'limit_response_size' => 65536,
			'headers' => [ 'Accept' => 'application/json' ],
			'body' => [ 'audience' => $audience, 'license' => $license['key'] ],
		] );
		if ( is_wp_error( $response ) )
			return self::unavailable();

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status !== 200 ) {
			// Known store errors only: do not echo arbitrary upstream responses or request details.
			$messages = [
				'license_invalid' => __( 'We could not verify this license. Check your license in Settings or sign in to your SigmaPlugin account.', 'advanced-database-cleaner' ),
				'license_expired' => __( 'Renew your license in your SigmaPlugin account before upgrading it.', 'advanced-database-cleaner' ),
				'license_unavailable' => __( 'This license cannot be upgraded. Check its status in your SigmaPlugin account.', 'advanced-database-cleaner' ),
				'sale_ended' => __( 'The Black Friday offer has ended.', 'advanced-database-cleaner' ),
				'offer_unavailable' => __( 'The Black Friday discount is currently unavailable.', 'advanced-database-cleaner' ),
				'rate_limited' => __( 'Please wait a minute before checking the plans again.', 'advanced-database-cleaner' ),
			];
			$code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';
			if ( ! isset( $messages[ $code ] ) )
				return self::unavailable();
			$reason = in_array( $code, [ 'sale_ended', 'offer_unavailable' ], true ) ? 'offer_unavailable' : 'plans_unavailable';
			return self::error( $messages[ $code ], $status, $reason );
		}

		if ( ! is_array( $data ) || ! isset( $data['plans'], $data['expires_at'], $data['current_plan'], $data['mode'] )
			|| ! is_array( $data['plans'] ) || count( $data['plans'] ) > 32
			|| $data['mode'] !== ( $audience === 'free' ? 'purchase' : 'upgrade' )
			|| ! is_numeric( $data['expires_at'] ) || (int) $data['expires_at'] <= time() )
			return self::unavailable();

		$current_term = null;
		if ( $audience !== 'free' ) {
			// Older store responses omit the billing period. Use the saved license when available.
			$current_term = array_key_exists( 'current_term', $data )
				? $data['current_term']
				: ADBC_Black_Friday::get_license_term( $audience, $license );
			if ( ! in_array( $current_term, [ 'annual', 'lifetime' ], true ) )
				return self::unavailable();
		}

		$plans = [];
		foreach ( $data['plans'] as $plan ) {
			if ( ! self::valid_plan( $plan, $data['mode'] ) )
				return self::unavailable();
			if ( $current_term !== null && $plan['term'] !== $current_term )
				continue;
			// Explicit allowlist prevents accidental store responses from exposing license/customer data.
			$plans[ $plan['id'] ] = [
				'id' => sanitize_text_field( $plan['id'] ),
				'name' => sanitize_text_field( $plan['name'] ),
				'term' => $plan['term'],
				'sites' => (int) $plan['sites'],
				'price' => sanitize_text_field( $plan['price'] ),
				'regular_price' => sanitize_text_field( $plan['regular_price'] ),
				'saving' => sanitize_text_field( $plan['saving'] ),
				'discounted' => (bool) $plan['discounted'],
				'checkout_url' => esc_url_raw( $plan['checkout_url'] ),
			];
		}
		if ( $audience !== 'free' ) {
			$plans = self::compare_tiers( $data['tiers'] ?? null, $plans, $current_term, $audience );
			if ( $plans === null )
				return self::unavailable();
		}
		$result = new WP_REST_Response( [ 'success' => true, 'data' => [
			'mode' => $data['mode'],
			'current_plan' => sanitize_text_field( $data['current_plan'] ),
			'current_term' => $current_term,
			'expires_at' => (int) $data['expires_at'],
			'plans' => array_values( $plans ),
		] ] );
		$result->header( 'Cache-Control', 'no-store, private' );
		return $result;
	}

	/** Show the owned tier first, followed by higher tiers in the store's catalog order. */
	private static function compare_tiers( $tiers, $quotes, $term, $audience ) {
		if ( ! is_array( $tiers ) || count( $tiers ) > 32 )
			return null;
		$plans = [];
		$current = null;
		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) || ! isset( $tier['edition'], $tier['price_id'], $tier['name'], $tier['term'], $tier['sites'], $tier['list_price'], $tier['current'] )
				|| ! in_array( $tier['edition'], [ 'pro', 'premium' ], true ) || ! is_int( $tier['price_id'] ) || $tier['price_id'] < 0
				|| ! is_int( $tier['sites'] ) || $tier['sites'] < 0 || ! is_bool( $tier['current'] )
				|| ! is_string( $tier['name'] ) || ! is_string( $tier['list_price'] ) )
				return null;
			if ( $tier['term'] !== $term )
				continue;
			$id = $tier['edition'] . '-' . $tier['price_id'];
			if ( isset( $plans[ $id ] ) )
				return null;
			$plans[ $id ] = [
				'id' => $id,
				'edition' => $tier['edition'],
				'price_id' => $tier['price_id'],
				'name' => sanitize_text_field( $tier['name'] ),
				'term' => $term,
				'sites' => $tier['sites'],
				'list_price' => sanitize_text_field( $tier['list_price'] ),
				'state' => 'unavailable',
			];
			if ( $tier['current'] ) {
				if ( $current !== null || $tier['edition'] !== $audience )
					return null;
				$current = $id;
				$plans[ $id ]['state'] = 'current';
			} elseif ( isset( $tier['upgrade_id'] ) && is_string( $tier['upgrade_id'] ) && isset( $quotes[ $tier['upgrade_id'] ] ) ) {
				$plans[ $id ]['quote'] = $quotes[ $tier['upgrade_id'] ];
			}
		}
		if ( $current === null )
			return empty( $plans ) ? [] : null;
		$capacity = $plans[ $current ]['sites'] ?: PHP_INT_MAX;
		$comparison = [ $plans[ $current ] ];
		foreach ( $plans as $plan ) {
			$rank = $plan['sites'] ?: PHP_INT_MAX;
			if ( $rank <= $capacity )
				continue;
			$quote = $plan['quote'] ?? null;
			unset( $plan['quote'] );
			if ( $quote && $quote['sites'] === $plan['sites'] && ( $plan['edition'] === $audience || $plan['edition'] === 'premium' ) ) {
				$plan['state'] = 'upgrade';
				$plan['id'] = $quote['id'];
				$plan += $quote;
			}
			$comparison[] = $plan;
		}
		return $comparison;
	}

	private static function valid_plan( $plan, $mode ) {
		if ( ! is_array( $plan ) )
			return false;
		foreach ( [ 'id', 'name', 'term', 'price', 'regular_price', 'saving', 'checkout_url' ] as $field ) {
			if ( ! isset( $plan[ $field ] ) || ! is_string( $plan[ $field ] ) )
				return false;
		}
		if ( ! isset( $plan['sites'], $plan['discounted'] ) || ! is_numeric( $plan['sites'] ) || $plan['sites'] < 0
			|| ! in_array( $plan['term'], [ 'annual', 'lifetime' ], true ) )
			return false;
		$url = wp_parse_url( $plan['checkout_url'] );
		if ( ! ( is_array( $url ) && ( $url['scheme'] ?? '' ) === 'https'
			&& ( $url['host'] ?? '' ) === 'sigmaplugin.com'
			&& ! isset( $url['user'] ) && ! isset( $url['pass'] ) && ! isset( $url['port'] ) ) )
			return false;
		parse_str( $url['query'] ?? '', $query );
		if ( isset( $query['license_key'] ) || isset( $query['license'] ) )
			return false;
		if ( $mode === 'upgrade' ) {
			return ( $query['edd_action'] ?? '' ) === 'sl_license_upgrade'
				&& isset( $query['license_id'], $query['upgrade_id'] )
				&& is_scalar( $query['license_id'] ) && ctype_digit( (string) $query['license_id'] ) && (int) $query['license_id'] > 0
				&& is_scalar( $query['upgrade_id'] ) && ctype_digit( (string) $query['upgrade_id'] )
				&& $plan['id'] === 'upgrade-' . $query['upgrade_id'];
		}
		return ( $query['edd_action'] ?? '' ) === 'add_to_cart' && ( $query['download_id'] ?? '' ) === '3289507'
			&& isset( $query['edd_options']['price_id'] ) && is_scalar( $query['edd_options']['price_id'] )
			&& ctype_digit( (string) $query['edd_options']['price_id'] );
	}

	private static function unavailable() {
		return self::error( __( 'We couldn’t load the current prices. Try again or visit SigmaPlugin to view your options.', 'advanced-database-cleaner' ), 503 );
	}

	private static function error( $message, $status, $code = 'plans_unavailable' ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => $message, 'code' => $code ], $status, [ 'Cache-Control' => 'no-store, private' ] );
	}
}
