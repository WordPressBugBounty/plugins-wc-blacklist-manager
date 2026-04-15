jQuery(function ($) {
  var resendCooldown = parseInt(wc_blacklist_manager_verification_data.resendCooldown, 10) || 180;
  var resendTimer = null;
  var resendButtonEnabled = false;
  var emailVerified = false;

  function disablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', true).addClass('disabled');
  }

  function enablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', false).removeClass('disabled');
  }

  function getBillingData() {
    return {
      billing_email: $('input[name="billing_email"]').val() || '',
      billing_first_name: $('input[name="billing_first_name"]').val() || '',
      billing_last_name: $('input[name="billing_last_name"]').val() || '',
      billing_address_1: $('input[name="billing_address_1"]').val() || '',
      billing_address_2: $('input[name="billing_address_2"]').val() || '',
      billing_city: $('input[name="billing_city"]').val() || '',
      billing_state: $('select[name="billing_state"]').val() || $('input[name="billing_state"]').val() || '',
      billing_postcode: $('input[name="billing_postcode"]').val() || '',
      billing_country: $('select[name="billing_country"]').val() || '',
      billing_phone: $('input[name="billing_phone"]').val() || '',
      billing_dial_code: $('input[name="billing_dial_code"]').val() || ''
    };
  }

  function startCountdown(seconds) {
    clearInterval(resendTimer);

    var timeLeft = typeof seconds === 'number' ? seconds : resendCooldown;
    resendButtonEnabled = false;

    $('#resend_timer').show();
    $('#resend_button').hide();

    resendTimer = setInterval(function () {
      if (timeLeft <= 0) {
        clearInterval(resendTimer);
        $('#resend_timer').hide();
        $('#resend_button').show();
        resendButtonEnabled = true;
        return;
      }

      $('#resend_timer').text(
        wc_blacklist_manager_verification_data.resend_in_label +
        ' ' + timeLeft + ' ' +
        wc_blacklist_manager_verification_data.seconds_label
      );

      timeLeft--;
    }, 1000);
  }

  function removeVerificationUI() {
    $('.yobm-email-verification-error').closest('.woocommerce-error, .woocommerce-NoticeGroup, li, div').remove();
    $('.yobm-verify-form').remove();
    $('#verification_message').remove();
  }

  function ensureVerificationUI() {
    if (emailVerified) {
      enablePlaceOrderButton();
      return;
    }

    var $error = $('.yobm-email-verification-error');

    if (!$error.length) {
      return;
    }

    disablePlaceOrderButton();

    if ($error.find('.yobm-verify-form').length) {
      $error.find('.yobm-verify-form').show();
      return;
    }

    var html =
      '<div class="yobm-verify-form">' +
        '<input type="text" id="verification_code" name="verification_code" ' +
          'placeholder="' + wc_blacklist_manager_verification_data.enter_code_placeholder + '" ' +
          'style="max-width:120px;margin-top:10px;" /> ' +
        '<button type="button" id="resend_button" class="button" style="display:none;">' +
          wc_blacklist_manager_verification_data.resend_button_label +
        '</button> ' +
        '<button type="button" id="submit_verification_code" class="button">' +
          wc_blacklist_manager_verification_data.verify_button_label +
        '</button>' +
        '<div id="resend_timer" style="margin-top:8px;"></div>' +
      '</div>' +
      '<div id="verification_message" style="display:none;margin-top:8px;"></div>';

    $error.append(html);
    startCountdown();
  }

  function showVerificationMessage(message, isSuccess) {
    var $message = $('#verification_message');
    $message.text(message).show();

    if (isSuccess) {
      $message.removeClass('yobm-error').addClass('yobm-message');
    } else {
      $message.removeClass('yobm-message').addClass('yobm-error');
    }
  }

  function submitCheckoutAfterVerification() {
    var $form = $('form.checkout');

    enablePlaceOrderButton();

    if (typeof $form.trigger === 'function') {
      $form.trigger('submit');
      return;
    }

    $('#place_order').trigger('click');
  }

  $(document.body).on('click', '#submit_verification_code', function () {
    var code = $.trim($('#verification_code').val());

    if (!code) {
      alert(wc_blacklist_manager_verification_data.enter_code_alert);
      return;
    }

    var billingData = getBillingData();

    var data = $.extend({}, billingData, {
      action: 'verify_email_code',
      code: code,
      security: wc_blacklist_manager_verification_data.nonce
    });

    $.post(wc_blacklist_manager_verification_data.ajax_url, data, function (resp) {
      if (!resp || !resp.data) {
        showVerificationMessage(wc_blacklist_manager_verification_data.code_resend_failed_message, false);
        return;
      }

      showVerificationMessage(resp.data.message, !!resp.success);

      if (resp.success) {
        emailVerified = true;
        clearInterval(resendTimer);
        removeVerificationUI();

        setTimeout(function () {
          submitCheckoutAfterVerification();
        }, 300);
      } else {
        emailVerified = false;
        disablePlaceOrderButton();
      }
    }).fail(function () {
      showVerificationMessage(wc_blacklist_manager_verification_data.code_resend_failed_message, false);
      emailVerified = false;
      disablePlaceOrderButton();
    });
  });

  $(document.body).on('click', '#resend_button', function () {
    if (!resendButtonEnabled) {
      return;
    }

    var billingData = getBillingData();

    var data = {
      action: 'resend_verification_code',
      billing_email: billingData.billing_email,
      security: wc_blacklist_manager_verification_data.nonce
    };

    $.post(wc_blacklist_manager_verification_data.ajax_url, data, function (resp) {
      if (!resp || !resp.data) {
        showVerificationMessage(wc_blacklist_manager_verification_data.code_resend_failed_message, false);
        return;
      }

      if (resp.success) {
        showVerificationMessage(resp.data.message, true);
        startCountdown();
      } else {
        if (resp.data.remaining) {
          startCountdown(parseInt(resp.data.remaining, 10));
        }
        showVerificationMessage(resp.data.message || wc_blacklist_manager_verification_data.code_resend_failed_message, false);
      }
    }).fail(function () {
      showVerificationMessage(wc_blacklist_manager_verification_data.code_resend_failed_message, false);
    });
  });

  function observeForVerificationError() {
    var observer = new MutationObserver(function () {
      if (emailVerified) {
        return;
      }

      if ($('.yobm-email-verification-error').length) {
        ensureVerificationUI();
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  ensureVerificationUI();
  observeForVerificationError();

  $(document.body).on('checkout_error updated_checkout', function () {
    if (!emailVerified) {
      ensureVerificationUI();
    }
  });

  $(document.body).on('change', 'input[name="billing_email"]', function () {
    emailVerified = false;
  });
});