jQuery(function($){
  var resendCooldown      = wc_blacklist_manager_verification_data.resendCooldown;
  var resendButtonEnabled = false;

  // 1) Utility to disable/enable the place order button
  function disablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', true).addClass('disabled');
  }
  function enablePlaceOrderButton() {
    $('form.checkout #place_order').prop('disabled', false).removeClass('disabled');
  }

  // 2) Countdown for resend
  function startCountdown(){
    $('#resend_timer').show();
    $('#resend_button').hide();
    var timeLeft = resendCooldown;
    var timer = setInterval(function(){
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

  // 3) Inject the verification form and wire up buttons
  function handlePhoneVerification(){
    var $error = $('.yobm-phone-verification-error');
    if ($error.length === 0) {
      // no error on page: nothing to do
      return;
    }

    disablePlaceOrderButton();

    // Append form if not already present
    if ($error.find('.yobm-verify-form').length === 0){
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
    }

    // Wire up “Verify” click
    $('#submit_verification_code').off('click').on('click', function(){
      var code = $('#verification_code').val().trim();
      if (! code){
        alert(wc_blacklist_manager_verification_data.enter_code_alert);
        return;
      }
      // gather billing data...
      var billingData = {
        billing_first_name: $('input[name="billing_first_name"]').val()||'',
        billing_last_name:  $('input[name="billing_last_name"]').val()||'',
        billing_address_1: $('input[name="billing_address_1"]').val()||'',
        billing_address_2: $('input[name="billing_address_2"]').val()||'',
        billing_city:     $('input[name="billing_city"]').val()||'',
        billing_state:    $('select[name="billing_state"]').val()||'',
        billing_postcode: $('input[name="billing_postcode"]').val()||'',
        billing_country:  $('select[name="billing_country"]').val()||'',
        billing_email:    $('input[name="billing_email"]').val()||'',
        billing_phone:    $('input[name="billing_phone"]').val()||'',
        billing_dial_code: $('#billing_dial_code').val()||''
      };
      $.post(
        wc_blacklist_manager_verification_data.ajax_url,
        $.extend({
          action:   'verify_phone_code',
          code:     code,
          security: wc_blacklist_manager_verification_data.nonce
        }, billingData),
        function(resp){
          if (resp.success){
            $('#verification_message').text(resp.data.message).show();
            enablePlaceOrderButton();
            // auto-submit after a second
            setTimeout(function(){ $('#place_order').trigger('click'); }, 1000);
          } else {
            $('#verification_message').text(resp.data.message).show();
          }
        }
      );
    });

    // Wire up “Resend” click
    $('#resend_button').off('click').on('click', function(){
      if (! resendButtonEnabled) return;
      var phoneData = {
        billing_phone:   $('input[name="billing_phone"]').val()||'',
        billing_country: $('select[name="billing_country"]').val()||''
      };
      $.post(
        wc_blacklist_manager_verification_data.ajax_url,
        {
          action:   'resend_phone_verification_code',
          security: wc_blacklist_manager_verification_data.nonce
        },
        function(resp){
          if (resp.success){
            $('#verification_message')
              .text(wc_blacklist_manager_verification_data.code_resent_message)
              .show();
            startCountdown();
          } else {
            var msg = resp.data && resp.data.message
              ? resp.data.message
              : wc_blacklist_manager_verification_data.code_resend_failed_message;
            $('#verification_message').text(msg).show();
          }
        }
      );
    });
  }

  // 4) Observe for errors injected later
  function observeForPhoneVerificationError(){
    var obs = new MutationObserver(function(muts, o){
      if ($('.yobm-phone-verification-error').length){
        o.disconnect();
        handlePhoneVerification();
      }
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }

  // 5) Always run on page load
  observeForPhoneVerificationError();
  handlePhoneVerification();

  // 6) Also catch AJAX-driven failures
  $(document.body).on('checkout_error', function(){
    handlePhoneVerification();
  });

  // 7) Poll for “failed” status if you still need it
  setInterval(function(){
    $.post(
      wc_blacklist_manager_verification_data.ajax_url,
      {
        action:   'check_sms_verification_status',
        security: wc_blacklist_manager_verification_data.nonce
      },
      function(resp){
        if (resp.success && resp.data && resp.data.failed){
          alert(wc_blacklist_manager_verification_data.verification_failed_message);
          location.reload();
        }
      }
    );
  }, 3000);
});
