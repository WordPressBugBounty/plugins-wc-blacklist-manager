// Dashboard list navigation.
(function($, window, document) {
    'use strict';

    function resolveTab(requested, remembered, available) {
        var fallback = available.indexOf('blacklisted') !== -1 ? 'blacklisted' : (available[0] || '');

        if (requested) {
            return available.indexOf(requested) !== -1 ? requested : fallback;
        }

        return available.indexOf(remembered) !== -1 ? remembered : fallback;
    }

    function readRememberedTab() {
        try {
            return window.localStorage.getItem('currentTab') || '';
        } catch (error) {
            return '';
        }
    }

    function rememberTab(tabId) {
        try {
            window.localStorage.setItem('currentTab', tabId);
        } catch (error) {
            // Storage can be unavailable in privacy-restricted browser contexts.
        }
    }

    if (window.yobmDashboardTestHooks) {
        window.yobmDashboardTestHooks.resolveTab = resolveTab;
    }

    $(document).ready(function() {
        var $tabs = $('.yobm-dashboard-tabs .yobm-admin-tab');
        var available = $tabs.map(function() {
            return String($(this).data('tab'));
        }).get();

        function openTab(tabId, shouldRemember) {
            var resolved = resolveTab(tabId, '', available);

            if (!resolved) {
                return;
            }

            $tabs.removeClass('is-active').removeAttr('aria-current');
            $tabs.filter('[data-tab="' + resolved + '"]').addClass('is-active').attr('aria-current', 'page');
            $('.tab-content .tab-pane').prop('hidden', true).removeClass('active');
            $('#' + resolved).prop('hidden', false).addClass('active');

            if (shouldRemember) {
                rememberTab(resolved);
            }
        }

        $tabs.on('click', function(event) {
            event.preventDefault();
            openTab(String($(this).data('tab')), true);
        });

        $('.yobm-manage-link').on('click', function(event) {
            event.preventDefault();
            openTab('blacklisted', true);
            document.getElementById('blacklisted').scrollIntoView({ block: 'start' });
        });

        var urlParams = new URLSearchParams(window.location.search);
        var requested = urlParams.get('current_tab') || urlParams.get('tab') || window.location.hash.replace(/^#/, '');
        var remembered = readRememberedTab();
        var currentTab = resolveTab(requested, remembered, available);

        if (!requested && remembered && remembered !== currentTab) {
            rememberTab(currentTab);
        }

        openTab(currentTab, false);
        // Toggle popup on click.
        $(document).on('click', '.bm-reason-link', function(event) {
            event.preventDefault();
            var $cell = $(this).closest('.bm-reason-cell');
            $('.bm-reason-cell').not($cell).removeClass('active');
            $cell.toggleClass('active');
        });

        // Close popup when clicking outside.
        $(document).on('click', function(event) {
            if (!$(event.target).closest('.bm-reason-cell').length) {
                $('.bm-reason-cell').removeClass('active');
            }
        });
    });
}(jQuery, window, document));


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
