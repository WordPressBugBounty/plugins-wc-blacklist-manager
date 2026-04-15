jQuery(function ($) {
  var cfg = window.wc_blacklist_manager_phone_verification_data || {};
  var resendCooldown = parseInt(cfg.resendCooldown, 10) || 60;
  var resendTimer = null;
  var resendButtonEnabled = false;
  var phoneVerified = false;

  function disablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', true).addClass('disabled');
  }

  function enablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', false).removeClass('disabled');
  }

  function getBillingData() {
    return {
      billing_first_name: $('input[name="billing_first_name"]').val() || '',
      billing_last_name: $('input[name="billing_last_name"]').val() || '',
      billing_address_1: $('input[name="billing_address_1"]').val() || '',
      billing_address_2: $('input[name="billing_address_2"]').val() || '',
      billing_city: $('input[name="billing_city"]').val() || '',
      billing_state: $('select[name="billing_state"]').val() || $('input[name="billing_state"]').val() || '',
      billing_postcode: $('input[name="billing_postcode"]').val() || '',
      billing_country: $('select[name="billing_country"]').val() || '',
      billing_email: $('input[name="billing_email"]').val() || '',
      billing_phone: $('input[name="billing_phone"]').val() || '',
      billing_dial_code: $('#billing_dial_code').val() || $('input[name="billing_dial_code"]').val() || ''
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
        cfg.resend_in_label + ' ' + timeLeft + ' ' + cfg.seconds_label
      );

      timeLeft--;
    }, 1000);
  }

  function removeVerificationUI() {
    $('.yobm-phone-verification-error').closest('.woocommerce-error, .woocommerce-NoticeGroup, li, div').remove();
    $('.yobm-phone-verify-form').remove();
    $('#phone_verification_message').remove();
  }

  function ensureVerificationUI() {
    if (phoneVerified) {
      enablePlaceOrderButton();
      return;
    }

    var $error = $('.yobm-phone-verification-error');
    if (!$error.length) {
      return;
    }

    disablePlaceOrderButton();

    if ($error.find('.yobm-phone-verify-form').length) {
      $error.find('.yobm-phone-verify-form').show();
      return;
    }

    var html =
      '<div class="yobm-phone-verify-form">' +
        '<input type="text" id="phone_verification_code" name="phone_verification_code" ' +
          'placeholder="' + cfg.enter_code_placeholder + '" style="max-width:120px;margin-top:10px;" /> ' +
        '<button type="button" id="resend_button" class="button" style="display:none;">' +
          cfg.resend_button_label +
        '</button> ' +
        '<button type="button" id="submit_phone_verification_code" class="button">' +
          cfg.verify_button_label +
        '</button>' +
        '<div id="resend_timer" style="margin-top:8px;"></div>' +
      '</div>' +
      '<div id="phone_verification_message" style="display:none;margin-top:8px;"></div>';

    $error.append(html);
    startCountdown();
  }

  function showVerificationMessage(message, isSuccess) {
    var $message = $('#phone_verification_message');
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

    if ($form.length) {
      $form.trigger('submit');
      return;
    }

    $('#place_order').trigger('click');
  }

  $(document.body).on('click', '#submit_phone_verification_code', function () {
    var code = $.trim($('#phone_verification_code').val());

    if (!code) {
      alert(cfg.enter_code_alert);
      return;
    }

    var data = $.extend({}, getBillingData(), {
      action: 'verify_phone_code',
      code: code,
      security: cfg.nonce
    });

    $.post(cfg.ajax_url, data, function (resp) {
      if (!resp || !resp.data) {
        showVerificationMessage(cfg.code_resend_failed_message, false);
        return;
      }

      showVerificationMessage(resp.data.message, !!resp.success);

      if (resp.success) {
        phoneVerified = true;
        clearInterval(resendTimer);
        removeVerificationUI();

        setTimeout(function () {
          submitCheckoutAfterVerification();
        }, 300);
      } else {
        phoneVerified = false;
        disablePlaceOrderButton();
      }
    }).fail(function () {
      showVerificationMessage(cfg.code_resend_failed_message, false);
      phoneVerified = false;
      disablePlaceOrderButton();
    });
  });

  $(document.body).on('click', '#resend_button', function () {
    if (!resendButtonEnabled) {
      return;
    }

    var data = $.extend({}, getBillingData(), {
      action: 'resend_phone_verification_code',
      security: cfg.nonce
    });

    $.post(cfg.ajax_url, data, function (resp) {
      if (!resp || !resp.data) {
        showVerificationMessage(cfg.code_resend_failed_message, false);
        return;
      }

      if (resp.success) {
        showVerificationMessage(resp.data.message, true);
        startCountdown();
      } else {
        if (resp.data.remaining) {
          startCountdown(parseInt(resp.data.remaining, 10));
        }
        showVerificationMessage(resp.data.message || cfg.code_resend_failed_message, false);
      }
    }).fail(function () {
      showVerificationMessage(cfg.code_resend_failed_message, false);
    });
  });

  function observeForPhoneVerificationError() {
    var observer = new MutationObserver(function () {
      if (phoneVerified) {
        return;
      }

      if ($('.yobm-phone-verification-error').length) {
        ensureVerificationUI();
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  ensureVerificationUI();
  observeForPhoneVerificationError();

  $(document.body).on('checkout_error updated_checkout', function () {
    if (!phoneVerified) {
      ensureVerificationUI();
    }
  });

  $(document.body).on('change input', 'input[name="billing_phone"], #billing_dial_code, input[name="billing_dial_code"], select[name="billing_country"]', function () {
    phoneVerified = false;
  });

  setInterval(function () {
    $.post(cfg.ajax_url, {
      action: 'check_sms_verification_status',
      security: cfg.nonce
    }, function (resp) {
      if (resp.success && resp.data && resp.data.failed) {
        alert(cfg.verification_failed_message);
        location.reload();
      }
    });
  }, 3000);
});