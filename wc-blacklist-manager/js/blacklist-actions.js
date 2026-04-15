jQuery(function ($) {
	var requestState = {
		suspect: false,
		block: false,
		remove: false
	};

	function getConfig() {
		return window.yobmOrderActions || {};
	}

	function getOrderId() {
		var config = getConfig();

		if (config.orderId) {
			return String(config.orderId);
		}

		if (window.woocommerce_admin_meta_boxes && woocommerce_admin_meta_boxes.post_id) {
			return String(woocommerce_admin_meta_boxes.post_id);
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

		if (xhr && xhr.responseText) {
			return String(xhr.responseText).trim();
		}

		return String(fallback || 'An error occurred.').trim();
	}

	function reloadSoon() {
		setTimeout(function () {
			window.location.reload();
		}, 800);
	}

	function closeModal() {
		$('#bmModal').hide();
		$('#bmModalBackdrop').hide();
	}

	function openModal() {
		$('#bmModalBackdrop').show();
		$('#bmModal').show();
	}

	function resetModalError() {
		$('#bmError').hide().text('');
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

		$.ajax({
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

	function bindSuspectAction() {
		$(document).on('click', '#add_to_blacklist', function (event) {
			event.preventDefault();

			if (requestState.suspect) {
				return;
			}

			var config = getConfig();
			var labels = config.suspect || {};
			var orderId = getOrderId();
			var $button = $(this);

			if (!orderId) {
				showNotice('error', 'Order ID not found.');
				return;
			}

			if (!window.confirm(labels.confirmMessage || 'Are you sure?')) {
				return;
			}

			requestState.suspect = true;
			setButtonProcessing($button, labels.processingText || 'Processing...');

			postJson(
				{
					action: 'add_to_blacklist',
					order_id: orderId,
					nonce: config.nonces ? config.nonces.suspect : ''
				},
				function (response) {
					var message = extractMessage(response, '');

					if (!response || !response.success) {
						requestState.suspect = false;
						resetButtonProcessing($button);
						showNotice('error', message || 'Failed to add to suspects list.');
						return;
					}

					showNotice('success', message);
					reloadSoon();
				},
				function (xhr) {
					requestState.suspect = false;
					resetButtonProcessing($button);
					showNotice('error', extractXhrMessage(xhr, 'Failed to add to suspects list.'));
				}
			);
		});
	}

	function bindBlockAction() {
		$(document).on('click', '#block_customer', function (event) {
			event.preventDefault();

			if (requestState.block) {
				return;
			}

			var config = getConfig();
			var block = config.block || {};
			var labels = block.labels || {};
			var reasons = block.reasons || {};
			var descriptions = block.descriptions || {};
			var $reason = $('#bm_reason');
			var $descWrap = $('#bmReasonDescWrap');
			var $descText = $('#bmReasonDesc');

			$('#bmModalTitle').text(labels.modal_title || '');
			$('#bmReasonLabel').text(labels.reason_label || '');
			$('#bmDescLabel').text(labels.description_label || '');
			$('#bmCancel').text(labels.cancel || 'Cancel');
			$('#bmConfirm').text(labels.confirm || 'Confirm block');

			resetModalError();
			$('#bm_description').val('');

			$reason.empty();
			$reason.append(
				$('<option>', {
					value: '',
					text: labels.select_reason || 'Select a reason...',
					disabled: true,
					selected: true
				})
			);

			$.each(reasons, function (value, label) {
				$reason.append($('<option>', { value: value, text: label }));
			});

			function updateReasonDesc() {
				var key = $reason.val();
				var desc = descriptions[key] || '';

				if (desc && key !== 'other') {
					$descText.text(desc);
					$descWrap.show();
				} else {
					$descText.text('');
					$descWrap.hide();
				}
			}

			$reason.off('change.bmBlockReason').on('change.bmBlockReason', updateReasonDesc);
			updateReasonDesc();

			$('#bmCancel').off('click.bmBlock').on('click.bmBlock', function () {
				if (requestState.block) {
					return;
				}
				closeModal();
			});

			$('#bmModalBackdrop').off('click.bmBlock').on('click.bmBlock', function () {
				if (requestState.block) {
					return;
				}
				closeModal();
			});

			$('#bmConfirm').off('click.bmBlock').on('click.bmBlock', function () {
				if (requestState.block) {
					return;
				}

				var orderId = getOrderId();
				var reason = $reason.val();
				var description = $('#bm_description').val().trim();
				var $confirm = $(this);
				var $mainButton = $('#block_customer');

				if (!orderId) {
					showNotice('error', 'Order ID not found.');
					closeModal();
					return;
				}

				if (!reason) {
					$('#bmError').text(labels.required_reason || 'Please select a reason.').show();
					return;
				}

				if (reason === 'other' && !description) {
					$('#bmError').text(labels.required_desc || 'Please enter a description.').show();
					return;
				}

				resetModalError();

				requestState.block = true;
				setButtonProcessing($confirm, labels.processingText || 'Processing...');
				disableActionButtons('#block_customer');

				postJson(
					{
						action: 'block_customer',
						order_id: orderId,
						nonce: getConfig().nonces ? getConfig().nonces.block : '',
						reason_code: reason,
						description: description
					},
					function (response) {
						var message = extractMessage(response, '');

						closeModal();

						if (!response || !response.success) {
							requestState.block = false;
							resetButtonProcessing($confirm);
							enableActionButtons('#block_customer');
							showNotice('error', message || 'Failed to block customer.');
							return;
						}

						showNotice('success', message);
						reloadSoon();
					},
					function (xhr) {
						closeModal();
						requestState.block = false;
						resetButtonProcessing($confirm);
						enableActionButtons('#block_customer');
						showNotice('error', extractXhrMessage(xhr, 'Failed to block customer.'));
					}
				);
			});

			openModal();
		});
	}

	function bindRemoveAction() {
		$(document).on('click', '#remove_from_blacklist', function (event) {
			event.preventDefault();

			if (requestState.remove) {
				return;
			}

			var config = getConfig();
			var remove = config.remove || {};
			var labels = remove.labels || {};
			var reasons = remove.reasons || {};
			var descriptions = remove.descriptions || {};
			var $reason = $('#bm_reason');
			var $descWrap = $('#bmReasonDescWrap');
			var $descText = $('#bmReasonDesc');

			$('#bmModalTitle').text(labels.modal_title || '');
			$('#bmReasonLabel').text(labels.reason_label || '');
			$('#bmDescLabel').text(labels.description_label || '');
			$('#bmCancel').text(labels.cancel || 'Cancel');
			$('#bmConfirm').text(labels.confirm || 'Confirm remove');

			resetModalError();
			$('#bm_description').val('');

			$reason.empty();
			$reason.append(
				$('<option>', {
					value: '',
					text: labels.select_reason || 'Select a reason...',
					disabled: true,
					selected: true
				})
			);

			$.each(reasons, function (value, label) {
				$reason.append($('<option>', { value: value, text: label }));
			});

			function updateReasonDesc() {
				var key = $reason.val();
				var desc = descriptions[key] || '';

				if (desc && key !== 'rvk_other') {
					$descText.text(desc);
					$descWrap.show();
				} else {
					$descText.text('');
					$descWrap.hide();
				}
			}

			$reason.off('change.bmRemoveReason').on('change.bmRemoveReason', updateReasonDesc);
			updateReasonDesc();

			$('#bmCancel').off('click.bmRemove').on('click.bmRemove', function () {
				if (requestState.remove) {
					return;
				}
				closeModal();
			});

			$('#bmModalBackdrop').off('click.bmRemove').on('click.bmRemove', function () {
				if (requestState.remove) {
					return;
				}
				closeModal();
			});

			$('#bmConfirm').off('click.bmRemove').on('click.bmRemove', function () {
				if (requestState.remove) {
					return;
				}

				var orderId = getOrderId();
				var reason = $reason.val();
				var note = $('#bm_description').val().trim();
				var $confirm = $(this);

				if (!orderId) {
					showNotice('error', 'Order ID not found.');
					closeModal();
					return;
				}

				if (!reason) {
					$('#bmError').text(labels.required_reason || 'Please select a reason.').show();
					return;
				}

				if (reason === 'rvk_other' && !note) {
					$('#bmError').text(labels.required_desc || 'Please enter a note.').show();
					return;
				}

				resetModalError();

				requestState.remove = true;
				setButtonProcessing($confirm, labels.processingText || 'Processing...');
				disableActionButtons('#remove_from_blacklist');

				postJson(
					{
						action: 'remove_from_blacklist',
						order_id: orderId,
						nonce: getConfig().nonces ? getConfig().nonces.remove : '',
						revoke_reason: reason,
						revoke_note: note
					},
					function (response) {
						var message = extractMessage(response, '');

						closeModal();

						if (!response || !response.success) {
							requestState.remove = false;
							resetButtonProcessing($confirm);
							enableActionButtons('#remove_from_blacklist');
							showNotice('error', message || 'Failed to remove from blacklist.');
							return;
						}

						showNotice('success', message);
						reloadSoon();
					},
					function (xhr) {
						closeModal();
						requestState.remove = false;
						resetButtonProcessing($confirm);
						enableActionButtons('#remove_from_blacklist');
						showNotice('error', extractXhrMessage(xhr, 'Failed to remove from blacklist.'));
					}
				);
			});

			openModal();
		});
	}

	bindSuspectAction();
	bindBlockAction();
	bindRemoveAction();
});