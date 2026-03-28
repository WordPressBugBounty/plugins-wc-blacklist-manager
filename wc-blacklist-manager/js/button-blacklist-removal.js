jQuery(function ($) {
    $('#remove_from_blacklist').on('click', function (event) {
        event.preventDefault();

        var $modal    = $('#bmModal');
        var $backdrop = $('#bmModalBackdrop');

        // If the shared modal is missing, just bail.
        if (!$modal.length || !$backdrop.length) {
            console.warn('[YOGB] bmModal not found for remove_from_blacklist');
            return;
        }

        var labels      = remove_ajax_object.labels || {};
        var reasons     = remove_ajax_object.reasons || {};
        var descriptions = remove_ajax_object.descriptions || {};

        // Title
        $('#bmModalTitle').text(labels.modal_title || '');

        // Fields + error box
        var $reasonField = $('#bm_reason').closest('.bm-field');
        var $descField   = $('#bm_description').closest('.bm-field');
        var $errorBox    = $('#bmError');

        $reasonField.show();
        $descField.show();

        $('#bmReasonLabel').text(labels.reason_label || '');
        $('#bmDescLabel').text(labels.description_label || '');

        $errorBox.hide().text('').css('color', '#b32d2e');

        // Populate reason select
        var $reasonSelect = $('#bm_reason');
        $reasonSelect.empty();

        var placeholder = labels.select_reason || '';
        if (placeholder) {
            $reasonSelect.append(
                $('<option/>', {
                    value: '',
                    text: placeholder
                })
            );
        }

        $.each(reasons, function (value, label) {
            $reasonSelect.append(
                $('<option/>', {
                    value: value,
                    text: label
                })
            );
        });

        // Reason description elements
        var $descWrap = $('#bmReasonDescWrap');
        var $descText = $('#bmReasonDesc');

        function updateReasonDescription() {
            var key  = $reasonSelect.val();
            var desc = descriptions[key] || '';

            if (desc && key !== 'rvk_other') {
                $descText.text(desc);
                $descWrap.show();
            } else {
                $descText.text('');
                $descWrap.hide();
            }
        }

        $reasonSelect.off('change.bmReasonDesc').on('change.bmReasonDesc', updateReasonDescription);
        updateReasonDescription();

        // Clear note
        $('#bm_description').val('');

        // Buttons text
        $('#bmCancel').text(labels.cancel || 'Cancel');
        $('#bmConfirm').text(labels.confirm || 'Confirm');

        // Unbind previous remove handlers then bind new ones (namespace .bmRemove)
        $('#bmCancel, #bmModalBackdrop').off('click.bmRemove').on('click.bmRemove', function () {
            closeModal();
        });

        $('#bmConfirm').off('click.bmRemove').on('click.bmRemove', function () {
            var reason = $reasonSelect.val();
            var desc   = $('#bm_description').val().trim();

            if (!reason) {
                $errorBox.text(labels.required_reason || 'Please select a reason.').show();
                return;
            }

            if (reason === 'rvk_other' && !desc) {
                $errorBox.text(labels.required_desc || 'Please enter a note.').show();
                return;
            }

            $errorBox.hide().text('');

            doRemove(reason, desc);
        });

        // Show modal
        $backdrop.show();
        $modal.show();

        // --- Helpers -------------------------------------------------

        function closeModal() {
            $modal.hide();
            $backdrop.hide();
        }

        function doRemove(reason, desc) {
            closeModal();

            $('#remove_from_blacklist').prop('disabled', true);

            var data = {
                action:        'remove_from_blacklist',
                order_id:      woocommerce_admin_meta_boxes.post_id,
                nonce:         remove_ajax_object.nonce,
                revoke_reason: reason,
                revoke_note:   desc
            };

            $.post(remove_ajax_object.ajax_url, data, function (response) {
                var messageHtml = '<div class="notice notice-success is-dismissible"><p>' + response + '</p></div>';
                $('div.wrap').first().prepend(messageHtml);
                $('div.notice').delay(3000).slideUp(300, function () {
                    window.location.reload();
                });
            });
        }
    });
});
