( function ( window, document, $ ) {
	'use strict';

	if ( window.wcBlacklistCheckoutVerificationController ) {
		return;
	}

	var config = window.wcBlacklistCheckoutVerification || {};
	var view = window.WCBlacklistCheckoutVerificationView;
	if ( ! view ) {
		return;
	}
	var state = { ready: true, required: false, channels: [], active_channel: '' };
	var root = null;
	var nativeHosts = [];
	var hostSequence = 0;
	var fallbackRoot = null;
	var modal = null;
	var opener = null;
	var modalOpen = false;
	var requestSequence = 0;
	var mutationSequence = 0;
	var mutationOwner = null;
	var latestStatusRequest = 0;
	var issueKey = '';
	var presentationMessage = '';
	var presentationMessageType = 'status';
	var refreshTimer = null;
	var cooldownTimer = null;
	var validationId = 'yobm-checkout-verification';
	var channelRevisions = {};

	function fieldValue( names ) {
		var element;
		for ( var index = 0; index < names.length; index++ ) {
			element = document.querySelector( names[ index ] );
			if ( element && typeof element.value !== 'undefined' ) {
				return String( element.value || '' );
			}
		}
		return '';
	}

	function checkoutContext() {
		return {
			billing_email: fieldValue( [ '[name="billing_email"]', '#email', '#billing-email' ] ),
			billing_phone: fieldValue( [ '[name="billing_phone"]', '#phone', '#billing-phone' ] ),
			billing_dial_code: fieldValue( [ '[name="billing_dial_code"]' ] ),
			billing_country: fieldValue( [ '[name="billing_country"]', '#billing-country' ] ),
			billing_first_name: fieldValue( [ '[name="billing_first_name"]', '#billing-first_name' ] ),
			billing_last_name: fieldValue( [ '[name="billing_last_name"]', '#billing-last_name' ] ),
			billing_address_1: fieldValue( [ '[name="billing_address_1"]', '#billing-address_1' ] ),
			billing_address_2: fieldValue( [ '[name="billing_address_2"]', '#billing-address_2' ] ),
			billing_city: fieldValue( [ '[name="billing_city"]', '#billing-city' ] ),
			billing_state: fieldValue( [ '[name="billing_state"]', '#billing-state' ] ),
			billing_postcode: fieldValue( [ '[name="billing_postcode"]', '#billing-postcode' ] ),
			shipping_phone: fieldValue( [ '[name="shipping_phone"]', '#shipping-phone' ] ),
			shipping_dial_code: fieldValue( [ '[name="shipping_dial_code"]' ] ),
			shipping_country: fieldValue( [ '[name="shipping_country"]', '#shipping-country' ] )
		};
	}

	function mutationBusy() {
		return mutationOwner !== null;
	}

	function randomRequestId() {
		var bytes = new Uint8Array( 16 );
		if ( window.crypto && typeof window.crypto.getRandomValues === 'function' ) {
			window.crypto.getRandomValues( bytes );
			return Array.prototype.map.call( bytes, function ( value ) {
				return value.toString( 16 ).padStart( 2, '0' );
			} ).join( '' );
		}
		return String( Date.now() ) + String( Math.random() ).replace( /\D/g, '' ).padEnd( 16, '0' ).slice( 0, 16 );
	}

	function channelFromState( projected, channelId ) {
		return ( projected.channels || [] ).find( function ( item ) { return item.id === channelId; } ) || null;
	}

	function stateIsFresh( projected ) {
		return ! ( projected.channels || [] ).some( function ( channel ) {
			return Number( channel.state_revision || 0 ) < Number( channelRevisions[ channel.id ] || 0 );
		} );
	}

	function applyState( projected ) {
		if ( ! projected || ! stateIsFresh( projected ) ) {
			return false;
		}
		( projected.channels || [] ).forEach( function ( channel ) {
			channelRevisions[ channel.id ] = Math.max(
				Number( channelRevisions[ channel.id ] || 0 ),
				Number( channel.state_revision || 0 )
			);
		} );
		state = projected;
		return true;
	}

	function request( operation, channel, code ) {
		var mutating = operation !== 'status';
		if ( mutating && mutationBusy() ) {
			return Promise.reject( new Error( config.labels.working ) );
		}
		var requestToken = ++requestSequence;
		var requestId = randomRequestId();
		var contextAtStart = checkoutContext();
		var contextSnapshot = JSON.stringify( contextAtStart );
		var expectedChannel = channelFromState( state, channel );
		var mutationGenerationAtStart = mutationSequence;
		var statusOverlappedMutation = ! mutating && mutationBusy();
		if ( mutating ) {
			mutationSequence++;
			mutationGenerationAtStart = mutationSequence;
			mutationOwner = requestToken;
		} else {
			latestStatusRequest = requestToken;
		}
		render();

		var payload = new URLSearchParams();
		payload.append( 'action', config.action );
		payload.append( 'security', config.nonce );
		payload.append( 'operation', operation );
		payload.append( 'channel', channel || '' );
		payload.append( 'code', code || '' );
		payload.append( 'context', contextSnapshot );
		if ( mutating ) {
			payload.append( 'request_id', requestId );
			payload.append( 'expected_revision', expectedChannel ? Number( expectedChannel.state_revision || 0 ) : 0 );
			payload.append( 'expected_generation', expectedChannel ? Number( expectedChannel.generation || 0 ) : 0 );
			payload.append( 'expected_challenge_id', expectedChannel ? String( expectedChannel.challenge_id || '' ) : '' );
		}

		function canApplyStatus() {
			return ! statusOverlappedMutation &&
				! mutationBusy() &&
				mutationGenerationAtStart === mutationSequence &&
				requestToken === latestStatusRequest;
		}

		function send() {
			return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: payload.toString()
			} );
		}

		return send().catch( function () {
			return send();
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			var responseState = response && response.data && response.data.state ? response.data.state : null;
			var contextUnchanged = contextSnapshot === JSON.stringify( checkoutContext() );
			var shouldApply = mutating ? contextUnchanged && stateIsFresh( responseState || state ) : canApplyStatus();
			if ( ! response || ! response.success ) {
				if ( shouldApply && responseState ) {
					applyState( responseState );
				}
				if ( mutating && ! shouldApply ) {
					scheduleRefresh();
				}
				throw new Error( response && response.data && response.data.message ? response.data.message : 'Verification request failed.' );
			}
			if ( shouldApply && response.data && response.data.state ) {
				shouldApply = applyState( response.data.state );
			}
			if ( mutating && ! shouldApply ) {
				scheduleRefresh();
			}
			if ( shouldApply ) {
				setMessage( response.data && response.data.message ? response.data.message : '' );
			}
			return state;
		} ).catch( function ( error ) {
			if ( mutating || canApplyStatus() ) {
				setMessage( error.message || 'Verification request failed.', true );
			}
			throw error;
		} ).finally( function () {
			if ( mutating && mutationOwner === requestToken ) {
				mutationOwner = null;
			}
			render();
		} );
	}

	function activeChannel() {
		return view.activeChannel( state );
	}

	function maybeIssueActive() {
		var channel = activeChannel();
		if ( ! channel || channel.status === 'challenge_sent' || mutationBusy() ) {
			return;
		}
		var nextKey = view.nextIssueKey( state, checkoutContext(), issueKey, mutationBusy() );
		if ( ! nextKey ) {
			return;
		}
		issueKey = nextKey;
		request( 'issue', channel.id ).catch( function () {} );
	}

	function setMessage( message, isError ) {
		presentationMessage = message || '';
		presentationMessageType = isError ? 'error' : 'status';
		if ( root ) {
			root.dataset.message = presentationMessage;
			root.dataset.messageType = presentationMessageType;
		}
	}

	function updateValidationStore() {
		if ( ! window.wp || ! wp.data || ! root || root.dataset.yobmContext !== 'blocks' ) {
			return;
		}
		var dispatcher = wp.data.dispatch( 'wc/store/validation' );
		if ( ! dispatcher ) {
			return;
		}
		if ( state.ready && typeof dispatcher.clearValidationError === 'function' ) {
			dispatcher.clearValidationError( validationId );
		} else if ( ! state.ready && typeof dispatcher.setValidationErrors === 'function' ) {
			var errors = {};
			errors[ validationId ] = { message: 'Complete checkout verification before placing the order.', hidden: false };
			dispatcher.setValidationErrors( errors );
		}
	}

	function focusSelector() {
		if ( ! modalOpen || ! root || ! document.activeElement || ! root.contains( document.activeElement ) ) {
			return '';
		}
		var selectors = [ '.yobm-verification-code', '.yobm-verification-verify', '.yobm-verification-resend', '.yobm-verification-close' ];
		for ( var index = 0; index < selectors.length; index++ ) {
			if ( document.activeElement.matches( selectors[ index ] ) ) {
				return selectors[ index ];
			}
		}
		return '';
	}

	function restoreOpenerFocus() {
		document.body.classList.remove( 'yobm-verification-modal-open' );
		if ( opener && opener.isConnected === false && root ) {
			opener = root.querySelector( '.yobm-verification-open' ) || opener;
		}
		if ( opener && typeof opener.focus === 'function' ) {
			opener.focus();
		}
		opener = null;
	}

	function syncTriggerState() {
		if ( ! root ) {
			return;
		}
		var trigger = root.querySelector( '.yobm-verification-open' );
		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', modalOpen ? 'true' : 'false' );
		}
	}

	function syncModalState( previousFocus ) {
		if ( ! modal ) {
			if ( modalOpen ) {
				modalOpen = false;
				restoreOpenerFocus();
			}
			return;
		}
		modal.hidden = ! modalOpen;
		syncTriggerState();
		if ( ! modalOpen ) {
			document.body.classList.remove( 'yobm-verification-modal-open' );
			return;
		}
		document.body.classList.add( 'yobm-verification-modal-open' );
		var focusTarget = previousFocus ? modal.querySelector( previousFocus ) : null;
		if ( ! focusTarget || focusTarget.disabled ) {
			focusTarget = modal.querySelector( '.yobm-verification-code:not([disabled]), button:not([disabled]), [tabindex="-1"]' );
		}
		if ( focusTarget ) {
			focusTarget.focus();
		}
	}

	function updateCooldownPresentation() {
		if ( ! root ) {
			return;
		}
		var remaining = view.resendRemaining( state );
		var button = root.querySelector( '.yobm-verification-resend' );
		var status = root.querySelector( '.yobm-verification-resend-status' );
		if ( button ) {
			button.disabled = mutationBusy() || remaining > 0;
		}
		if ( status ) {
			status.hidden = remaining <= 0;
			status.textContent = remaining > 0
				? String( config.labels.resendIn || 'Resend available in %d seconds.' ).replace( '%d', remaining )
				: '';
		}
	}

	function scheduleCooldownTick() {
		window.clearTimeout( cooldownTimer );
		cooldownTimer = null;
		if ( view.resendRemaining( state ) <= 0 ) {
			return;
		}
		cooldownTimer = window.setTimeout( function () {
			updateCooldownPresentation();
			scheduleCooldownTick();
		}, 1000 );
	}

	function render() {
		if ( ! root ) {
			return;
		}
		var previousFocus = focusSelector();
		var message = presentationMessage;
		var messageClass = presentationMessageType === 'error' ? ' yobm-is-error' : '';
		root.innerHTML = view.render(
			state,
			config.mode,
			config.labels,
			mutationBusy(),
			message,
			messageClass !== '',
			undefined,
			root.dataset.yobmContext || 'classic',
			root.dataset.yobmHost || 'classic'
		);
		modal = root.querySelector( '.yobm-verification-dialog' );
		syncModalState( previousFocus );
		updateCooldownPresentation();
		scheduleCooldownTick();
		updateValidationStore();
		maybeIssueActive();
	}

	function openModal( trigger ) {
		if ( ! modal ) {
			return;
		}
		opener = trigger || document.activeElement;
		modalOpen = true;
		modal.hidden = false;
		syncTriggerState();
		document.body.classList.add( 'yobm-verification-modal-open' );
		var focusTarget = modal.querySelector( '.yobm-verification-code:not([disabled]), button:not([disabled]), [tabindex="-1"]' );
		if ( focusTarget ) {
			focusTarget.focus();
		}
		request( 'status', '' ).catch( function () {} );
	}

	function closeModal() {
		modalOpen = false;
		if ( modal ) {
			modal.hidden = true;
		}
		syncTriggerState();
		restoreOpenerFocus();
	}

	function trapFocus( event ) {
		if ( ! modal || modal.hidden || event.key !== 'Tab' ) {
			return;
		}
		var focusable = Array.prototype.slice.call( modal.querySelectorAll( 'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])' ) );
		if ( ! focusable.length ) {
			return;
		}
		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	function handleClick( event ) {
		var channel = activeChannel();
		if ( event.target.closest( '.yobm-verification-open' ) ) {
			openModal( event.target );
			return;
		}
		if ( event.target.closest( '.yobm-verification-close, .yobm-verification-backdrop' ) ) {
			closeModal();
			return;
		}
		if ( ! channel || mutationBusy() ) {
			return;
		}
		if ( event.target.closest( '.yobm-verification-verify' ) ) {
			var input = root.querySelector( '.yobm-verification-code' );
			request( 'verify', channel.id, input ? input.value : '' ).then( maybeIssueActive ).catch( function () {} );
		} else if ( event.target.closest( '.yobm-verification-resend' ) ) {
			if ( view.resendRemaining( state ) > 0 ) {
				return;
			}
			request( 'resend', channel.id ).catch( function () {} );
		}
	}

	function scheduleRefresh() {
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( function () {
			issueKey = '';
			request( 'status', '' ).catch( function () {} );
		}, 250 );
	}

	function bindRoot( candidate ) {
		if ( ! candidate || candidate.dataset.yobmBound === 'true' ) {
			return;
		}
		candidate.dataset.yobmBound = 'true';
		candidate.addEventListener( 'click', handleClick );
	}

	function activateRoot( candidate ) {
		if ( ! candidate ) {
			return;
		}
		if ( root && root !== candidate ) {
			root.hidden = true;
			root.inert = true;
			root.setAttribute( 'aria-hidden', 'true' );
			root.innerHTML = '';
		}
		if ( fallbackRoot && fallbackRoot !== candidate ) {
			fallbackRoot.hidden = true;
			fallbackRoot.inert = true;
			fallbackRoot.setAttribute( 'aria-hidden', 'true' );
			fallbackRoot.innerHTML = '';
		}
		root = candidate;
		root.hidden = false;
		root.inert = false;
		root.removeAttribute( 'aria-hidden' );
		root.dataset.yobmMode = config.mode || 'inline';
		root.dataset.message = presentationMessage;
		root.dataset.messageType = presentationMessageType;
		bindRoot( root );
		render();
	}

	function findFallbackRoot() {
		var roots = typeof document.querySelectorAll === 'function'
			? document.querySelectorAll( '.yobm-checkout-verification-root' )
			: [];
		for ( var index = 0; index < roots.length; index++ ) {
			if ( roots[ index ].dataset.yobmHost !== 'native' ) {
				return roots[ index ];
			}
		}
		return document.querySelector( '.yobm-checkout-verification-root' );
	}

	function connectedNativeHosts() {
		nativeHosts = nativeHosts.filter( function ( record ) {
			return record.element && record.element.isConnected !== false;
		} );
		return nativeHosts.slice().sort( function ( left, right ) {
			var leftTree = left.element.dataset.yobmPlacement === 'tree' ? 0 : 1;
			var rightTree = right.element.dataset.yobmPlacement === 'tree' ? 0 : 1;
			return leftTree === rightTree ? left.sequence - right.sequence : leftTree - rightTree;
		} );
	}

	function electHost() {
		var candidates = connectedNativeHosts();
		var elected = candidates.length ? candidates[0].element : null;
		candidates.forEach( function ( record ) {
			var candidate = record.element;
			if ( candidate !== elected ) {
				candidate.hidden = true;
				candidate.inert = true;
				candidate.setAttribute( 'aria-hidden', 'true' );
				candidate.innerHTML = '';
			}
		} );
		return elected || ( fallbackRoot && fallbackRoot.isConnected !== false ? fallbackRoot : null );
	}

	function attachHost( candidate ) {
		if ( ! candidate ) {
			return;
		}
		if ( candidate.dataset.yobmHost === 'native' ) {
			if ( ! nativeHosts.some( function ( record ) { return record.element === candidate; } ) ) {
				nativeHosts.push( { element: candidate, sequence: ++hostSequence } );
			}
		} else {
			fallbackRoot = candidate;
		}
		activateRoot( electHost() );
	}

	function detachHost( candidate ) {
		nativeHosts = nativeHosts.filter( function ( record ) { return record.element !== candidate; } );
		if ( candidate === fallbackRoot ) {
			fallbackRoot = null;
		}
		if ( candidate === root ) {
			fallbackRoot = fallbackRoot && fallbackRoot.isConnected !== false ? fallbackRoot : findFallbackRoot();
			root = null;
			var elected = electHost();
			if ( elected && elected !== candidate ) {
				activateRoot( elected );
			}
		}
	}

	function refreshHost() {
		fallbackRoot = findFallbackRoot();
		activateRoot( electHost() );
	}

	function init() {
		( window.wcBlacklistCheckoutVerificationPendingHosts || [] ).forEach( function ( candidate ) {
			attachHost( candidate );
		} );
		window.wcBlacklistCheckoutVerificationPendingHosts = [];
		fallbackRoot = findFallbackRoot();
		if ( ! fallbackRoot && ! connectedNativeHosts().length ) {
			return;
		}
		activateRoot( electHost() );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && modal && ! modal.hidden ) {
				closeModal();
			} else {
				trapFocus( event );
			}
		} );
		document.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[name^="billing_"], [name^="shipping_"], #email, #phone, [id^="billing-"], [id^="shipping-"]' ) ) {
				scheduleRefresh();
			}
		} );
		if ( $ ) {
			$( document.body ).on( 'updated_checkout', function () {
				refreshHost();
				scheduleRefresh();
			} );
			$( 'form.checkout' ).on( 'checkout_place_order', function () {
				if ( ! state.ready ) {
					if ( config.mode === 'popup_modal' ) {
						openModal( document.querySelector( '#place_order' ) );
					}
					return false;
				}
				return true;
			} );
		}
		request( 'status', '' ).catch( function () {} );
	}

	window.wcBlacklistCheckoutVerificationController = {
		init: init,
		request: request,
		getState: function () { return state; },
		open: openModal,
		close: closeModal,
		attachHost: attachHost,
		detachHost: detachHost
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document, window.jQuery ) );
