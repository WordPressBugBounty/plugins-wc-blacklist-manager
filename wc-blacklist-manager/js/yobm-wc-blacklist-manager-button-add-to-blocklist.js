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
    // i18n labels
    $('#bmModalTitle').text(block_ajax_reasons.labels.modal_title);
    $('#bmReasonLabel').text(block_ajax_reasons.labels.reason_label);
    $('#bmDescLabel').text(block_ajax_reasons.labels.description_label);
    $('#bmCancel').text(block_ajax_reasons.labels.cancel);
    $('#bmConfirm').text(block_ajax_reasons.labels.confirm);

    // Populate reasons once (with a placeholder first)
    const $reason = $('#bm_reason');
    if ($reason.children().length === 0) {
      const placeholderText = (block_ajax_reasons.labels && block_ajax_reasons.labels.select_reason)
        ? block_ajax_reasons.labels.select_reason
        : 'Select a reason...';

      // Placeholder option (disabled + selected so user must pick a real reason)
      $reason.append($('<option>', {
        value: '',
        text: placeholderText,
        disabled: true,
        selected: true
      }));

      $.each(block_ajax_reasons.reasons, function(value, label) {
        $reason.append($('<option>', { value: value, text: label }));
      });
    }

    // Reset state every time modal opens
    $('#bmError').hide().text('');
    $('#bm_description').val('');
    // Ensure the placeholder is selected on open
    $reason.prop('selectedIndex', 0);

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

    // Required: must choose a reason (placeholder has value '')
    if (!reason) {
      $('#bmError').text(block_ajax_reasons.labels.required_reason).show();
      return;
    }
    if (reason === 'other' && !desc) {
      $('#bmError').text(block_ajax_reasons.labels.required_desc).show();
      return;
    }

    // Disable confirm to prevent double submit
    $('#bmConfirm').prop('disabled', true);

    const data = {
      action      : 'block_customer',
      order_id    : orderId,
      nonce       : block_ajax_object.nonce,
      reason_code : reason,
      description : desc
    };

    $.post(block_ajax_object.ajax_url, data, function(response) {
      // Success notice, then reload
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
