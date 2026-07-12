document.addEventListener('DOMContentLoaded', function() {
    var phoneNumberHolder = document.querySelector("#phone_number_holder");
    var phoneDialCodeHolder = document.querySelector("#phone_dial_code_holder");
    var newPhoneNumber = document.querySelector("#new_phone_number");
    var form = phoneNumberHolder ? phoneNumberHolder.closest('form') : null;
    var iti; // Billing intl-tel-input instance
    var initAttempts = 0;
    var maxInitAttempts = 40;

    function normalizeDialCode(value) {
        var digits = String(value || '').replace(/\D+/g, '');

        return digits ? '+' + digits : '';
    }

    function getDialCodeFromDom() {
        if (!phoneNumberHolder || !phoneNumberHolder.closest) {
            return '';
        }

        var container = phoneNumberHolder.closest('.iti');
        var selectedDialCode = container && container.querySelector ? container.querySelector('.iti__selected-dial-code') : null;

        return selectedDialCode ? normalizeDialCode(selectedDialCode.textContent || selectedDialCode.innerText || '') : '';
    }

    function updateDialCodeFromSelectedCountry() {
        if (!phoneDialCodeHolder) {
            return '';
        }

        var dialCode = '';

        if (iti && typeof iti.getSelectedCountryData === 'function') {
            var countryData = iti.getSelectedCountryData();
            if (countryData && countryData.dialCode) {
                dialCode = normalizeDialCode(countryData.dialCode);
            }
        }

        if (!dialCode) {
            dialCode = getDialCodeFromDom();
        }

        if (dialCode) {
            phoneDialCodeHolder.value = dialCode;
        }

        return phoneDialCodeHolder.value;
    }

    // Helper function to update the newPhoneNumber field
    function updateNewPhoneNumber() {
        if (newPhoneNumber && phoneDialCodeHolder && phoneNumberHolder) {
            var entered = phoneNumberHolder.value.trim();
            var phoneNumberClean = entered.replace(/[^0-9]/g, '');
            var dialCode = updateDialCodeFromSelectedCountry();
            var dialDigits = dialCode.replace(/\D+/g, '');

            if (!phoneNumberClean) {
                newPhoneNumber.value = '';
                return;
            }

            if (entered.charAt(0) === '+') {
                newPhoneNumber.value = '+' + phoneNumberClean.replace(/^0+/, '');
                return;
            }

            // Remove leading zero(s)
            phoneNumberClean = phoneNumberClean.replace(/^0+/, '');

            if (dialDigits) {
                // Combine the dial code and cleaned phone number
                newPhoneNumber.value = '+' + dialDigits + phoneNumberClean;
                return;
            }

            newPhoneNumber.value = phoneNumberClean;
        }
    }

    function initializePhoneInput() {
        if (!phoneNumberHolder || iti || typeof window.intlTelInput !== 'function') {
            return false;
        }

        var config = window.yobmDashboardForm || {};

        iti = window.intlTelInput(phoneNumberHolder, {
            initialCountry: config.initial_country || 'us',
            preferredCountries: [],
            excludeCountries: config.excluded_countries || [],
            onlyCountries: config.specific_countries || []
        });

        // Immediately set the billing dial code.
        updateDialCodeFromSelectedCountry();
        updateNewPhoneNumber();

        // Update billing dial code on country change.
        phoneNumberHolder.addEventListener('countrychange', function() {
            updateDialCodeFromSelectedCountry();
            updateNewPhoneNumber();
        });

        // On blur, format the phone and update the newPhoneNumber field.
        phoneNumberHolder.addEventListener('blur', function() {
            var entered = phoneNumberHolder.value.trim();
            if (entered.charAt(0) === '+') {
                // If the number starts with '+', set the number and format it.
                iti.setNumber(entered);
                updateDialCodeFromSelectedCountry();
                updateNewPhoneNumber();
                setTimeout(function() {
                    var countryData = iti.getSelectedCountryData();
                    updateDialCodeFromSelectedCountry();
                    if (typeof intlTelInputUtils !== 'undefined') {
                        var nationalNumber = intlTelInputUtils.formatNumber(
                            entered,
                            countryData.iso2,
                            intlTelInputUtils.numberFormat.NATIONAL
                        );
                        if (nationalNumber) {
                            phoneNumberHolder.value = nationalNumber;
                        }
                    }
                    updateNewPhoneNumber();
                }, 100);
            } else {
                updateNewPhoneNumber();
            }
        });

        return true;
    }

    if (phoneNumberHolder) {
        phoneNumberHolder.addEventListener('input', function() {
            updateNewPhoneNumber();
        });
    }

    if (phoneNumberHolder && !initializePhoneInput()) {
        var initTimer = window.setInterval(function() {
            initAttempts++;

            if (initializePhoneInput() || initAttempts >= maxInitAttempts) {
                window.clearInterval(initTimer);
            }
        }, 50);
    }

    if (form) {
        form.addEventListener('submit', function() {
            if (iti && phoneNumberHolder && phoneNumberHolder.value.trim().charAt(0) === '+') {
                iti.setNumber(phoneNumberHolder.value.trim());
                updateDialCodeFromSelectedCountry();
            }

            updateNewPhoneNumber();
        });
    }
});
