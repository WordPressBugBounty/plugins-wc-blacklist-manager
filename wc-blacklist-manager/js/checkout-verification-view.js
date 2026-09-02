( function ( root, factory ) {
	'use strict';
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.WCBlacklistCheckoutVerificationView = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function escapeHtml( value ) {
		return String( value || '' ).replace( /[&<>'"]/g, function ( character ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[ character ];
		} );
	}

	function activeChannel( state ) {
		return ( state.channels || [] ).filter( function ( channel ) {
			return channel.id === state.active_channel;
		} )[ 0 ] || null;
	}

	function resendRemaining( state, now ) {
		var channel = activeChannel( state );
		var current = typeof now === 'number' ? now : Math.floor( Date.now() / 1000 );
		if ( ! channel || ! channel.resend_available_at ) {
			return 0;
		}
		return Math.max( 0, Number( channel.resend_available_at ) - current );
	}

	function formatStep( state, labels ) {
		if ( Number( state.total_steps || 0 ) <= 1 ) {
			return '';
		}
		return String( labels.step || 'Step %1$d of %2$d' )
			.replace( '%1$d', Number( state.current_step || 1 ) )
			.replace( '%2$d', Number( state.total_steps || 1 ) );
	}

	function channelMarkup( state, labels, busy, now, surface, idPrefix ) {
		var channel = activeChannel( state );
		if ( ! state.required ) {
			return '';
		}
		if ( state.ready ) {
			return '<p class="yobm-verification-complete">' + escapeHtml( labels.complete ) + '</p>';
		}
		if ( ! channel ) {
			return '';
		}
		var prefix = idPrefix || 'yobm-verification';
		var codeId = prefix + '-code-' + channel.id;
		var description = channel.masked_destination ? channel.label + ': ' + channel.masked_destination : channel.label;
		var remaining = resendRemaining( state, now );
		var resendText = remaining > 0 ? String( labels.resendIn || 'Resend available in %d seconds.' ).replace( '%d', remaining ) : '';
		var buttonClass = surface === 'classic' ? 'button ' : '';
		var progress = formatStep( state, labels );
		return '<div class="yobm-verification-channel" data-yobm-channel="' + escapeHtml( channel.id ) + '">' +
			( progress ? '<p class="yobm-verification-progress">' + escapeHtml( progress ) + '</p>' : '' ) +
			'<p class="yobm-verification-destination">' + escapeHtml( description ) + '</p>' +
			( channel.locked ? '<p class="yobm-verification-locked">' + escapeHtml( labels.locked ) + '</p>' : '' ) +
			'<div class="yobm-verification-controls">' +
			'<label class="yobm-verification-code-label" for="' + escapeHtml( codeId ) + '">' + escapeHtml( labels.codeLabel || labels.enterCode ) + '</label>' +
			'<input id="' + escapeHtml( codeId ) + '" class="yobm-verification-code" type="text" inputmode="numeric" autocomplete="one-time-code" ' + ( busy ? 'disabled' : '' ) + '>' +
			'<button type="button" class="' + buttonClass + 'yobm-verification-verify" ' + ( busy ? 'disabled aria-busy="true"' : '' ) + '>' + escapeHtml( busy ? labels.working : labels.verify ) + '</button>' +
			'<button type="button" class="' + buttonClass + 'yobm-verification-resend" ' + ( busy || remaining > 0 ? 'disabled' : '' ) + ( busy ? ' aria-busy="true"' : '' ) + '>' + escapeHtml( labels.resend ) + '</button>' +
			'</div><p class="yobm-verification-resend-status" ' + ( remaining > 0 ? '' : 'hidden' ) + '>' + escapeHtml( resendText ) + '</p></div>';
	}

	function liveMarkup( message, isError, idPrefix ) {
		return '<div id="' + escapeHtml( idPrefix ) + '-status" class="yobm-verification-live' + ( isError ? ' yobm-is-error' : '' ) + '" role="status" aria-live="polite" aria-atomic="true">' + escapeHtml( message ) + '</div>';
	}

	function dialogMarkup( body, status, labels, idPrefix, surface ) {
		var buttonClass = surface === 'classic' ? 'button ' : '';
		return '<button type="button" class="' + buttonClass + 'yobm-verification-open" aria-haspopup="dialog" aria-controls="' + escapeHtml( idPrefix ) + '-dialog" aria-expanded="false">' + escapeHtml( labels.open ) + '</button>' +
			'<div id="' + escapeHtml( idPrefix ) + '-dialog" class="yobm-verification-dialog" role="dialog" aria-modal="true" aria-labelledby="' + escapeHtml( idPrefix ) + '-title" hidden>' +
			'<div class="yobm-verification-backdrop"></div><div class="yobm-verification-panel" tabindex="-1">' +
			'<button type="button" class="yobm-verification-close" aria-label="' + escapeHtml( labels.close ) + '">&times;</button>' +
			'<h2 id="' + escapeHtml( idPrefix ) + '-title" class="yobm-verification-title">' + escapeHtml( labels.title ) + '</h2>' + body + status + '</div></div>';
	}

	function stepHeading( labels ) {
		return '<h2 class="yobm-verification-title">' + escapeHtml( labels.title ) + '</h2>';
	}

	function render( state, mode, labels, busy, message, isError, now, surface, host ) {
		var resolvedSurface = surface || 'classic';
		var idPrefix = 'yobm-verification-' + ( host || resolvedSurface );
		var content = channelMarkup( state, labels, busy, now, resolvedSurface, idPrefix );
		var status = liveMarkup( message, isError, idPrefix );
		var heading = state.required ? stepHeading( labels ) : '';
		if ( mode === 'popup_modal' && state.required && ! state.ready ) {
			return '<section class="yobm-verification-step">' + heading + dialogMarkup( content, status, labels, idPrefix, resolvedSurface ) + '</section>';
		}
		return '<section class="yobm-verification-step">' + heading + content + status + '</section>';
	}

	function nextIssueKey( state, context, previousKey, busy ) {
		var channel = activeChannel( state );
		if ( ! channel || channel.status === 'challenge_sent' || busy ) {
			return '';
		}
		var candidate = channel.id + ':' + JSON.stringify( context );
		return candidate === previousKey ? '' : candidate;
	}

	function validationError( state ) {
		return state.ready ? null : { message: 'Complete checkout verification before placing the order.', hidden: false };
	}

	return {
		activeChannel: activeChannel,
		resendRemaining: resendRemaining,
		formatStep: formatStep,
		channelMarkup: channelMarkup,
		dialogMarkup: dialogMarkup,
		stepHeading: stepHeading,
		render: render,
		nextIssueKey: nextIssueKey,
		validationError: validationError
	};
} ) );
