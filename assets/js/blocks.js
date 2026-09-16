( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;

	if ( ! registry || ! registry.registerPaymentMethod || ! settingsApi || ! element ) {
		return;
	}

	var decodeEntities = htmlEntities && htmlEntities.decodeEntities ? htmlEntities.decodeEntities : function ( value ) {
		return value || '';
	};
	var createElement = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var RawHTML = element.RawHTML;

	var methodName = 'bkash-for-woocommerce';
	var settings = {};

	if ( typeof settingsApi.getPaymentMethodData === 'function' ) {
		settings = settingsApi.getPaymentMethodData( methodName, {} ) || {};
	}

	if ( ! settings || ! Object.keys( settings ).length ) {
		settings = settingsApi.getSetting( methodName + '_data', {} ) || {};
	}

	var i18n = settings.i18n || {};
	var label = decodeEntities( settings.title || 'bKash Payment Gateway' );
	var sdkState = {
		initialized: false,
		loading: null,
		pending: null
	};

	function isBlockEditor() {
		return !!(
			window.wp &&
			window.wp.data &&
			window.wp.data.select &&
			window.wp.data.select( 'core/editor' )
		);
	}

	function sanitizeDescription( html ) {
		var sanitize = window.wc && window.wc.sanitize && window.wc.sanitize.sanitizeHTML;
		var text = decodeEntities( html || '' );

		if ( RawHTML && typeof sanitize === 'function' ) {
			return createElement( RawHTML, { children: sanitize( text ) } );
		}

		return text;
	}

	function normalizePaymentDetails( details ) {
		if ( ! details ) {
			return {};
		}

		if ( Array.isArray( details ) ) {
			return details.reduce( function ( acc, item ) {
				if ( item && item.key ) {
					acc[ item.key ] = item.value;
				}
				return acc;
			}, {} );
		}

		return details;
	}

	function parseJson( payload, fallback ) {
		if ( ! payload ) {
			return fallback;
		}

		if ( typeof payload === 'object' ) {
			return payload;
		}

		try {
			return JSON.parse( payload );
		} catch ( error ) {
			return fallback;
		}
	}

	function postForm( url, data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( key ) {
			if ( typeof data[ key ] !== 'undefined' && data[ key ] !== null ) {
				body.append( key, data[ key ] );
			}
		} );

		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		} ).then( function ( response ) {
			return response.text();
		} ).then( function ( text ) {
			return parseJson( text, {} );
		} );
	}

	function loadBkashSdk( scriptUrl ) {
		if ( typeof window.bKash !== 'undefined' ) {
			return Promise.resolve();
		}

		if ( sdkState.loading ) {
			return sdkState.loading;
		}

		sdkState.loading = new Promise( function ( resolve, reject ) {
			var existing = document.querySelector( 'script[data-bkash-sdk="true"]' );
			if ( existing ) {
				existing.addEventListener( 'load', function () {
					resolve();
				} );
				existing.addEventListener( 'error', function () {
					reject( new Error( i18n.sdkMissing || 'bKash JS SDK is missing' ) );
				} );
				return;
			}

			var script = document.createElement( 'script' );
			script.src = scriptUrl;
			script.async = true;
			script.dataset.bkashSdk = 'true';
			script.onload = function () {
				resolve();
			};
			script.onerror = function () {
				reject( new Error( i18n.sdkMissing || 'bKash JS SDK is missing' ) );
			};
			document.head.appendChild( script );
		} );

		return sdkState.loading;
	}

	function ensureBkashButton() {
		var button = document.getElementById( 'bKash_button' );

		if ( ! button ) {
			button = document.createElement( 'button' );
			button.id = 'bKash_button';
			button.type = 'button';
			button.style.display = 'none';
			document.body.appendChild( button );
		}

		return button;
	}

	function initBkashSdk() {
		if ( sdkState.initialized || typeof window.bKash === 'undefined' ) {
			return;
		}

		ensureBkashButton();

		window.bKash.init( {
			paymentMode: 'checkout',
			paymentRequest: {
				amount: '0',
				intent: 'sale'
			},
			createRequest: function () {
				if ( sdkState.pending && sdkState.pending.response ) {
					window.bKash.create().onSuccess( sdkState.pending.response );
					return;
				}

				window.bKash.execute().onError();
			},
			executeRequestOnAuthorization: function () {
				if ( sdkState.pending && typeof sdkState.pending.onAuthorized === 'function' ) {
					sdkState.pending.onAuthorized();
				}
			},
			onClose: function () {
				if ( sdkState.pending && typeof sdkState.pending.onClose === 'function' ) {
					sdkState.pending.onClose();
				}
			}
		} );

		sdkState.initialized = true;
	}

	function executePayment( details ) {
		return postForm( settings.executeUrl, {
			action: 'bk_execute',
			security: settings.nonce || '',
			orderId: details.orderId,
			paymentID: details.paymentID,
			invoiceID: details.invoiceID,
			status: 'success',
			apiVersion: settings.apiVersion || 'v1.2.0-beta'
		} ).then( function ( result ) {
			if ( result && result.result === 'success' && result.redirect ) {
				return result;
			}

			throw new Error( result && result.message ? String( result.message ).replace( /<[^>]*>/g, '' ) : ( i18n.genericError || 'Something went wrong. Please try again.' ) );
		} );
	}

	function cancelPayment( details ) {
		return postForm( settings.cancelPaymentUrl, {
			action: 'bk_cancel',
			security: settings.nonce || '',
			orderId: details.orderId,
			paymentID: details.paymentID,
			invoiceID: details.invoiceID,
			status: 'cancel',
			apiVersion: settings.apiVersion || 'v1.2.0-beta'
		} ).catch( function () {
			return null;
		} );
	}

	function startCheckoutPopup( details ) {
		return loadBkashSdk( settings.bKashScriptURL ).then( function () {
			initBkashSdk();

			return new Promise( function ( resolve, reject ) {
				var settled = false;

				sdkState.pending = {
					response: parseJson( details.bkash_response, details.bkash_response || {} ),
					onAuthorized: function () {
						if ( settled ) {
							return;
						}

						executePayment( details ).then( function ( result ) {
							settled = true;
							sdkState.pending = null;
							resolve( result );
						} ).catch( function ( error ) {
							settled = true;
							sdkState.pending = null;
							window.bKash.execute().onError();
							reject( error );
						} );
					},
					onClose: function () {
						if ( settled ) {
							return;
						}

						settled = true;
						cancelPayment( details );
						sdkState.pending = null;
						window.bKash.execute().onError();
						reject( new Error( i18n.cancelled || 'You have chosen to cancel the payment' ) );
					}
				};

				ensureBkashButton().click();
			} );
		} );
	}

	function Label( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;

		if ( PaymentMethodLabel ) {
			return createElement( PaymentMethodLabel, { text: label } );
		}

		return createElement( 'span', null, label );
	}

	function Content( props ) {
		var eventRegistration = props.eventRegistration || {};
		var emitResponse = props.emitResponse || {};
		var onPaymentSetup = eventRegistration.onPaymentSetup;
		var onCheckoutSuccess = eventRegistration.onCheckoutSuccess;
		var agreements = Array.isArray( settings.agreements ) ? settings.agreements : [];
		var defaultAgreement = agreements.length ? agreements[ 0 ].token : ( settings.integrationType === 'tokenized-both' ? 'no' : 'new' );
		var agreementState = useState( defaultAgreement );
		var selectedAgreement = agreementState[ 0 ];
		var setSelectedAgreement = agreementState[ 1 ];
		var listState = useState( agreements );
		var agreementList = listState[ 0 ];
		var setAgreementList = listState[ 1 ];
		var selectedRef = useRef( selectedAgreement );

		useEffect( function () {
			selectedRef.current = selectedAgreement;
		}, [ selectedAgreement ] );

		useEffect( function () {
			if ( typeof onPaymentSetup !== 'function' ) {
				return undefined;
			}

			var unsubscribe = onPaymentSetup( function () {
				return {
					type: emitResponse.responseTypes ? emitResponse.responseTypes.SUCCESS : 'success',
					meta: {
						paymentMethodData: {
							agreement_id: selectedRef.current || ''
						}
					}
				};
			} );

			return unsubscribe;
		}, [ onPaymentSetup, emitResponse.responseTypes ] );

		useEffect( function () {
			if ( typeof onCheckoutSuccess !== 'function' || settings.integrationType !== 'checkout' || isBlockEditor() ) {
				return undefined;
			}

			var unsubscribe = onCheckoutSuccess( function ( payload ) {
				var paymentResult = payload && payload.paymentResult ? payload.paymentResult : {};
				var details = normalizePaymentDetails( paymentResult.paymentDetails );

				if ( ! details.paymentID || ! details.bkash_response ) {
					return true;
				}

				return startCheckoutPopup( details ).then( function ( result ) {
					return {
						type: emitResponse.responseTypes ? emitResponse.responseTypes.SUCCESS : 'success',
						redirectUrl: ( result && result.redirect ) || payload.redirectUrl
					};
				} ).catch( function ( error ) {
					return {
						type: emitResponse.responseTypes ? emitResponse.responseTypes.ERROR : 'error',
						message: error && error.message ? error.message : ( i18n.genericError || 'Something went wrong. Please try again.' ),
						retry: true
					};
				} );
			} );

			return unsubscribe;
		}, [ onCheckoutSuccess, emitResponse.responseTypes ] );

		function removeAgreement( token ) {
			if ( ! window.confirm( i18n.confirmCancel || 'Are you sure you want to cancel this agreement?' ) ) {
				return;
			}

			postForm( settings.cancelAgreementUrl, {
				id: token,
				security: settings.nonce || ''
			} ).then( function ( result ) {
				if ( result && result.result === 'success' ) {
					var remaining = agreementList.filter( function ( item ) {
						return item.token !== token;
					} );
					setAgreementList( remaining );
					if ( selectedAgreement === token ) {
						setSelectedAgreement( remaining.length ? remaining[ 0 ].token : 'new' );
					}
					return;
				}

				window.alert( result && result.message ? result.message : ( i18n.genericError || 'Something went wrong. Please try again.' ) );
			} );
		}

		var children = [
			createElement(
				'div',
				{ className: 'bkash-blocks-description', key: 'description' },
				sanitizeDescription( settings.description || '' )
			)
		];

		if ( settings.integrationType === 'tokenized' && ! settings.isLoggedIn ) {
			children.push(
				createElement(
					'p',
					{ className: 'bkash-blocks-error', key: 'login' },
					i18n.loginRequired || 'Please login to complete the payment'
				)
			);
		}

		if ( ( settings.integrationType === 'tokenized' || settings.integrationType === 'tokenized-both' ) && settings.isLoggedIn ) {
			var items = agreementList.map( function ( agreement ) {
				return createElement(
					'li',
					{ key: agreement.token },
					createElement(
						'label',
						{ htmlFor: 'bkash-agreement-' + agreement.token },
						createElement( 'input', {
							id: 'bkash-agreement-' + agreement.token,
							type: 'radio',
							name: 'bkash_agreement_id',
							value: agreement.token,
							checked: selectedAgreement === agreement.token,
							onChange: function () {
								setSelectedAgreement( agreement.token );
							}
						} ),
						agreement.phone || agreement.token
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'bkash-blocks-remove',
							onClick: function () {
								removeAgreement( agreement.token );
							}
						},
						i18n.remove || 'Remove'
					)
				);
			} );

			items.push(
				createElement(
					'li',
					{ key: 'new' },
					createElement(
						'label',
						{ htmlFor: 'bkash-agreement-new' },
						createElement( 'input', {
							id: 'bkash-agreement-new',
							type: 'radio',
							name: 'bkash_agreement_id',
							value: 'new',
							checked: selectedAgreement === 'new',
							onChange: function () {
								setSelectedAgreement( 'new' );
							}
						} ),
						i18n.newAgreement || 'Pay and remember a new bKash account'
					)
				)
			);

			if ( settings.integrationType === 'tokenized-both' ) {
				items.push(
					createElement(
						'li',
						{ key: 'no' },
						createElement(
							'label',
							{ htmlFor: 'bkash-agreement-no' },
							createElement( 'input', {
								id: 'bkash-agreement-no',
								type: 'radio',
								name: 'bkash_agreement_id',
								value: 'no',
								checked: selectedAgreement === 'no',
								onChange: function () {
									setSelectedAgreement( 'no' );
								}
							} ),
							i18n.withoutRemember || 'Pay without remembering'
						)
					)
				);
			}

			children.push(
				createElement( 'ul', { className: 'bkash-blocks-agreements', key: 'agreements' }, items )
			);
		}

		if ( ! settings.isLoggedIn && settings.integrationType !== 'checkout' && settings.integrationType !== 'tokenized' ) {
			children.push(
				createElement(
					'p',
					{ className: 'bkash-blocks-hint', key: 'hint' },
					i18n.rememberHint || 'To remember your bKash account number, please login and check remember'
				)
			);
		}

		return createElement( 'div', { className: 'bkash-blocks-content' }, children );
	}

	registry.registerPaymentMethod( {
		name: settings.name || methodName,
		paymentMethodId: settings.name || methodName,
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			if ( typeof settings.canMakePayment === 'boolean' ) {
				return settings.canMakePayment;
			}

			return true;
		},
		ariaLabel: label,
		placeOrderButtonLabel: settings.orderButtonText || 'Pay with bKash',
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
}() );
