<?php
/**
 * Process Payment
 *
 * @category    Payment
 * @package     bkash-pgw-for-woocommerce
 * @author      Md. Shahnawaz Ahmed <shahnawaz.ahmed@bkash.com>
 * @copyright   Copyright 2022 bKash Limited. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://bkash.com
 */

namespace bKash\PGW;

use bKash\PGW\Models\Agreement;
use bKash\PGW\Models\Transaction;

class ProcessPayments {
	public $integration_type;
	private $bKashObj;

	public function __construct( string $integration_type ) {
		$this->integration_type = $integration_type;
		$this->bKashObj         = new ApiComm();
	}

	/**
	 * @param string $orderPageURL
	 * @param string $callbackURL
	 *
	 * @return void
	 */
	final public function executePayment( string $orderPageURL, string $callbackURL = '' ) {
		$message = '';

		if ( Utils::hasGetField( 'orderId' ) ) {
			$order_id   = Utils::safeGetValue( 'orderId' );
			$payment_id = Utils::safeGetValue( 'paymentID' );
			$invoice_id = Utils::safeGetValue( 'invoiceID' );
			$status     = Utils::safeGetValue( 'status' );
		} else {
			$order_id   = Utils::safePostValue( 'orderId' );
			$payment_id = Utils::safePostValue( 'paymentID' );
			$invoice_id = Utils::safePostValue( 'invoiceID' );
			$status     = Utils::safePostValue( 'status' );
		}

		$order       = wc_get_order( $order_id );
		$trx         = new Transaction();
		$transaction = $trx->getTransaction( $invoice_id );

		if ( ! $order || ! $transaction ) {
			$this->failAndExit( $this->processResponse( 'Invalid payment ID or Invoice ID' ), $orderPageURL );
		}

		if ( $this->isSuccessfulTransaction( $transaction ) ) {
			$this->finalizeSuccessfulOrder( $order, $transaction, $orderPageURL );
		}

		if ( ! empty( $transaction->getTrxID() ) ) {
			$recovered = $this->recoverSuccessfulPayment( $transaction );
			if ( $recovered instanceof Transaction ) {
				$this->finalizeSuccessfulOrder( $order, $recovered, $orderPageURL );
			}

			$this->failAndExit(
				$this->processResponse( 'This payment was already processed. Please check the order status or contact support.' ),
				$orderPageURL,
				$order
			);
		}

		if ( $status === 'success' ) {
			if ( $transaction->getPaymentID() === $payment_id ) {
				$transaction = $this->waitForInFlightExecute( $invoice_id, $payment_id, $transaction );
				if ( $this->isSuccessfulTransaction( $transaction ) ) {
					$this->finalizeSuccessfulOrder( $order, $transaction, $orderPageURL );
				}

				$lock_key = $this->executeLockKey( $payment_id );
				set_transient( $lock_key, 1, 120 );

				$transaction->update(
					array(
						'status' => 'CALLBACK_REACHED',
					)
				);

				$response = $this->bKashObj->executePayment( $transaction->getPaymentID() );

				if ( isset( $response['status_code'] ) && $response['status_code'] === 200 ) {
					$mode = $transaction->getMode();

					if ( $mode === '0000' ) {
						$agreementResp = Operations::processResponse( $response, 'agreementID' );
						if ( is_array( $agreementResp ) ) {
							if ( ( $agreementResp['agreementStatus'] ?? '' ) === 'Completed' ) {
								$agreementObj = new Agreement();
								$agreementObj->setAgreementID( $agreementResp['agreementID'] ?? '' );
								$agreementObj->setMobileNo( $agreementResp['customerMsisdn'] ?? '' );
								$agreementObj->setDateTime( $agreementResp['agreementExecuteTime'] ?? '' );
								$agreementObj->setUserID( $order->get_user_id() );
								$stored = $agreementObj->save();

								if ( $stored ) {
									$transaction->update(
										array( 'mode' => '0001' ),
										array( 'payment_id' => $transaction->getPaymentID() )
									);
									add_post_meta( $order->get_id(), '_bkmode', '0001', true );

									$createResp = $this->createPayment(
										$transaction->getOrderID(),
										$transaction->getIntent(),
										$callbackURL,
										$transaction
									);

									delete_transient( $lock_key );

									if ( isset( $createResp['redirect'] ) ) {
										wp_safe_redirect( $createResp['redirect'] );
										die();
									}

									echo wp_json_encode( $createResp );
									die();
								}

								$message = 'Agreement cannot be done right now, cannot store in db, try again. ' . $agreementObj->errorMessage;
								$message = $this->processResponse( $message );
							} else {
								$message = $this->processResponse( 'Agreement cannot be done right now, try again' );
							}
						} else {
							$message = is_string( $agreementResp ) ? $agreementResp : '';
							$message = $this->processResponse( $message );
						}
					} else {
						$paymentResp = $this->resolveExecutedPayment( $response, $transaction );

						if ( is_array( $paymentResp ) && ! empty( $paymentResp['trxID'] ) ) {
							$bkash_status = $paymentResp['transactionStatus'] ?? 'NO_STATUS_EXECUTE';
							$transaction->update(
								array(
									'status' => $bkash_status,
									'trx_id' => $paymentResp['trxID'],
								)
							);

							$fresh = ( new Transaction() )->getTransaction( $invoice_id );
							if ( $fresh instanceof Transaction ) {
								$transaction = $fresh;
							}

							if ( $this->isSuccessfulTransaction( $transaction ) ) {
								delete_transient( $lock_key );
								$this->finalizeSuccessfulOrder( $order, $transaction, $orderPageURL );
							}

							$message = 'Transaction was not successful, last transaction status: ' . $bkash_status;
						} else {
							$message = is_string( $paymentResp ) ? $paymentResp : 'Could not get transaction status';
						}

						$fresh = ( new Transaction() )->getTransaction( $invoice_id );
						if ( $fresh instanceof Transaction ) {
							$recovered = $this->recoverSuccessfulPayment( $fresh );
							if ( $recovered instanceof Transaction ) {
								delete_transient( $lock_key );
								$this->finalizeSuccessfulOrder( $order, $recovered, $orderPageURL );
							}

							if ( ! empty( $fresh->getTrxID() ) ) {
								delete_transient( $lock_key );
								$order->add_order_note( 'bKash Payment: ' . $message );
								$this->failAndExit( $this->processResponse( $message ), $orderPageURL, $order );
							}
						}

						$transaction->update(
							array(
								'status' => 'Failed',
							)
						);
						$order->add_order_note( 'bKash Payment: ' . $message );
						$message = $this->processResponse( $message );
					}
				} else {
					$fresh = ( new Transaction() )->getTransaction( $invoice_id );
					if ( $fresh instanceof Transaction && $this->isSuccessfulTransaction( $fresh ) ) {
						delete_transient( $lock_key );
						$this->finalizeSuccessfulOrder( $order, $fresh, $orderPageURL );
					}

					$message = $this->processResponse( 'Communication issue with payment gateway' );
				}

				delete_transient( $lock_key );
				$this->failAndExit( $message, $orderPageURL, $order );
			}

			$message = $this->processResponse( 'Invalid payment ID or Invoice ID' );
		} else {
			$status = str_replace( array( 'cancel', 'failure' ), array( 'Cancelled', 'Failed' ), $status );
			if ( $this->isSuccessfulTransaction( $transaction ) ) {
				$order->add_order_note(
					'bKash Payment is already in Completed state. Tried to change Status to => ' . esc_html( $status )
				);
				$this->finalizeSuccessfulOrder( $order, $transaction, $orderPageURL );
			}

			$transaction->update(
				array(
					'status' => esc_html( $status ),
				)
			);
			$order->add_order_note( 'bKash Payment is not successful. Status => ' . esc_html( $status ) );
			$message = $this->processResponse( 'Transaction is ' . $status );
		}

		$this->failAndExit( $message, $orderPageURL, $order );
	}

	/**
	 * Whether the stored bKash transaction already captured or authorized funds.
	 */
	private function isSuccessfulTransaction( $transaction ): bool {
		if ( ! $transaction instanceof Transaction ) {
			return false;
		}

		$status = (string) $transaction->getStatus();
		$trx_id = (string) $transaction->getTrxID();

		return '' !== $trx_id && in_array( $status, array( 'Completed', 'Authorized' ), true );
	}

	/**
	 * Complete the WooCommerce order for a payment that already succeeded at bKash.
	 */
	private function finalizeSuccessfulOrder( $order, Transaction $transaction, string $orderPageURL ) {
		if ( $order instanceof \WC_Order && $order->needs_payment() ) {
			$trx_id = (string) $transaction->getTrxID();

			if ( 'Authorized' === $transaction->getStatus() ) {
				$order->update_status( 'on-hold' );
			} else {
				$order->payment_complete( $trx_id );
			}

			if ( '' !== $trx_id ) {
				$order->set_transaction_id( $trx_id );
				$order->save();
				$order->add_order_note( sprintf( 'bKash PGW payment approved (ID: %s)', $trx_id ) );
			}
		}

		if ( $this->integration_type === 'checkout' ) {
			echo wp_json_encode(
				array(
					'result'   => 'success',
					'redirect' => $orderPageURL,
				)
			);
			die();
		}

		wp_safe_redirect( $orderPageURL );
		die();
	}

	private function executeLockKey( string $payment_id ): string {
		return 'bkash_pgw_execute_' . md5( $payment_id );
	}

	/**
	 * If another request is already executing this payment, wait for it to finish.
	 */
	private function waitForInFlightExecute( string $invoice_id, string $payment_id, Transaction $transaction ): Transaction {
		$lock_key = $this->executeLockKey( $payment_id );
		if ( ! get_transient( $lock_key ) ) {
			return $transaction;
		}

		for ( $i = 0; $i < 5; $i++ ) {
			sleep( 1 );
			$fresh = ( new Transaction() )->getTransaction( $invoice_id );
			if ( $fresh instanceof Transaction ) {
				$transaction = $fresh;
				if ( $this->isSuccessfulTransaction( $transaction ) || ! get_transient( $lock_key ) ) {
					break;
				}
			}
		}

		return $transaction;
	}

	/**
	 * Parse execute response, and query bKash if execute did not return a trxID.
	 *
	 * @param array       $response    Execute API envelope.
	 * @param Transaction $transaction Stored transaction.
	 *
	 * @return array|string
	 */
	private function resolveExecutedPayment( array $response, Transaction $transaction ) {
		$paymentResp = Operations::processResponse( $response, 'trxID' );

		if ( is_array( $paymentResp ) && ! empty( $paymentResp['trxID'] ) ) {
			return $paymentResp;
		}

		$query = $this->bKashObj->queryPayment( (string) $transaction->getPaymentID() );

		return Operations::processResponse( $query, 'trxID' );
	}

	/**
	 * Ask bKash for the real payment status when the local row looks inconsistent.
	 *
	 * @param Transaction $transaction Stored transaction.
	 *
	 * @return Transaction|null
	 */
	private function recoverSuccessfulPayment( Transaction $transaction ) {
		if ( $this->isSuccessfulTransaction( $transaction ) ) {
			return $transaction;
		}

		$payment_id = (string) $transaction->getPaymentID();
		if ( '' === $payment_id ) {
			return null;
		}

		$query = $this->bKashObj->queryPayment( $payment_id );
		$resp  = Operations::processResponse( $query, 'trxID' );
		if ( ! is_array( $resp ) || empty( $resp['trxID'] ) ) {
			return null;
		}

		$bkash_status = $resp['transactionStatus'] ?? '';
		if ( ! in_array( $bkash_status, array( 'Completed', 'Authorized' ), true ) ) {
			return null;
		}

		$transaction->update(
			array(
				'status' => $bkash_status,
				'trx_id' => $resp['trxID'],
			)
		);

		$invoice_id = (string) $transaction->getInvoiceID();
		$fresh      = $invoice_id ? ( new Transaction() )->getTransaction( $invoice_id ) : null;

		return $fresh instanceof Transaction ? $fresh : $transaction;
	}

	/**
	 * @param string               $message
	 * @param string               $orderPageURL
	 * @param \WC_Order|false|null $order
	 *
	 * @return void
	 */
	private function failAndExit( string $message, string $orderPageURL, $order = null ) {
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note( 'bKash PGW payment declined (' . $message . ')' );
		}

		if ( $this->integration_type === 'checkout' ) {
			echo wp_json_encode(
				array(
					'result'  => 'failure',
					'message' => $message,
				)
			);
			die();
		}

		wc_add_notice( $message, 'error' );
		wp_safe_redirect( wc_get_checkout_url() ? wc_get_checkout_url() : $orderPageURL );
		die();
	}

	final public function processResponse( string $message ): string {
		return "<h3 style='color:#fff;font-weight:bold;margin:0;font-size:20px;line-height: 14px;'>Payment Failed</h3>" . $message;
	}

	/**
	 * @param string           $order_id
	 * @param string           $intent
	 * @param string           $callbackURL
	 * @param Transaction|null $trx
	 *
	 * @return array|null
	 * */
	final public function createPayment(
		string $order_id,
		string $intent = 'sale',
		string $callbackURL = '',
		$transaction = null
	): array {
		$isAgreement = Utils::hasPostField( 'agreement' );
		if ( ! $isAgreement ) {
			$isAgreement = Utils::hasGetField( 'agreement' );
		}
		$agreement_id = Utils::safePostValue( 'agreement_id' );
		if ( ! $agreement_id ) {
			$agreement_id = Utils::hasGetField( 'agreement_id' );
		}

		// To receive order id and total
		$order    = wc_get_order( $order_id );
		$amount   = $order->get_total();
		$currency = get_woocommerce_currency();

		// To receive user id and order details
		$merchantCustomerId = $order->get_user_id();
		$merchantOrderId    = $order->get_order_number();

		if ( $this->integration_type === 'checkout' ) {
			$payment_payload = array(
				'amount'                  => $amount,
				'currency'                => $currency,
				'intent'                  => $intent,
				'merchantInvoiceNumber'   => uniqid( 'bfw_', false ) . '_' . $merchantOrderId,
				'merchantAssociationInfo' => '',
			);
		} else {
			// Check if already has agreement
			$storedAgreementID = '';
			$mode              = null;

			// Check if user is logged in
			if ( ! empty( $order->get_user_id() ) ) {
				if ( $agreement_id === 'new' || $agreement_id === 'no' ) {
					// If customer wants to add new number then, mode 0000, or without agreement 0011
					$mode = $agreement_id === 'new' ? '0000' : '0011';
				} elseif ( $agreement_id ) {
					// Customer selected an agreement to pay
					$storedAgreementID = $agreement_id;
				} else {
					// Proceed with stored most recent agreement id
					$agreementObj = new Agreement();
					$agreement    = $agreementObj->getAgreement( '', $order->get_user_id() );
					if ( $agreement ) {
						$storedAgreementID = $agreement->getAgreementID();
					}
				}
			} else {
				// Non-logged in user
				if ( $this->integration_type === 'tokenized' ) {
					wc_add_notice( 'Please login to proceed with tokenized payment', 'error' );

					return array( 'result' => 'failure' );
				}

				if ( $this->integration_type === 'tokenized-both' ) {
					$mode = '0011';
				}
			}

			if ( ! $mode ) {
				$mode = Operations::getTokenizedPaymentMode(
					$this->integration_type,
					$isAgreement,
					$storedAgreementID
				);
			}

			$payment_payload = array(
				'mode'                  => $mode,
				'payerReference'        => uniqid( 'bKash_', false ) . '_' . $merchantCustomerId,
				'callbackURL'           => $callbackURL,
				'agreementID'           => $storedAgreementID ?? '',
				'amount'                => $amount,
				'currency'              => $currency,
				'intent'                => $intent,
				'merchantInvoiceNumber' => uniqid( 'bfw_', false ) . '_' . $merchantOrderId,
			);
		}

		// if transaction is not prepared yet
		if ( empty( $transaction ) ) {
			/* Store Transaction in Database */
			$trx = new Transaction();
			$trx->setOrderID( $order_id );
			$trx->setAmount( $amount );
			$trx->setIntegrationType( $this->integration_type );
			$trx->setIntent( $intent );
			$trx->setCurrency( $currency );
			$trx->setMode( $mode ?? '' );
			$trx->setStatus( 'Created' );

			if ( ! empty( $payment_payload['merchantInvoiceNumber'] ) ) {
				$trx->setInvoiceID( $payment_payload['merchantInvoiceNumber'] );
			}

			$trxSaved = $trx->save();
		} else {
			$trxSaved = $transaction;
		}

		if ( $trxSaved ) {
			// pass invoice number in callback string
			if ( isset( $payment_payload['callbackURL'] ) ) {
				$payment_payload['callbackURL'] .= '&invoiceID=' . $trxSaved->getInvoiceID();
			}

			$createResponse = $this->bKashObj->paymentCreate( $payment_payload );

			if ( isset( $createResponse['status_code'] ) && $createResponse['status_code'] === 200 ) {
				$response = array();
				if ( isset( $createResponse['response'] ) && is_string( $createResponse['response'] ) ) {
					$response = json_decode( $createResponse['response'], true );
				}

				if ( $response ) {
					// If any error for tokenized
					if ( isset( $response['statusMessage'] ) && $response['statusMessage'] !== 'Successful' ) {
						$message = $response['statusMessage'];
					} elseif ( isset( $response['errorCode'] ) ) { // If any error for checkout
						$message = $response['errorMessage'] ?? '';
					} elseif ( isset( $response['paymentID'] ) && ! empty( $response['paymentID'] ) ) {
						// Remove items from cart.
						WC()->cart->empty_cart();
						if ( isset( $this->log ) && $this->log ) {
							$this->log->add( $this->id, 'Cart emptied.' );
						}

						$updated = $trxSaved->update( array( 'payment_id' => $response['paymentID'] ) );
						if ( $updated ) {
							if ( $this->integration_type === 'checkout' ) {
								return array(
									'result'   => 'success',
									'redirect' => null,
									'order'    => array(
										'orderId'   => $order_id,
										'paymentID' => $response['paymentID'],
										'invoiceID' => $trx->getInvoiceID(),
										'amount'    => $amount,
									),
									'response' => $response,
								);
							}

							return array(
								'result'   => 'success',
								'redirect' => $response['bkashURL'],
							);
						}

						$message = $this->processResponse(
							'Cannot process this payment right now, payment ID issue'
						);
					} else {
						$message = $this->processResponse(
							'Cannot process this payment right now, unknown error message'
						);
					}
				} else {
					$message = $this->processResponse( 'Cannot process this payment right now, not a valid response' );
				}
			} else {
				$message = $this->processResponse( 'Cannot process this payment right now, error in communication' );
			}
		} else {
			$message = $trx->errorMessage;
		}

		wc_add_notice( $message, 'error' );

		return array(
			'result'  => 'failure',
			'message' => $message,
		);
	}

	final public function cancelPayment( string $order_id ): array {
		// global $woocommerce;
		// To receive order id
		$order = wc_get_order( $order_id );
		if ( $order ) {
			if ( $order->get_status() === 'pending' ) {
				$trx         = new Transaction();
				$transaction = $trx->getTransactionByOrderId( $order_id );
				if ( $transaction ) {
					if ( in_array( (string) $transaction->getStatus(), array( 'Completed', 'Authorized' ), true )
						&& ! empty( $transaction->getTrxID() ) ) {
						return array(
							'result'  => 'failure',
							'message' => 'Payment already completed and cannot be cancelled from checkout',
						);
					}
					$transaction->update(
						array(
							'status' => 'Cancelled',
						)
					);
					$order->add_order_note( 'bKash Payment has been cancelled, either failed or customer cancelled' );
					$order->update_status( 'cancelled', 'Payment has been cancelled!' );

					return array(
						'result'   => 'success',
						'redirect' => null,
						'response' => 'Order cancelled!',
					);
				}

				return array(
					'result'  => 'failure',
					'message' => 'Transaction not found in bKash database',
				);
			}

			return array(
				'result'  => 'failure',
				'message' => 'Order is not in pending status to cancel the payment',
			);
		}

		return array(
			'result'  => 'failure',
			'message' => 'Order not found',
		);
	}
}
