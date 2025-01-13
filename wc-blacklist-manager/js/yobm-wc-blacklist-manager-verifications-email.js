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
        if ($('.yobm-email-verification-error').length > 0) {
    
            disablePlaceOrderButton();
    
            $('.yobm-email-verification-error').append(`
                <div class="yobm-verify-form">
                    <input type="text" id="verification_code" name="verification_code" placeholder="${wc_blacklist_manager_verification_data.enter_code_placeholder}" style="max-width: 120px; margin-top: 10px;" />
                    <button type="button" id="resend_button" class="button" style="display:none;">${wc_blacklist_manager_verification_data.resend_button_label}</button>
                    <button type="button" id="submit_verification_code" class="button">${wc_blacklist_manager_verification_data.verify_button_label}</button>
                    <div id="resend_timer">${wc_blacklist_manager_verification_data.resend_in_label} 60 seconds</div>
                </div>
                <div id="verification_message" style="display:none;"></div>
            `);
    
            startCountdown();
    
            // Attach event handlers
            attachEventHandlers();
        } else {    
            // Observe changes in the DOM
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
        $('#submit_verification_code').on('click', function () {
            const verificationCode = $('#verification_code').val().trim();
    
            if (verificationCode === '') {
                alert(wc_blacklist_manager_verification_data.enter_code_alert);
                return;
            }
    
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
                billing_phone: $('input[name="billing_phone"]').val() || ''
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
                            $('.woocommerce-error').hide();
                            $('<div class="woocommerce-message alert alert_success">' + response.data.message + '</div>').insertBefore('.woocommerce-form-coupon-toggle');
        
                            $('html, body').animate({
                                scrollTop: $('.woocommerce-message').offset().top - 150
                            }, 500);
                        } else {
                            $('.yobm-verify-form').hide();
                        }
                        enablePlaceOrderButton();
                    } else {
                        $('#verification_message').text(response.data.message).show();
                    }
                }
            });
        });
    
        $('#resend_button').on('click', function () {
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
