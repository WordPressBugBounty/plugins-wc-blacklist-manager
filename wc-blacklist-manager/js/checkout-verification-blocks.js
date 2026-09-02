( function ( window, document ) {
	'use strict';
	var diagnostics = window.wcBlacklistCheckoutVerificationRegistrationDiagnostics = {
		executed: true,
		readyState: document.readyState,
		scriptIndex: document.currentScript && document.scripts ? Array.prototype.indexOf.call( document.scripts, document.currentScript ) : -1,
		registered: false,
		renderCount: 0,
		effectMountCount: 0,
		effectCleanupCount: 0
	};

	var blocksCheckout = window.wc && window.wc.blocksCheckout;
	var element = window.wp && window.wp.element;
	if ( ! blocksCheckout || typeof blocksCheckout.registerCheckoutBlock !== 'function' || ! element ) {
		diagnostics.unavailable = true;
		return;
	}

	function VerificationHost( props ) {
		diagnostics.renderCount++;
		diagnostics.lastProps = props ? Object.keys( props ).sort() : [];
		var hostRef = element.useRef( null );

		element.useEffect( function () {
			diagnostics.effectMountCount++;
			var host = hostRef.current;
			diagnostics.hostConnectedAtMount = Boolean( host && host.isConnected );
			diagnostics.controllerAvailableAtMount = Boolean( window.wcBlacklistCheckoutVerificationController );
			var controller = window.wcBlacklistCheckoutVerificationController;
			if ( host && controller && typeof controller.attachHost === 'function' ) {
				controller.attachHost( host );
			} else if ( host ) {
				window.wcBlacklistCheckoutVerificationPendingHosts = window.wcBlacklistCheckoutVerificationPendingHosts || [];
				window.wcBlacklistCheckoutVerificationPendingHosts.push( host );
			}
			return function () {
				diagnostics.effectCleanupCount++;
				diagnostics.hostConnectedAtCleanup = Boolean( host && host.isConnected );
				var liveController = window.wcBlacklistCheckoutVerificationController;
				if ( window.wcBlacklistCheckoutVerificationPendingHosts ) {
					window.wcBlacklistCheckoutVerificationPendingHosts = window.wcBlacklistCheckoutVerificationPendingHosts.filter( function ( candidate ) {
						return candidate !== host;
					} );
				}
				if ( host && liveController && typeof liveController.detachHost === 'function' ) {
					liveController.detachHost( host );
				}
			};
		}, [] );

		return element.createElement( 'div', {
			className: 'yobm-checkout-verification-root yobm-checkout-verification-native',
			'data-yobm-context': 'blocks',
			'data-yobm-host': 'native',
			'data-yobm-placement': props && props.yobmPlacement ? props.yobmPlacement : undefined,
			'data-yobm-profile': props && props.yobmProfile ? props.yobmProfile : undefined,
			ref: hostRef
		} );
	}

	blocksCheckout.registerCheckoutBlock( {
		metadata: {
			name: 'wc-blacklist-manager/checkout-verification',
			parent: [ 'woocommerce/checkout-fields-block' ],
			attributes: {
				lock: {
					type: 'object',
					default: { remove: true, move: false }
				}
			}
		},
		component: VerificationHost,
		force: true
	} );
	diagnostics.registered = true;
}( window, document ) );
