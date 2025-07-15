// Add to Block Customer button in the Edit Order page
jQuery(document).ready(function($) {
    $('#block_customer').on('click', function(event) {
        event.preventDefault();

        var userConfirmed = confirm(block_ajax_object.confirm_message);

        if (userConfirmed) {        
            $('#block_customer').remove();
            var data = {
                'action': 'block_customer',
                'order_id': woocommerce_admin_meta_boxes.post_id,
                'nonce': block_ajax_object.nonce
            };

            $.post(block_ajax_object.ajax_url, data, function(response) {
                var messageHtml = '<div class="notice notice-success is-dismissible"><p>' + response + '</p></div>';
                $('div.wrap').first().prepend(messageHtml);
                $('div.notice').delay(3000).slideUp(300, function() {
                    window.location.reload();
                });
            });
        }
    });
});