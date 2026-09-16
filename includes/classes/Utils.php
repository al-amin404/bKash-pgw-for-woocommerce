<?php
/**
 * Utils
 *
 * @category    Utility
 * @package     bkash-pgw-for-woocommerce
 * @author      Md. Shahnawaz Ahmed <shahnawaz.ahmed@bkash.com>
 * @copyright   Copyright 2022 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW;

class Utils {
	public static function hasPostField( string $key ): bool {
		return filter_has_var( INPUT_POST, $key );
	}

	public static function safePostValue( string $key ): string {
		return sanitize_text_field( filter_input( INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS ) );
	}

	public static function hasGetField( string $key ): bool {
		return filter_has_var( INPUT_GET, $key );
	}

	public static function safeGetValue( string $key ): string {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Whether the given WooCommerce payment method belongs to this gateway.
	 *
	 * @param string $payment_method Payment method ID stored on an order.
	 *
	 * @return bool
	 */
	public static function isBkashPaymentMethod( string $payment_method ): bool {
		return in_array(
			$payment_method,
			array(
				BKASH_FW_PLUGIN_SLUG,
				BKASH_FW_LEGACY_PLUGIN_SLUG,
			),
			true
		);
	}

	public static function hasServerField( string $key ): string {
		return filter_has_var( INPUT_SERVER, $key );
	}

	public static function safeServerValue( string $key ): string {
		return sanitize_text_field( filter_input( INPUT_SERVER, $key, FILTER_SANITIZE_SPECIAL_CHARS ) );
	}

	public static function safeString( string $value ): string {
		return sanitize_text_field( $value );
	}

	public static function safeSqlString( string $value ): string {
		return sanitize_text_field( esc_sql( $value ) );
	}
}
