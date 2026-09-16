( function () {
	const wc = window.wc || {};
	const settingsApi = wc.wcSettings || {};
	const registry = wc.wcBlocksRegistry || {};
	const htmlEntities = window.wp && window.wp.htmlEntities ? window.wp.htmlEntities : null;
	const element = window.wp && window.wp.element ? window.wp.element : null;

	if ( ! settingsApi.getSetting || ! registry.registerPaymentMethod || ! htmlEntities || ! element ) {
		return;
	}

	const settings = settingsApi.getSetting( 'bkash-pgw-for-woocommerce_data', {} );
	const labelText = htmlEntities.decodeEntities( settings.title || 'bKash Payment Gateway' );
	const descriptionText = htmlEntities.decodeEntities( settings.description || '' );
	const supports = settings.supports || [ 'products' ];
	const icon = settings.icon || '';

	const Label = function () {
		const children = [];

		if ( icon ) {
			children.push(
				element.createElement( 'img', {
					src: icon,
					alt: labelText,
					style: {
						maxHeight: '24px',
						marginRight: '8px',
						verticalAlign: 'middle',
					},
				} )
			);
		}

		children.push( element.createElement( 'span', null, labelText ) );

		return element.createElement( 'span', null, children );
	};

	const Content = function () {
		return element.createElement( 'div', null, descriptionText );
	};

	registry.registerPaymentMethod( {
		name: 'bkash-pgw-for-woocommerce',
		label: element.createElement( Label, null ),
		content: element.createElement( Content, null ),
		edit: element.createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: labelText,
		supports: {
			features: supports,
		},
	} );
} )();
