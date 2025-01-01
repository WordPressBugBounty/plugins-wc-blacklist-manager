// Navagation tabs
jQuery(document).ready(function($) {
    // Function to open a specific tab
    function openTab(tabId) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('a[data-tab="' + tabId + '"]').addClass('nav-tab-active');
        $('.tab-pane').hide();
        $('#' + tabId).show();
        localStorage.setItem('currentTab', tabId);
    }

    // Handle tab clicks
    $('.nav-tab').click(function(e) {
        e.preventDefault();
        openTab($(this).data('tab'));
    });

    // Check for 'current_tab' parameter in URL or localStorage
    var urlParams = new URLSearchParams(window.location.search);
    var currentTab = urlParams.get('current_tab') || localStorage.getItem('currentTab');
    if (currentTab) {
        openTab(currentTab);
    } else {
        openTab('blacklisted'); // Default to the first tab
    }
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
