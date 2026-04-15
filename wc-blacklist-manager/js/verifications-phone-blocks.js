jQuery(function ($) {
  var cfg = window.wc_blacklist_manager_phone_blocks_verification_data || {};
  var namespace = cfg.namespace || 'wc-blacklist-manager-phone-verification';

  var resendTimer = null;
  var resendButtonEnabled = false;
  var verifiedPhone = '';
  var activeNoticePhone = '';
  var codeSentForPhone = '';
  var autoRetryInProgress = false;
  var lastAutoRetryPhone = '';

  function getCheckoutDispatch() {
    if (!window.wp || !wp.data) {
      return null;
    }
    return wp.data.dispatch('wc/store/checkout');
  }

  function setExtensionData(data) {
    var dispatch = getCheckoutDispatch();
    if (!dispatch || typeof dispatch.setExtensionData !== 'function') {
      return;
    }
    dispatch.setExtensionData(namespace, data);
  }

  function getCartStoreSelector() {
    if (!window.wp || !window.wp.data || !window.wc || !window.wc.wcBlocksData) {
      return null;
    }

    var cartStore = window.wc.wcBlocksData.cartStore;
    if (!cartStore) {
      return null;
    }

    return window.wp.data.select(cartStore);
  }

  function getCustomerData() {
    var select = getCartStoreSelector();
    if (!select || typeof select.getCustomerData !== 'function') {
      return null;
    }

    try {
      return select.getCustomerData();
    } catch (e) {
      return null;
    }
  }

  function getBillingPhone() {
    var $input = $('input[name="billing_phone"], input[name="phone"], input[type="tel"]').filter(':visible').first();
    if ($input.length) {
      return ($input.val() || '').trim();
    }

    var customerData = getCustomerData();
    if (customerData && customerData.billingAddress && customerData.billingAddress.phone) {
      return String(customerData.billingAddress.phone).trim();
    }

    return '';
  }

  function getBillingCountry() {
    // First try billing country from Blocks store.
    var customerData = getCustomerData();

    if (customerData && customerData.billingAddress && customerData.billingAddress.country) {
      return String(customerData.billingAddress.country).trim();
    }

    // Fallback to shipping country, since your Blocks flow depends on shipping first.
    if (customerData && customerData.shippingAddress && customerData.shippingAddress.country) {
      return String(customerData.shippingAddress.country).trim();
    }

    // Final DOM fallback.
    var $input = $('select[name="billing_country"], input[name="billing_country"], select[name="shipping_country"], input[name="shipping_country"]').filter(':visible').first();
    if ($input.length) {
      return ($input.val() || '').trim();
    }

    return '';
  }

  function getBillingDialCode() {
    // Your custom hidden field is on shipping side in Blocks.
    var $shippingDial = $('#shipping_dial_code, input[name="shipping_dial_code"]').first();
    if ($shippingDial.length) {
      return ($shippingDial.val() || '').trim();
    }

    // Optional fallback if billing hidden field exists too.
    var $billingDial = $('#billing_dial_code, input[name="billing_dial_code"]').first();
    if ($billingDial.length) {
      return ($billingDial.val() || '').trim();
    }

    return '';
  }

  function getPlaceOrderButton() {
    var selectors = [
      '.wc-block-components-checkout-place-order-button',
      '.wc-block-checkout__actions button[type="submit"]',
      '.wc-block-checkout__actions button',
      'button.wc-block-components-checkout-place-order-button'
    ];

    for (var i = 0; i < selectors.length; i++) {
      var $button = $(selectors[i]).filter(':visible').first();
      if ($button.length) {
        return $button;
      }
    }

    return $();
  }

  function isCheckoutReadyForRetry() {
    if (!window.wp || !window.wp.data || !window.wc || !window.wc.wcBlocksData) {
      return false;
    }

    var checkoutStore = window.wc.wcBlocksData.checkoutStore;
    if (!checkoutStore) {
      return false;
    }

    var select = window.wp.data.select(checkoutStore);
    if (!select) {
      return false;
    }

    try {
      var isIdle = typeof select.isIdle === 'function' ? select.isIdle() : false;
      var isBeforeProcessing = typeof select.isBeforeProcessing === 'function' ? select.isBeforeProcessing() : false;
      var isProcessing = typeof select.isProcessing === 'function' ? select.isProcessing() : false;
      var isAfterProcessing = typeof select.isAfterProcessing === 'function' ? select.isAfterProcessing() : false;

      return isIdle && !isBeforeProcessing && !isProcessing && !isAfterProcessing;
    } catch (e) {
      return false;
    }
  }

  function resetVerifiedState(phone) {
    verifiedPhone = '';
    setExtensionData({
      verified: false,
      phone: phone || ''
    });
  }

  function setVerifiedState(phone) {
    verifiedPhone = phone;
    setExtensionData({
      verified: true,
      phone: phone || ''
    });
  }

  function clearResendTimer() {
    if (resendTimer) {
      clearInterval(resendTimer);
      resendTimer = null;
    }
  }

  function startCountdown(seconds) {
    clearResendTimer();
    resendButtonEnabled = false;

    var timeLeft = typeof seconds === 'number' ? seconds : (parseInt(cfg.resendCooldown, 10) || 60);

    $('#yobm_blocks_phone_resend_timer').show();
    $('#yobm_blocks_phone_resend_button').hide();

    resendTimer = setInterval(function () {
      if (timeLeft <= 0) {
        clearResendTimer();
        $('#yobm_blocks_phone_resend_timer').hide();
        $('#yobm_blocks_phone_resend_button').show();
        resendButtonEnabled = true;
        return;
      }

      $('#yobm_blocks_phone_resend_timer').text(
        cfg.resend_in_label + ' ' + timeLeft + ' ' + cfg.seconds_label
      );

      timeLeft--;
    }, 1000);
  }

  function getVerificationNotice() {
    return $('.wc-block-components-notice-banner.is-error').filter(function () {
      return $(this).text().indexOf(cfg.verify_required_message) !== -1;
    }).first();
  }

  function removeExistingVerificationUi() {
    $('.yobm-blocks-phone-verification-wrap').remove();
  }

  function removeVerificationNoticeUiOnly() {
    removeExistingVerificationUi();
  }

  function showMessage(message, isError) {
    var $message = $('#yobm_blocks_phone_verification_message');
    if (!$message.length) {
      return;
    }

    $message
      .text(message || '')
      .css('color', isError ? '#b32d2e' : '#2271b1')
      .show();
  }

  function injectVerificationUiIntoNotice() {
    var $notice = getVerificationNotice();
    if (!$notice.length) {
      return false;
    }

    var $content = $notice.find('.wc-block-components-notice-banner__content').first();
    if (!$content.length) {
      return false;
    }

    if ($content.find('.yobm-blocks-phone-verification-wrap').length) {
      return true;
    }

    var html =
      '<div class="yobm-blocks-phone-verification-wrap" style="margin-top:10px;">' +
        '<div class="yobm-blocks-phone-verification-fields">' +
          '<input type="text" id="yobm_blocks_phone_verification_code" placeholder="' + cfg.enter_code_placeholder + '" style="max-width:140px;" /> ' +
          '<button type="button" id="yobm_blocks_phone_verify_button" class="button">' + cfg.verify_button_label + '</button> ' +
          '<button type="button" id="yobm_blocks_phone_resend_button" class="button" style="display:none;">' + cfg.resend_button_label + '</button>' +
          '<div id="yobm_blocks_phone_resend_timer" style="margin-top:8px;"></div>' +
        '</div>' +
        '<div id="yobm_blocks_phone_verification_message" style="margin-top:8px; display:none;"></div>' +
      '</div>';

    $content.append(html);

    return true;
  }

  function sendCode(phone, isResend) {
    if (!phone) {
      return;
    }

    var action = isResend ? 'resend_phone_verification_code' : 'send_phone_verification_code_blocks';

    $.post(cfg.ajax_url, {
      action: action,
      billing_phone: getBillingPhone(),
      billing_dial_code: getBillingDialCode(),
      billing_country: getBillingCountry(),
      security: cfg.nonce
    }, function (resp) {
      if (!resp || !resp.data) {
        showMessage(cfg.code_resend_failed_message, true);
        return;
      }

      if (!resp.success) {
        if (resp.data.remaining) {
          startCountdown(parseInt(resp.data.remaining, 10));
        }
        showMessage(resp.data.message || cfg.code_resend_failed_message, true);
        return;
      }

      if (resp.data.required === false) {
        setVerifiedState(phone);
        removeExistingVerificationUi();
        return;
      }

      codeSentForPhone = phone;
      showMessage(isResend ? cfg.code_resent_message : cfg.code_sent_message, false);
      startCountdown();
    }).fail(function () {
      showMessage(cfg.code_resend_failed_message, true);
    });
  }

  function retryPlaceOrderOnce(phone) {
    if (!phone) {
      return;
    }

    if (autoRetryInProgress && lastAutoRetryPhone === phone) {
      return;
    }

    autoRetryInProgress = true;
    lastAutoRetryPhone = phone;

    var attempts = 0;
    var maxAttempts = 30;

    var waitTimer = setInterval(function () {
      attempts++;

      var $button = getPlaceOrderButton();

      if (isCheckoutReadyForRetry() && $button.length && !$button.prop('disabled')) {
        clearInterval(waitTimer);

        setTimeout(function () {
          $button.trigger('click');

          setTimeout(function () {
            autoRetryInProgress = false;
          }, 3000);
        }, 150);

        return;
      }

      if (attempts >= maxAttempts) {
        clearInterval(waitTimer);
        autoRetryInProgress = false;
      }
    }, 250);
  }

  function maybeHandleVerificationNotice() {
    var phone = getBillingPhone();
    var $notice = getVerificationNotice();

    if (!$notice.length) {
      return;
    }

    if (!phone) {
      return;
    }

    if (verifiedPhone && verifiedPhone === phone) {
      return;
    }

    if (!injectVerificationUiIntoNotice()) {
      return;
    }

    if (activeNoticePhone !== phone) {
      activeNoticePhone = phone;
      codeSentForPhone = '';
      resetVerifiedState(phone);
    }

    if (codeSentForPhone !== phone) {
      sendCode(phone, false);
    }
  }

  $(document.body).on('click', '#yobm_blocks_phone_verify_button', function () {
    var code = ($('#yobm_blocks_phone_verification_code').val() || '').trim();

    if (!code) {
      alert(cfg.enter_code_alert);
      return;
    }

    var phone = getBillingPhone();

    $.post(cfg.ajax_url, {
      action: 'verify_phone_code',
      code: code,
      billing_phone: getBillingPhone(),
      billing_dial_code: getBillingDialCode(),
      billing_country: getBillingCountry(),
      billing_first_name: $('input[name="billing_first_name"]').val() || '',
      billing_last_name: $('input[name="billing_last_name"]').val() || '',
      billing_address_1: $('input[name="billing_address_1"]').val() || '',
      billing_address_2: $('input[name="billing_address_2"]').val() || '',
      billing_city: $('input[name="billing_city"]').val() || '',
      billing_state: $('select[name="billing_state"]').val() || $('input[name="billing_state"]').val() || '',
      billing_postcode: $('input[name="billing_postcode"]').val() || '',
      billing_email: $('input[name="billing_email"], input[name="email"]').val() || '',
      security: cfg.nonce
    }, function (resp) {
      if (!resp || !resp.data) {
        showMessage(cfg.code_resend_failed_message, true);
        return;
      }

      if (resp.success) {
        setVerifiedState(phone);
        clearResendTimer();
        showMessage(resp.data.message || cfg.verification_success_message, false);

        setTimeout(function () {
          removeVerificationNoticeUiOnly();
          retryPlaceOrderOnce(phone);
        }, 300);
      } else {
        resetVerifiedState(phone);
        showMessage(resp.data.message || cfg.code_resend_failed_message, true);
      }
    }).fail(function () {
      resetVerifiedState(phone);
      showMessage(cfg.code_resend_failed_message, true);
    });
  });

  $(document.body).on('click', '#yobm_blocks_phone_resend_button', function () {
    if (!resendButtonEnabled) {
      return;
    }

    var phone = getBillingPhone();
    if (!phone) {
      return;
    }

    resetVerifiedState(phone);
    sendCode(phone, true);
  });

  $(document.body).on('change input', 'input[name="billing_phone"], input[name="phone"], input[type="tel"], #billing_dial_code, input[name="billing_dial_code"], select[name="billing_country"]', function () {
    var phone = getBillingPhone();

    if (verifiedPhone && verifiedPhone !== phone) {
      resetVerifiedState(phone);
      lastAutoRetryPhone = '';
    }

    if (activeNoticePhone && activeNoticePhone !== phone) {
      codeSentForPhone = '';
      activeNoticePhone = phone;
      removeExistingVerificationUi();
      clearResendTimer();
    }
  });

  function pollSmsFailure() {
    $.post(cfg.ajax_url, {
      action: 'check_sms_verification_status',
      security: cfg.nonce
    }, function (resp) {
      if (resp.success && resp.data && resp.data.failed) {
        alert(cfg.verification_failed_message);
        location.reload();
      }
    });
  }

  function boot() {
    setExtensionData({
      verified: false,
      phone: ''
    });

    setInterval(function () {
      maybeHandleVerificationNotice();
    }, 500);

    setInterval(function () {
      pollSmsFailure();
    }, 3000);
  }

  boot();
});