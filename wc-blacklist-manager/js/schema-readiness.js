/* Copy only: no database request or repair is initiated by this script. */
document.addEventListener('DOMContentLoaded', function () {
    var panel = document.querySelector('.yobm-schema-panel');
    if (!panel) { return; }
    var button = panel.querySelector('.yobm-schema-copy');
    var message = panel.querySelector('#yobm-schema-packet');
    var feedback = panel.querySelector('.yobm-schema-copy-feedback');
    var details = panel.querySelector('.yobm-schema-packet-details');
    if (!button || !message || !feedback || !details) { return; }
    button.addEventListener('click', function () {
        details.open = true;
        message.focus();
        message.select();
        feedback.textContent = button.dataset.fallback;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(message.value).then(function () {
                feedback.textContent = button.dataset.copied;
            }).catch(function () { /* The selected text remains a usable fallback. */ });
        }
    });
});
