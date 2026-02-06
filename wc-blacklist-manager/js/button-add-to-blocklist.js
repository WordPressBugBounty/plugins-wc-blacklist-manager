jQuery(document).ready(function($) {
  function getOrderId() {
    // Legacy editor
    if (window.woocommerce_admin_meta_boxes && woocommerce_admin_meta_boxes.post_id) {
      return woocommerce_admin_meta_boxes.post_id;
    }
    // HPOS: read `id` from URL
    const urlParams = new URLSearchParams(window.location.search);
    const hposId = urlParams.get('id');
    if (hposId) return hposId;

    // Fallback
    return null;
  }

  function openModal() {
    var labels       = block_ajax_reasons.labels || {};
    var reasons      = block_ajax_reasons.reasons || {};
    var descriptions = block_ajax_reasons.descriptions || {};

    // i18n labels
    $('#bmModalTitle').text(labels.modal_title || '');
    $('#bmReasonLabel').text(labels.reason_label || '');
    $('#bmDescLabel').text(labels.description_label || '');
    $('#bmCancel').text(labels.cancel || 'Cancel');
    $('#bmConfirm').text(labels.confirm || 'Confirm');

    // Populate reasons once (with a placeholder first)
    const $reason = $('#bm_reason');
    if ($reason.children().length === 0) {
      const placeholderText = labels.select_reason || 'Select a reason...';

      // Placeholder option (disabled + selected so user must pick a real reason)
      $reason.append($('<option>', {
        value: '',
        text: placeholderText,
        disabled: true,
        selected: true
      }));

      $.each(reasons, function(value, label) {
        $reason.append($('<option>', { value: value, text: label }));
      });
    }

    // Reset state every time modal opens
    $('#bmError').hide().text('');
    $('#bm_description').val('');
    $reason.prop('selectedIndex', 0);

    // Reason description area
    var $descWrap = $('#bmReasonDescWrap');
    var $descText = $('#bmReasonDesc');

    function updateReasonDesc() {
      var key  = $reason.val();
      var desc = descriptions[key] || '';

      if (desc && key !== 'other') {
        $descText.text(desc);
        $descWrap.show();
      } else {
        $descText.text('');
        $descWrap.hide();
      }
    }

    $reason.off('change.bmReasonDesc').on('change.bmReasonDesc', updateReasonDesc);
    updateReasonDesc();

    $('#bmModalBackdrop').show();
    $('#bmModal').show();
  }

  function closeModal() {
    $('#bmModalBackdrop').hide();
    $('#bmModal').hide();
  }

  // Open modal instead of immediate block
  $(document).on('click', '#block_customer', function(e) {
    e.preventDefault();
    openModal();
  });

  // Cancel
  $(document).on('click', '#bmCancel', function() {
    closeModal();
  });

  // Confirm submit
  $(document).on('click', '#bmConfirm', function() {
    const orderId = getOrderId();
    if (!orderId) {
      alert('Order ID not found.');
      return;
    }

    const reason = $('#bm_reason').val();
    const desc   = $('#bm_description').val().trim();

    if (!reason) {
      $('#bmError').text(block_ajax_reasons.labels.required_reason).show();
      return;
    }
    if (reason === 'other' && !desc) {
      $('#bmError').text(block_ajax_reasons.labels.required_desc).show();
      return;
    }

    $('#bmConfirm').prop('disabled', true);

    const data = {
      action      : 'block_customer',
      order_id    : orderId,
      nonce       : block_ajax_object.nonce,
      reason_code : reason,
      description : desc
    };

    $.post(block_ajax_object.ajax_url, data, function(response) {
      const messageHtml = '<div class="notice notice-success is-dismissible"><p>' + response + '</p></div>';
      $('div.wrap').first().prepend(messageHtml);
      closeModal();
      setTimeout(function(){ window.location.reload(); }, 1200);
    }).fail(function(xhr) {
      const err = (xhr && xhr.responseText) ? xhr.responseText : 'Error';
      const messageHtml = '<div class="notice notice-error is-dismissible"><p>' + err + '</p></div>';
      $('div.wrap').first().prepend(messageHtml);
      $('#bmConfirm').prop('disabled', false);
    });
  });

  // Hide when clicking outside
  $('#bmModalBackdrop').on('click', function(){ closeModal(); });
});
