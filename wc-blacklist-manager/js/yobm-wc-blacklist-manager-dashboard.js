// Navagation tabs
jQuery(document).ready(function($) {
    function openTab(tabId) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('a[data-tab="' + tabId + '"]').addClass('nav-tab-active');
        $('.tab-pane').hide();
        $('#' + tabId).show();
        localStorage.setItem('currentTab', tabId);
    }

    $('.nav-tab').click(function(e) {
        e.preventDefault();
        openTab($(this).data('tab'));
    });

    var urlParams = new URLSearchParams(window.location.search);
    var currentTab = urlParams.get('current_tab');
    
    // Only fallback to localStorage if URL doesn't define it
    if (!currentTab) {
        currentTab = localStorage.getItem('currentTab') || 'blacklisted';
    }

    openTab(currentTab);

    // Toggle popup on click
    $(document).on('click', '.bm-reason-link', function(e){
        e.preventDefault();
        var $cell = $(this).closest('.bm-reason-cell');
        // Close others
        $('.bm-reason-cell').not($cell).removeClass('active');
        // Toggle current
        $cell.toggleClass('active');
    });

    // Close popup when clicking outside
    $(document).on('click', function(e){
        if (!$(e.target).closest('.bm-reason-cell').length) {
        $('.bm-reason-cell').removeClass('active');
        }
    });
});


// WC Blacklist Manager
function removeMessages() {
    var messageElement = document.getElementById('message');
    if (messageElement) {
        setTimeout(function() { 
            messageElement.style.display = 'none'; 
            messageElement.remove();

            // Clear messages from the session
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('clear_messages=1');
        }, 5000); // 5000 milliseconds = 5 seconds
    }
}

document.addEventListener('DOMContentLoaded', removeMessages);
