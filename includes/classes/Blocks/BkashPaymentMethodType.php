<?php
/**
 * WooCommerce Blocks payment method integration for bKash.
 *
 * @package bkash-pgw-for-woocommerce
 */

namespace bKash\PGW\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers bKash payment method support for Checkout Block.
 */
final class BkashPaymentMethodType extends AbstractPaymentMethodType {
	/**
	 * Gateway ID used by WooCommerce.
	 *
	 * @var string
	 */
	protected $name = BKASH_FW_PLUGIN_SLUG;

	/**
	 * Gateway settings.
	 *
	 * @var array
	 */
	private $gateway_settings = array();

	/**
	 * Initialize integration settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->gateway_settings = get_option( 'woocommerce_' . BKASH_FW_PLUGIN_SLUG . '_settings', array() );
	}

	/**
	 * Return whether method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->gateway_settings['enabled'] ) && 'yes' === $this->gateway_settings['enabled'];
	}

	/**
	 * Register and return script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_handle = 'wc-bkash-blocks-integration';
		$script_path   = BKASH_FW_BASE_PATH . 'assets/js/blocks/payment-method.js';
		$script_url    = BKASH_FW_BASE_URL . 'assets/js/blocks/payment-method.js';

		if ( file_exists( $script_path ) ) {
			wp_register_script(
				$script_handle,
				$script_url,
				array(
					'wc-blocks-registry',
					'wc-settings',
					'wp-element',
					'wp-html-entities',
				),
				(string) filemtime( $script_path ),
				true
			);

			return array( $script_handle );
		}

		return array();
	}

	/**
	 * Return script handles for editor context.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	/**
	 * Provide data to Checkout Block integration script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$supports = array( 'products' );
		$icon_url = plugins_url( '../../assets/images/logo.png', __DIR__ );

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways[ BKASH_FW_PLUGIN_SLUG ] ) ) {
				$gateway = $gateways[ BKASH_FW_PLUGIN_SLUG ];
				if ( isset( $gateway->supports ) && is_array( $gateway->supports ) ) {
					$supports = $gateway->supports;
				}
				if ( isset( $gateway->icon ) && is_string( $gateway->icon ) && '' !== $gateway->icon ) {
					$icon_url = $gateway->icon;
				}
			}
		}

		return array(
			'title'       => isset( $this->gateway_settings['title'] ) ? wp_strip_all_tags( $this->gateway_settings['title'] ) : __( 'bKash Payment Gateway', 'bkash-pgw-for-woocommerce' ),
			'description' => isset( $this->gateway_settings['description'] ) ? wp_kses_post( $this->gateway_settings['description'] ) : __( 'Pay with bKash PGW.', 'bkash-pgw-for-woocommerce' ),
			'supports'    => array_values( array_unique( array_map( 'sanitize_text_field', $supports ) ) ),
			'icon'        => esc_url_raw( $icon_url ),
		);
	}
}
