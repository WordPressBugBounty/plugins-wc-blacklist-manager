(function ($) {
	'use strict';

	var templateId = 'yobm-order-action-modal';
	var modalSequence = 0;
	var activeModal = null;
	var capabilityRefreshScheduled = false;
	var requestState = {
		suspect: false,
		block: false,
		remove: false
	};

	function getConfig() {
		return window.yobmOrderActions || {};
	}

	function getCommonLabels() {
		return getConfig().common || {};
	}

	function getOrderId() {
		var config = getConfig();

		if (config.orderId) {
			return String(config.orderId);
		}

		if (window.woocommerce_admin_meta_boxes && window.woocommerce_admin_meta_boxes.post_id) {
			return String(window.woocommerce_admin_meta_boxes.post_id);
		}

		var urlParams = new URLSearchParams(window.location.search);
		return urlParams.get('id') || null;
	}

	function showNotice(type, message) {
		message = String(message || '').trim();

		if (!message) {
			return;
		}

		$('.bm-ajax-notice').remove();

		var $notice = $(
			'<div class="notice notice-' + type + ' is-dismissible bm-ajax-notice"><p></p></div>'
		);

		$notice.find('p').text(message);
		$('div.wrap').first().prepend($notice);
	}

	function extractMessage(response, fallback) {
		if (response && response.data && response.data.message) {
			return String(response.data.message).trim();
		}

		return String(fallback || '').trim();
	}

	function extractXhrMessage(xhr, fallback) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return String(xhr.responseJSON.data.message).trim();
		}

		return String(fallback || '').trim();
	}

	function reloadSoon() {
		setTimeout(function () {
			window.location.reload();
		}, 800);
	}

	function disableActionButtons(selector) {
		$(selector).prop('disabled', true).addClass('disabled');
	}

	function enableActionButtons(selector) {
		$(selector).prop('disabled', false).removeClass('disabled');
	}

	function setButtonProcessing($button, processingText) {
		if (!$button || !$button.length) {
			return;
		}

		if (typeof $button.data('original-text') === 'undefined') {
			$button.data('original-text', $button.text());
		}

		$button.text(processingText).prop('disabled', true).addClass('disabled');
	}

	function resetButtonProcessing($button) {
		if (!$button || !$button.length) {
			return;
		}

		var originalText = $button.data('original-text');

		if (typeof originalText !== 'undefined') {
			$button.text(originalText);
		}

		$button.prop('disabled', false).removeClass('disabled');
	}

	function postJson(data, done, fail) {
		var config = getConfig();

		return $.ajax({
			url: config.ajaxUrl,
			type: 'POST',
			data: data,
			dataType: 'json'
		})
			.done(function (response) {
				if (typeof done === 'function') {
					done(response);
				}
			})
			.fail(function (xhr) {
				if (typeof fail === 'function') {
					fail(xhr);
				}
			});
	}

	function beginRequest(action) {
		if (requestState[action]) {
			return false;
		}

		requestState[action] = true;
		return true;
	}

	function finishRequest(action) {
		requestState[action] = false;
	}

	function createMutationLifecycle() {
		var processing = false;
		var removed = false;
		var succeeded = false;

		return {
			begin: function () {
				if (processing || removed || succeeded) {
					return false;
				}

				processing = true;
				return true;
			},
			fail: function () {
				if (!removed) {
					processing = false;
				}
			},
			succeed: function () {
				processing = false;
				succeeded = true;
			},
			remove: function () {
				processing = false;
				removed = true;
			},
			isProcessing: function () {
				return processing;
			},
			canClose: function () {
				return !processing && !removed;
			}
		};
	}

	function nativeModalAvailable() {
		return !!(
			$.fn &&
			typeof $.fn.WCBackboneModal === 'function' &&
			window.wp &&
			typeof window.wp.template === 'function'
		);
	}

	function singletonIsActive() {
		return $('#wc-backbone-modal-dialog').length > 0;
	}

	function modalConfig(action) {
		var section = getConfig()[action] || {};
		var freshUntil = Number(section.capabilityFreshUntil);

		if (
			'block' === action &&
			'v2' === section.contractMode &&
			(
				!isFinite(freshUntil) ||
				freshUntil <= 0 ||
				Math.floor(Date.now() / 1000) > freshUntil
			)
		) {
			scheduleCapabilityRefresh();
			return section.legacyFallback || {};
		}

		return section;
	}

	function scheduleCapabilityRefresh() {
		if (capabilityRefreshScheduled) {
			return;
		}

		capabilityRefreshScheduled = true;
		var nonces = getConfig().nonces || {};
		postJson({
			action: 'yogb_bm_schedule_report_v2_capability_refresh',
			order_id: getOrderId(),
			nonce: nonces.reportV2CapabilityRefresh || ''
		});
	}

	function mainButtonSelector(action) {
		return 'block' === action ? '#block_customer' : '#remove_from_blacklist';
	}

	function otherReason(action, section) {
		if ('block' === action && section && 'v2' === section.contractMode) {
			return 'unclassified';
		}
		return 'block' === action ? 'other' : 'rvk_other';
	}

	function validateAction(action, reason, description, labels, section) {
		if (!reason) {
			return labels.required_reason || 'Please select a reason.';
		}

		if (reason === otherReason(action, section) && !description) {
			return labels.required_desc || 'Please enter a description.';
		}

		return '';
	}

	function requestData(action, orderId, reason, description, section) {
		var config = getConfig();
		var nonces = config.nonces || {};

		if ('block' === action) {
			var blockData = {
				action: 'block_customer',
				order_id: orderId,
				nonce: nonces.block || '',
				reason_code: reason,
				description: description
			};
			if (section && section.contractMode) {
				blockData.report_contract = section.contractMode;
				blockData.report_contract_nonce = section.contractNonce || '';
			}
			return blockData;
		}

		return {
			action: 'remove_from_blacklist',
			order_id: orderId,
			nonce: nonces.remove || '',
			revoke_reason: reason,
			revoke_note: description
		};
	}

	function modalIsCurrent(modal) {
		return !!(
			modal &&
			activeModal === modal &&
			modal.$dialog &&
			modal.$dialog.length &&
			$.contains(document, modal.$dialog.get(0))
		);
	}

	function setInlineError(modal, message) {
		if (!modalIsCurrent(modal)) {
			return;
		}

		modal.$error.text(String(message || '')).prop('hidden', !message);
	}

	function setCloseEnabled(modal, enabled) {
		if (!modal || !modal.$dialog || !modal.$dialog.length) {
			return;
		}

		var $controls = modal.$dialog.find('.yobm-order-action-close, .yobm-order-action-cancel');

		if (enabled) {
			$controls.addClass('modal-close').removeAttr('aria-disabled');
			modal.$dialog.find('.yobm-order-action-cancel').prop('disabled', false);
			modal.closeEnabled = true;
			return;
		}

		$controls.removeClass('modal-close').attr('aria-disabled', 'true');
		modal.$dialog.find('.yobm-order-action-cancel').prop('disabled', true);
		modal.closeEnabled = false;
	}

	function setProcessing(modal, processing) {
		if (!modalIsCurrent(modal)) {
			return;
		}

		modal.processing = processing;
		setCloseEnabled(modal, !processing);

		if (processing) {
			setButtonProcessing(modal.$submit, modal.labels.processingText || 'Processing...');
			disableActionButtons(mainButtonSelector(modal.action));
			return;
		}

		resetButtonProcessing(modal.$submit);
		enableActionButtons(mainButtonSelector(modal.action));
	}

	function restoreTriggerFocus(modal) {
		if (
			modal &&
			modal.$trigger &&
			modal.$trigger.length &&
			$.contains(document, modal.$trigger.get(0))
		) {
			modal.$trigger.trigger('focus');
		}
	}

	function cleanupModal(modal) {
		if (!modal || activeModal !== modal) {
			return;
		}

		$(document.body).off('.yobmOrderActionsModal');
		finishRequest(modal.action);
		modal.lifecycle.remove();
		modal.removed = true;
		activeModal = null;
		restoreTriggerFocus(modal);
	}

	function closeModal(modal) {
		if (!modalIsCurrent(modal) || !modal.lifecycle.canClose()) {
			return false;
		}

		setCloseEnabled(modal, true);
		modal.$dialog.find('.yobm-order-action-close').first().trigger('click');
		return true;
	}

	function updateReasonDescription(modal) {
		var reason = modal.$reason.val();
		var description = modal.descriptions[reason] || '';
		var showDescription = !!description && reason !== otherReason(modal.action);

		modal.$reasonDescription.find('p').text(showDescription ? description : '');
		modal.$reasonDescription.prop('hidden', !showDescription);
	}

	function populateCta(modal) {
		var cta = modal.section.cta || '';
		var $wrap = modal.$dialog.find('.yobm-order-action-cta');

		if (!cta || !cta.url || !cta.cta) {
			$wrap.prop('hidden', true);
			return;
		}

		$wrap.find('strong').text(cta.title || '');
		$wrap.find('p').text(cta.message || '');
		$wrap.find('a').attr('href', cta.url).text(cta.cta);
		$wrap.prop('hidden', false);
	}

	function finishFailedRequest(modal, message) {
		finishRequest(modal.action);
		modal.lifecycle.fail();

		if (!modalIsCurrent(modal)) {
			return;
		}

		setProcessing(modal, false);
		setInlineError(modal, message);
		modal.$submit.trigger('focus');
	}

	function submitModal(modal) {
		if (!modalIsCurrent(modal) || !modal.lifecycle.begin()) {
			return;
		}

		if (!beginRequest(modal.action)) {
			modal.lifecycle.fail();
			return;
		}

		var common = getCommonLabels();
		var orderId = getOrderId();
		var reason = String(modal.$reason.val() || '');
		var description = String(modal.$description.val() || '').trim();
		var validationError = validateAction(modal.action, reason, description, modal.labels, modal.section);

		if (!orderId) {
			finishRequest(modal.action);
			modal.lifecycle.fail();
			setInlineError(modal, common.order_missing || 'Order ID not found.');
			return;
		}

		if (validationError) {
			finishRequest(modal.action);
			modal.lifecycle.fail();
			setInlineError(modal, validationError);
			return;
		}

		setInlineError(modal, '');
		setProcessing(modal, true);

		postJson(
			requestData(modal.action, orderId, reason, description, modal.section),
			function (response) {
				var message = extractMessage(response, common.request_failed || 'The request failed. Please try again.');

				if (!response || !response.success) {
					finishFailedRequest(modal, message);
					return;
				}

				finishRequest(modal.action);
				modal.lifecycle.succeed();

				if (!modalIsCurrent(modal)) {
					return;
				}

				modal.processing = false;
				setCloseEnabled(modal, true);
				closeModal(modal);
				showNotice('success', message);
				reloadSoon();
			},
			function (xhr) {
				finishFailedRequest(
					modal,
					extractXhrMessage(xhr, common.request_failed || 'The request failed. Please try again.')
				);
			}
		);
	}

	function bindModalLifecycle(modal) {
		$(document.body)
			.off('.yobmOrderActionsModal')
			.on('wc_backbone_modal_removed.yobmOrderActionsModal', function (event, target) {
				if (target === templateId) {
					cleanupModal(modal);
				}
			});

		modal.$reason.on('change.yobmOrderActionsModal', function () {
			updateReasonDescription(modal);
			setInlineError(modal, '');
		});

		modal.$submit.on('click.yobmOrderActionsModal', function (event) {
			event.preventDefault();
			event.stopPropagation();
			submitModal(modal);
		});

		modal.$dialog.on('click.yobmOrderActionsModal', '.yobm-order-action-cancel, .yobm-order-action-close', function (event) {
			if (!modal.processing) {
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();
		});

		var modalRoot = modal.$dialog.find('.wc-backbone-modal').get(0);

		if (modalRoot) {
			modalRoot.addEventListener(
				'keydown',
				function (event) {
					var key = event.key || '';
					var keyCode = event.keyCode || event.which;

					if (modal.processing && ('Escape' === key || 27 === keyCode)) {
						event.preventDefault();
						event.stopPropagation();
						event.stopImmediatePropagation();
					}
				},
				true
			);
		}
	}

	function populateModal(modal) {
		var labels = modal.labels;
		var $title = modal.$dialog.find('#yobm-order-action-modal-title');

		$title.text(labels.modal_title || '');
		modal.$dialog.find('#yobm-order-action-reason-label').text(labels.reason_label || '');
		modal.$dialog.find('#yobm-order-action-description-label').text(labels.description_label || '');
		modal.$dialog.find('.yobm-order-action-cancel').text(labels.cancel || 'Cancel');
		modal.$submit.text(labels.confirm || 'Confirm');

		modal.$reason.empty().append(
			$('<option>', {
				value: '',
				text: labels.select_reason || 'Select a reason...',
				disabled: true,
				selected: true
			})
		);

		var grouped = 'v2' === modal.section.contractMode;
		var $recommendedGroup = $('<optgroup>', { label: labels.recommended || 'Recommended' });
		var $applicableGroup = $('<optgroup>', { label: labels.applicable || 'Applicable' });
		$.each(modal.section.reasons || {}, function (value, label) {
			var meta = modal.reasonMeta[value] || {};
			var prefix = '';
			if (!grouped && 'recommended' === meta.presentation) {
				prefix = (labels.recommended || 'Recommended') + ': ';
			} else if ('impossible' === meta.presentation) {
				prefix = (labels.impossible || 'Unavailable') + ': ';
			}
			var $option = $('<option>', {
				value: value,
				text: prefix + label,
				disabled: !!meta.disabled
			});
			if (!grouped) {
				modal.$reason.append($option);
			} else if ('recommended' === meta.presentation) {
				$recommendedGroup.append($option);
			} else {
				$applicableGroup.append($option);
			}
		});
		if (grouped) {
			modal.$reason.append($recommendedGroup, $applicableGroup);
		}

		modal.$description.val('');
		modal.$dialog.find('.yobm-order-action-disclosure')
			.text(labels.disclosure || '')
			.prop('hidden', !labels.disclosure);
		modal.$reasonDescription.prop('hidden', true).find('p').text('');
		setInlineError(modal, '');
		populateCta(modal);
		updateReasonDescription(modal);
		modal.$reason.trigger('focus');
	}

	function openActionModal(action, $trigger) {
		var common = getCommonLabels();

		if (activeModal) {
			if (modalIsCurrent(activeModal)) {
				activeModal.$dialog.find('.wc-backbone-modal-content').trigger('focus');
			}
			return false;
		}

		if (!nativeModalAvailable()) {
			showNotice('error', common.modal_unavailable || 'WooCommerce modal is unavailable.');
			$trigger.trigger('focus');
			return false;
		}

		if (singletonIsActive()) {
			showNotice('error', common.modal_busy || 'Another modal is already open.');
			$trigger.trigger('focus');
			return false;
		}

		$(document.body).WCBackboneModal({
			template: templateId,
			variable: {}
		});

		var $dialog = $('#wc-backbone-modal-dialog');

		if (!$dialog.length || !$dialog.find('.yobm-order-action-modal').length) {
			showNotice('error', common.modal_unavailable || 'WooCommerce modal is unavailable.');
			$trigger.trigger('focus');
			return false;
		}

		var section = modalConfig(action);
		var modal = {
			token: ++modalSequence,
			action: action,
			section: section,
			labels: section.labels || {},
			descriptions: section.descriptions || {},
			reasonMeta: section.reasonMeta || {},
			$trigger: $trigger,
			$dialog: $dialog,
			$reason: $dialog.find('#yobm-order-action-reason'),
			$description: $dialog.find('#yobm-order-action-description'),
			$reasonDescription: $dialog.find('.yobm-order-action-reason-description'),
			$error: $dialog.find('.yobm-order-action-error'),
			$submit: $dialog.find('.yobm-order-action-submit'),
			lifecycle: createMutationLifecycle(),
			processing: false,
			closeEnabled: true,
			removed: false
		};

		activeModal = modal;
		finishRequest(action);
		bindModalLifecycle(modal);
		populateModal(modal);
		return true;
	}

	function bindSuspectAction() {
		$(document)
			.off('click.yobmOrderActions', '#add_to_blacklist')
			.on('click.yobmOrderActions', '#add_to_blacklist', function (event) {
				event.preventDefault();

				if (!beginRequest('suspect')) {
					return;
				}

				var config = getConfig();
				var common = getCommonLabels();
				var labels = config.suspect || {};
				var orderId = getOrderId();
				var $button = $(this);

				if (!orderId) {
					finishRequest('suspect');
					showNotice('error', common.order_missing || 'Order ID not found.');
					return;
				}

				if (!window.confirm(labels.confirmMessage || 'Are you sure?')) {
					finishRequest('suspect');
					return;
				}

				setButtonProcessing($button, labels.processingText || 'Processing...');

				postJson(
					{
						action: 'add_to_blacklist',
						order_id: orderId,
						nonce: config.nonces ? config.nonces.suspect : ''
					},
					function (response) {
						var message = extractMessage(response, common.request_failed || 'The request failed. Please try again.');

						if (!response || !response.success) {
							finishRequest('suspect');
							resetButtonProcessing($button);
							showNotice('error', message);
							return;
						}

						showNotice('success', message);
						reloadSoon();
					},
					function (xhr) {
						finishRequest('suspect');
						resetButtonProcessing($button);
						showNotice(
							'error',
							extractXhrMessage(xhr, common.request_failed || 'The request failed. Please try again.')
						);
					}
				);
			});
	}

	function bindModalAction(triggerSelector, action) {
		$(document)
			.off('click.yobmOrderActions', triggerSelector)
			.on('click.yobmOrderActions', triggerSelector, function (event) {
				event.preventDefault();

				if (requestState[action]) {
					return;
				}

				openActionModal(action, $(this));
			});
	}

	if (window.yobmOrderActionsTestHooks) {
		window.yobmOrderActionsTestHooks.beginRequest = beginRequest;
		window.yobmOrderActionsTestHooks.createMutationLifecycle = createMutationLifecycle;
		window.yobmOrderActionsTestHooks.finishRequest = finishRequest;
		window.yobmOrderActionsTestHooks.validateAction = validateAction;
		window.yobmOrderActionsTestHooks.requestData = requestData;
		window.yobmOrderActionsTestHooks.nativeModalAvailable = nativeModalAvailable;
		window.yobmOrderActionsTestHooks.modalConfig = modalConfig;
		window.yobmOrderActionsTestHooks.getRequestState = function () {
			return {
				suspect: requestState.suspect,
				block: requestState.block,
				remove: requestState.remove
			};
		};
	}

	$(function () {
		bindSuspectAction();
		bindModalAction('#block_customer', 'block');
		bindModalAction('#remove_from_blacklist', 'remove');
	});
}(jQuery));
