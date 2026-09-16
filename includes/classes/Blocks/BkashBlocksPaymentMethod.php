<?php

namespace bKash\PGW\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\NoticeHandler;
use bKash\PGW\Models\Agreement;
use bKash\PGW\Operations;
use bKash\PGW\PaymentGatewaybKash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * bKash payment method integration for WooCommerce Cart & Checkout blocks.
 *
 * @see https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
 */
final class BkashBlocksPaymentMethod extends AbstractPaymentMethodType {

	/**
	 * Payment method name. Must match the gateway ID and client-side registration name.
	 *
	 * @var string
	 */
	protected $name = BKASH_FW_PLUGIN_SLUG;

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );

		add_action(
			'woocommerce_rest_checkout_process_payment_with_context',
			array( $this, 'process_payment_with_context' ),
			10,
			2
		);
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', 'no' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_handle = 'bkash-for-woocommerce-blocks';

		wp_register_script(
			$script_handle,
			BKASH_FW_BASE_URL . 'assets/js/blocks.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wc-sanitize',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			BKASH_FW_PLUGIN_VERSION,
			true
		);

		wp_enqueue_style(
			$script_handle,
			BKASH_FW_BASE_URL . 'assets/css/blocks.css',
			array(),
			BKASH_FW_PLUGIN_VERSION
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				$script_handle,
				'bkash-for-woocommerce',
				BKASH_FW_BASE_PATH . 'languages/'
			);
		}

		return array( $script_handle );
	}

	/**
	 * Data made available to the payment method client-side via wc.wcSettings.getPaymentMethodData().
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway           = $this->get_gateway();
		$integration_type  = (string) $this->get_setting( 'integration_type', 'checkout' );
		$sandbox           = $this->get_setting( 'sandbox', 'yes' );
		$description       = (string) $this->get_setting( 'description', '' );
		$api_version       = (string) $this->get_setting( 'bkash_api_version', 'v1.2.0-beta' );
		$site_url          = get_site_url();
		$api_base          = defined( 'BKASH_FW_WC_API' ) ? BKASH_FW_WC_API : '/wc-api/';

		if ( 'yes' === $sandbox && ! empty( $description ) ) {
			$description .= ' ' . __( '(IN SANDBOX)', 'bkash-for-woocommerce' );
		}

		return array(
			'name'               => $this->name,
			'title'              => $this->get_setting( 'title', __( 'bKash Payment Gateway', 'bkash-for-woocommerce' ) ),
			'description'        => $description,
			'icon'               => $gateway && ! empty( $gateway->icon ) ? $gateway->icon : '',
			'supports'           => $this->get_supported_features(),
			'integrationType'    => $integration_type,
			'sandbox'            => $sandbox,
			'isLoggedIn'         => is_user_logged_in(),
			'agreements'         => $this->get_agreements_for_current_user( $integration_type ),
			'orderButtonText'    => $gateway && ! empty( $gateway->order_button_text ) ? $gateway->order_button_text : __( 'Pay with bKash', 'bkash-for-woocommerce' ),
			'canMakePayment'     => $gateway ? (bool) $gateway->is_available() : true,
			'bKashScriptURL'     => 'checkout' === $integration_type
				? Operations::CheckoutScriptURL( 'yes' === $sandbox, $api_version )
				: '',
			'executeUrl'         => $site_url . $api_base . 'bk_execute',
			'cancelPaymentUrl'   => $site_url . $api_base . 'bk_cancel',
			'cancelAgreementUrl' => $site_url . $api_base . 'bk_cancel_agreement',
			'apiVersion'         => $api_version,
			'nonce'              => wp_create_nonce( 'bkash-ajax-nonce' ),
			'i18n'               => array(
				'cancelled'       => __( 'You have chosen to cancel the payment', 'bkash-for-woocommerce' ),
				'genericError'    => __( 'Something went wrong. Please try again.', 'bkash-for-woocommerce' ),
				'loginRequired'   => __( 'Please login to complete the payment', 'bkash-for-woocommerce' ),
				'newAgreement'    => __( 'Pay and remember a new bKash account', 'bkash-for-woocommerce' ),
				'withoutRemember' => __( 'Pay without remembering', 'bkash-for-woocommerce' ),
				'remove'          => __( 'Remove', 'bkash-for-woocommerce' ),
				'confirmCancel'   => __( 'Are you sure you want to cancel this agreement?', 'bkash-for-woocommerce' ),
				'sdkMissing'      => __( 'bKash JS SDK is missing', 'bkash-for-woocommerce' ),
				'rememberHint'    => __( 'To remember your bKash account number, please login and check remember', 'bkash-for-woocommerce' ),
				'agreementRemoved'=> __( 'Token for that agreement has been deleted', 'bkash-for-woocommerce' ),
			),
		);
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return array
	 */
	public function get_supported_features() {
		$gateway = $this->get_gateway();

		if ( $gateway ) {
			return array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) );
		}

		return parent::get_supported_features();
	}

	/**
	 * Process payment via the Store API so checkout (JS SDK) can receive flattened payment details.
	 *
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentContext $context Payment context.
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentResult  $result  Payment result (passed by reference).
	 *
	 * @throws RouteException When payment fails.
	 */
	public function process_payment_with_context( $context, &$result ) {
		if ( $this->name !== $context->payment_method ) {
			return;
		}

		$gateway = $context->get_payment_method_instance();
		if ( ! $gateway instanceof PaymentGatewaybKash ) {
			return;
		}

		$posted_data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		foreach ( $context->payment_data as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}

		$gateway->validate_fields();
		NoticeHandler::convert_notices_to_exceptions( 'woocommerce_rest_payment_error' );

		$gateway_result = $gateway->process_payment( $context->order->get_id() );

		$_POST = $posted_data;

		if ( isset( $gateway_result['result'] ) && 'failure' === $gateway_result['result'] ) {
			if ( ! empty( $gateway_result['message'] ) ) {
				throw new RouteException(
					'woocommerce_rest_payment_error',
					wp_strip_all_tags( $gateway_result['message'] ),
					400
				);
			}

			NoticeHandler::convert_notices_to_exceptions( 'woocommerce_rest_payment_error' );
		}

		$result_status = $gateway_result['result'] ?? 'failure';
		$valid_status  = array( 'success', 'failure', 'pending', 'error' );
		$result->set_status( in_array( $result_status, $valid_status, true ) ? $result_status : 'failure' );

		wc_clear_notices();

		$details = array(
			'integration_type' => (string) $this->get_setting( 'integration_type', 'checkout' ),
		);

		if ( ! empty( $gateway_result['order'] ) && is_array( $gateway_result['order'] ) ) {
			foreach ( $gateway_result['order'] as $key => $value ) {
				$details[ (string) $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}
		}

		if ( ! empty( $gateway_result['response'] ) ) {
			$details['bkash_response'] = is_string( $gateway_result['response'] )
				? $gateway_result['response']
				: wp_json_encode( $gateway_result['response'] );
		}

		$result->set_payment_details( $details );

		if ( ! empty( $gateway_result['redirect'] ) && is_string( $gateway_result['redirect'] ) ) {
			$result->set_redirect_url( $gateway_result['redirect'] );
		}
	}

	/**
	 * Saved tokenized agreements for the current shopper.
	 *
	 * @param string $integration_type Gateway integration type.
	 *
	 * @return array
	 */
	private function get_agreements_for_current_user( $integration_type ) {
		if ( ! is_user_logged_in() || ! in_array( $integration_type, array( 'tokenized', 'tokenized-both' ), true ) ) {
			return array();
		}

		$agreement_model = new Agreement();
		$rows            = $agreement_model->getAgreements( get_current_user_id() );
		$agreements      = array();

		foreach ( (array) $rows as $row ) {
			if ( empty( $row->agreement_token ) ) {
				continue;
			}

			$agreements[] = array(
				'token' => (string) $row->agreement_token,
				'phone' => isset( $row->phone ) ? (string) $row->phone : '',
			);
		}

		return $agreements;
	}

	/**
	 * Registered bKash gateway instance.
	 *
	 * @return PaymentGatewaybKash|null
	 */
	private function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return null;
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		return isset( $gateways[ $this->name ] ) && $gateways[ $this->name ] instanceof PaymentGatewaybKash
			? $gateways[ $this->name ]
			: null;
	}
}
