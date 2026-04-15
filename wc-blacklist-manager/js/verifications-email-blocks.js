jQuery(function ($) {
  var cfg = window.wc_blacklist_manager_blocks_verification_data || {};
  var namespace = cfg.namespace || 'wc-blacklist-manager-email-verification';

  var resendTimer = null;
  var resendButtonEnabled = false;
  var verifiedEmail = '';
  var activeNoticeEmail = '';
  var codeSentForEmail = '';
  var autoRetryInProgress = false;
  var lastAutoRetryEmail = '';

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

  function getBillingEmail() {
    var selectors = [
      'input[name="email"]',
      'input[name="billing_email"]',
      'input[type="email"]'
    ];

    for (var i = 0; i < selectors.length; i++) {
      var $input = $(selectors[i]).filter(':visible').first();
      if ($input.length) {
        return ($input.val() || '').trim();
      }
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

  function resetVerifiedState(email) {
    verifiedEmail = '';
    setExtensionData({
      verified: false,
      email: email || ''
    });
  }

  function setVerifiedState(email) {
    verifiedEmail = email;
    setExtensionData({
      verified: true,
      email: email || ''
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

    var timeLeft = typeof seconds === 'number' ? seconds : (parseInt(cfg.resendCooldown, 10) || 180);

    $('#yobm_blocks_resend_timer').show();
    $('#yobm_blocks_resend_button').hide();

    resendTimer = setInterval(function () {
      if (timeLeft <= 0) {
        clearResendTimer();
        $('#yobm_blocks_resend_timer').hide();
        $('#yobm_blocks_resend_button').show();
        resendButtonEnabled = true;
        return;
      }

      $('#yobm_blocks_resend_timer').text(
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
    $('.yobm-blocks-verification-wrap').remove();
  }

  function removeVerificationNoticeUiOnly() {
    removeExistingVerificationUi();
  }

  function showMessage(message, isError) {
    var $message = $('#yobm_blocks_verification_message');
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

    if ($content.find('.yobm-blocks-verification-wrap').length) {
      return true;
    }

    var html =
      '<div class="yobm-blocks-verification-wrap" style="margin-top:10px;">' +
        '<div class="yobm-blocks-verification-fields">' +
          '<input type="text" id="yobm_blocks_verification_code" placeholder="' + cfg.enter_code_placeholder + '" style="max-width:140px;" /> ' +
          '<button type="button" id="yobm_blocks_verify_button" class="button">' + cfg.verify_button_label + '</button> ' +
          '<button type="button" id="yobm_blocks_resend_button" class="button" style="display:none;">' + cfg.resend_button_label + '</button>' +
          '<div id="yobm_blocks_resend_timer" style="margin-top:8px;"></div>' +
        '</div>' +
        '<div id="yobm_blocks_verification_message" style="margin-top:8px; display:none;"></div>' +
      '</div>';

    $content.append(html);

    return true;
  }

  function sendCode(email, isResend) {
    if (!email) {
      return;
    }

    var action = isResend ? 'resend_verification_code' : 'send_verification_code_blocks';

    $.post(cfg.ajax_url, {
      action: action,
      billing_email: email,
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
        setVerifiedState(email);
        removeExistingVerificationUi();
        removeVerificationNotice();
        return;
      }

      codeSentForEmail = email;
      showMessage(isResend ? cfg.code_resent_message : cfg.code_sent_message, false);
      startCountdown();
    }).fail(function () {
      showMessage(cfg.code_resend_failed_message, true);
    });
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

  function retryPlaceOrderOnce(email) {
    if (!email) {
      return;
    }

    if (autoRetryInProgress && lastAutoRetryEmail === email) {
      return;
    }

    autoRetryInProgress = true;
    lastAutoRetryEmail = email;

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
    var email = getBillingEmail();
    var $notice = getVerificationNotice();

    if (!$notice.length) {
      return;
    }

    if (!email) {
      return;
    }

    if (verifiedEmail && verifiedEmail === email) {
      return;
    }

    if (!injectVerificationUiIntoNotice()) {
      return;
    }

    if (activeNoticeEmail !== email) {
      activeNoticeEmail = email;
      codeSentForEmail = '';
      resetVerifiedState(email);
    }

    if (codeSentForEmail !== email) {
      sendCode(email, false);
    }
  }

  $(document.body).on('click', '#yobm_blocks_verify_button', function () {
    var code = ($('#yobm_blocks_verification_code').val() || '').trim();
    var email = getBillingEmail();

    if (!code) {
      alert(cfg.enter_code_alert);
      return;
    }

    $.post(cfg.ajax_url, {
      action: 'verify_email_code',
      code: code,
      billing_email: email,
      security: cfg.nonce
    }, function (resp) {
      if (!resp || !resp.data) {
        showMessage(cfg.code_resend_failed_message, true);
        return;
      }

      if (resp.success) {
        setVerifiedState(email);
        clearResendTimer();
        showMessage(resp.data.message || cfg.verification_success_message, false);

        setTimeout(function () {
          removeVerificationNoticeUiOnly();
          retryPlaceOrderOnce(email);
        }, 300);
      } else {
        resetVerifiedState(email);
        showMessage(resp.data.message || cfg.code_resend_failed_message, true);
      }
    }).fail(function () {
      resetVerifiedState(email);
      showMessage(cfg.code_resend_failed_message, true);
    });
  });

  $(document.body).on('click', '#yobm_blocks_resend_button', function () {
    if (!resendButtonEnabled) {
      return;
    }

    var email = getBillingEmail();
    if (!email) {
      return;
    }

    resetVerifiedState(email);
    sendCode(email, true);
  });

  $(document.body).on('change input', 'input[name="email"], input[name="billing_email"], input[type="email"]', function () {
    var email = getBillingEmail();

    if (verifiedEmail && verifiedEmail !== email) {
      resetVerifiedState(email);
      lastAutoRetryEmail = '';
    }

    if (activeNoticeEmail && activeNoticeEmail !== email) {
      codeSentForEmail = '';
      activeNoticeEmail = email;
      removeExistingVerificationUi();
      clearResendTimer();
    }
  });

  function boot() {
    setExtensionData({
      verified: false,
      email: ''
    });

    setInterval(function () {
      maybeHandleVerificationNotice();
    }, 500);
  }

  boot();
});