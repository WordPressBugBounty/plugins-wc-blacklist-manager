// Add to Blacklist button in the Edit Order page
jQuery(document).ready(function($) {
    $('#add_to_blacklist').on('click', function(event) {
        event.preventDefault();

        var userConfirmed = confirm(blacklist_ajax_object.confirm_message);

        if (userConfirmed) {
            var data = {
                'action': 'add_to_blacklist',
                'order_id': woocommerce_admin_meta_boxes.post_id,
                'nonce': blacklist_ajax_object.nonce
            };

            $.post(blacklist_ajax_object.ajax_url, data, function(response) {
                var messageHtml = '<div class="notice notice-success is-dismissible"><p>' + response + '</p></div>';
                $('div.wrap').first().prepend(messageHtml);
                $('div.notice').delay(3000).slideUp(300, function() {
                    window.location.reload();
                });
                $('#add_to_blacklist_container').hide();
            });
        }
    });
});
