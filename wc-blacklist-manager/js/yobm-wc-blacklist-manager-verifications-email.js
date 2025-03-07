jQuery(document).ready(function($) {
	var resendCooldown = wc_blacklist_manager_verification_data.resendCooldown;
	var resendButtonEnabled = false;

	function disablePlaceOrderButton() {
		$('form.checkout #place_order').prop('disabled', true).addClass('disabled');
	}

	function enablePlaceOrderButton() {
		$('form.checkout #place_order').prop('disabled', false).removeClass('disabled');
	}

	function startCountdown() {
		$('#resend_timer').show();
		$('#resend_button').hide();
		var timeLeft = resendCooldown;
		var countdownInterval = setInterval(function() {
			if (timeLeft <= 0) {
				clearInterval(countdownInterval);
				$('#resend_timer').hide();
				$('#resend_button').show();
				resendButtonEnabled = true;
			} else {
				$('#resend_timer').text(wc_blacklist_manager_verification_data.resend_in_label + ' ' + timeLeft + ' ' + wc_blacklist_manager_verification_data.seconds_label);
				timeLeft--;
			}
		}, 1000);
	}

	function checkForVerificationNotice() {    
		var $errorContainer = $('.yobm-email-verification-error');
		if ($errorContainer.length > 0) {
			disablePlaceOrderButton();
	
			// Check if the verification form already exists.
			var $verifyForm = $errorContainer.find('.yobm-verify-form');
			if ($verifyForm.length === 0) {
				$errorContainer.append(`
					<div class="yobm-verify-form">
						<input type="text" id="verification_code" name="verification_code" placeholder="${wc_blacklist_manager_verification_data.enter_code_placeholder}" style="max-width: 120px; margin-top: 10px;" />
						<button type="button" id="resend_button" class="button" style="display:none;">${wc_blacklist_manager_verification_data.resend_button_label}</button>
						<button type="button" id="submit_verification_code" class="button">${wc_blacklist_manager_verification_data.verify_button_label}</button>
						<div id="resend_timer">${wc_blacklist_manager_verification_data.resend_in_label} 60 seconds</div>
					</div>
					<div id="verification_message" style="display:none;"></div>
				`);
				startCountdown();
				attachEventHandlers();
			} else {
				// Ensure the existing form is visible and the message area is hidden.
				$verifyForm.show();
				$('#verification_message').hide();
			}
		} else {    
			// If the error container isn't available yet, observe changes in the DOM.
			observeForVerificationError();
		}
	}
	
	function observeForVerificationError() {
		const targetNode = document.body; // Monitor the entire body
		const config = { childList: true, subtree: true };
	
		const callback = function (mutationsList, observer) {
			for (const mutation of mutationsList) {
				if ($('.yobm-email-verification-error').length > 0) {
					observer.disconnect(); // Stop observing
					checkForVerificationNotice(); // Retry the verification logic
					break;
				}
			}
		};
	
		const observer = new MutationObserver(callback);
		observer.observe(targetNode, config);
	}
	
	function attachEventHandlers() {
		// Use off() to avoid attaching multiple events
		$('#submit_verification_code').off('click').on('click', function () {
			const verificationCode = $('#verification_code').val().trim();
	
			if (verificationCode === '') {
				alert(wc_blacklist_manager_verification_data.enter_code_alert);
				return;
			}

			var billingDialCode = $('#billing_dial_code').val() || '';
	
			const billingDetails = {
				billing_first_name: $('input[name="billing_first_name"]').val() || '',
				billing_last_name: $('input[name="billing_last_name"]').val() || '',
				billing_address_1: $('input[name="billing_address_1"]').val() || '',
				billing_address_2: $('input[name="billing_address_2"]').val() || '',
				billing_city: $('input[name="billing_city"]').val() || '',
				billing_state: $('select[name="billing_state"]').val() || '',
				billing_postcode: $('input[name="billing_postcode"]').val() || '',
				billing_country: $('select[name="billing_country"]').val() || '',
				billing_email: $('input[name="billing_email"]').val() || '',
				billing_phone: $('input[name="billing_phone"]').val() || '',
				billing_dial_code: billingDialCode
			};
		
			$.ajax({
				url: wc_blacklist_manager_verification_data.ajax_url,
				type: 'POST',
				data: {
					action: 'verify_email_code',
					code: verificationCode,
					security: wc_blacklist_manager_verification_data.nonce,
					...billingDetails
				},
				success: function (response) {
					if (response.success) {
						$('#verification_message').text(response.data.message).show();
	
						if ($('.woocommerce-error').length > 0) {
							$('<div class="woocommerce-message alert alert_success">' + response.data.message + '</div>')
								.insertAfter('.woocommerce-error');
							$('.woocommerce-error').hide();
							$('html, body').animate({
								scrollTop: $('.woocommerce-message').offset().top - 150
							}, 500);
						} else {
							$('.yobm-verify-form').hide();
						}
						enablePlaceOrderButton();
						setTimeout(function () {
							$('#place_order').trigger('click');
						}, 1000);
					} else {
						$('#verification_message').text(response.data.message).show();
					}
				}
			});
		});
	
		$('#resend_button').off('click').on('click', function () {
			if (!resendButtonEnabled) {
				return;
			}
	
			const billingEmail = $('input[name="billing_email"]').val();
		
			$.ajax({
				url: wc_blacklist_manager_verification_data.ajax_url,
				type: 'POST',
				data: {
					action: 'resend_verification_code',
					billing_email: billingEmail,
					security: wc_blacklist_manager_verification_data.nonce
				},
				success: function (response) {
					if (response.success) {
						$('#verification_message').text(wc_blacklist_manager_verification_data.code_resent_message).show();
						startCountdown();
					} else {
						$('#verification_message').text(wc_blacklist_manager_verification_data.code_resend_failed_message).show();
					}
				}
			});
		});
	}	
	
	// Attach the event listener
	$(document).ready(function () {
		$(document.body).on('checkout_error', function () {
			checkForVerificationNotice();
		});
	});
});
