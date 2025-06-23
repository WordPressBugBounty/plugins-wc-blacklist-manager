jQuery(function($){
  var resendCooldown      = wc_blacklist_manager_verification_data.resendCooldown;
  var resendButtonEnabled = false;

  // Disable/enable order button
  function disablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', true).addClass('disabled');
  }
  function enablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', false).removeClass('disabled');
  }

  // Countdown timer
  function startCountdown(){
    $('#resend_timer').show();
    $('#resend_button').hide();
    var timeLeft = resendCooldown;
    var timer   = setInterval(function(){
      if (timeLeft <= 0){
        clearInterval(timer);
        $('#resend_timer').hide();
        $('#resend_button').show();
        resendButtonEnabled = true;
      } else {
        $('#resend_timer').text(
          wc_blacklist_manager_verification_data.resend_in_label
          + ' ' + timeLeft
          + ' ' + wc_blacklist_manager_verification_data.seconds_label
        );
        timeLeft--;
      }
    }, 1000);
  }

  // Main injector & handler
  function handleEmailVerification(){
    var $error = $('.yobm-email-verification-error');
    if (!$error.length) return;

    disablePlaceOrderButton();

    // inject form once
    if (!$error.find('.yobm-verify-form').length){
      $error.append('\
        <div class="yobm-verify-form">\
          <input type="text" id="verification_code" name="verification_code"\
            placeholder="'+ wc_blacklist_manager_verification_data.enter_code_placeholder +'"\
            style="max-width:120px;margin-top:10px;" />\
          <button type="button" id="resend_button" class="button" style="display:none;">\
            '+ wc_blacklist_manager_verification_data.resend_button_label +'\
          </button>\
          <button type="button" id="submit_verification_code" class="button">\
            '+ wc_blacklist_manager_verification_data.verify_button_label +'\
          </button>\
          <div id="resend_timer"></div>\
        </div>\
        <div id="verification_message" style="display:none;"></div>'
      );
      startCountdown();
      attachEventHandlers();
    } else {
      $error.find('.yobm-verify-form').show();
      $('#verification_message').hide();
    }
  }

  // Watch for late-injected notices
  function observeForVerificationError(){
    var obs = new MutationObserver(function(muts, o){
      if ($('.yobm-email-verification-error').length){
        o.disconnect();
        handleEmailVerification();
      }
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }

  // Wire up the buttons
  function attachEventHandlers(){
    $('#submit_verification_code').off('click').on('click', function(){
      var code = $('#verification_code').val().trim();
      if (! code){
        alert(wc_blacklist_manager_verification_data.enter_code_alert);
        return;
      }

      // gather billing + email details
      var data = {
        action:        'verify_email_code',
        code:          code,
        security:      wc_blacklist_manager_verification_data.nonce,
        billing_email: $('input[name="billing_email"]').val() || '',
        // include other billing fields if needed...
        billing_first_name: $('input[name="billing_first_name"]').val() || '',
        billing_last_name:  $('input[name="billing_last_name"]').val() || '',
        billing_address_1:  $('input[name="billing_address_1"]').val() || '',
        billing_address_2:  $('input[name="billing_address_2"]').val() || '',
        billing_city:       $('input[name="billing_city"]').val() || '',
        billing_state:      $('select[name="billing_state"]').val() || '',
        billing_postcode:   $('input[name="billing_postcode"]').val() || '',
        billing_country:    $('select[name="billing_country"]').val() || ''
      };

      $.post(wc_blacklist_manager_verification_data.ajax_url, data, function(resp){
        $('#verification_message').text(resp.data.message).show();
        if (resp.success){
          enablePlaceOrderButton();
          // auto-submit
          setTimeout(function(){ $('#place_order').trigger('click'); }, 1000);
        }
      });
    });

    $('#resend_button').off('click').on('click', function(){
      if (! resendButtonEnabled) return;

      var data = {
        action:         'resend_verification_code',
        billing_email:  $('input[name="billing_email"]').val() || '',
        security:       wc_blacklist_manager_verification_data.nonce
      };

      $.post(wc_blacklist_manager_verification_data.ajax_url, data, function(resp){
        if (resp.success){
          $('#verification_message')
            .text(wc_blacklist_manager_verification_data.code_resent_message)
            .show();
          startCountdown();
        } else {
          $('#verification_message')
            .text(wc_blacklist_manager_verification_data.code_resend_failed_message)
            .show();
        }
      });
    });
  }

  // Poll for server-side “failed” status if still needed
  setInterval(function(){
    $.post(wc_blacklist_manager_verification_data.ajax_url, {
      action:   'check_email_verification_status',
      security: wc_blacklist_manager_verification_data.nonce
    }, function(resp){
      if (resp.success && resp.data && resp.data.failed){
        alert(wc_blacklist_manager_verification_data.verification_failed_message);
        location.reload();
      }
    });
  }, 3000);

  // initialize on load
  observeForVerificationError();
  handleEmailVerification();

  // catch WooCommerce AJAX errors
  $(document.body).on('checkout_error', handleEmailVerification);
});
