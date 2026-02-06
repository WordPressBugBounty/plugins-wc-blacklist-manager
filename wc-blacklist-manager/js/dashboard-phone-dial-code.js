document.addEventListener('DOMContentLoaded', function() {
    var phoneNumberHolder = document.querySelector("#phone_number_holder");
    var phoneDialCodeHolder = document.querySelector("#phone_dial_code_holder");
    var newPhoneNumber = document.querySelector("#new_phone_number");
    var iti; // Billing intl-tel-input instance

    // Helper function to update the newPhoneNumber field
    function updateNewPhoneNumber() {
        if (newPhoneNumber && phoneDialCodeHolder && phoneNumberHolder) {
            // Remove any non-digit characters from the phone number
            var phoneNumberClean = phoneNumberHolder.value.replace(/[^0-9]/g, '');
            // Remove leading zero(s)
            phoneNumberClean = phoneNumberClean.replace(/^0+/, '');
            // Combine the dial code and cleaned phone number
            newPhoneNumber.value = phoneDialCodeHolder.value + phoneNumberClean;
        }
    }

    if (phoneNumberHolder && typeof intlTelInput !== 'undefined') {
        iti = window.intlTelInput(phoneNumberHolder, {
            initialCountry: yobmDashboardForm.initial_country,
            preferredCountries: [],
            excludeCountries: yobmDashboardForm.excluded_countries,
            onlyCountries: yobmDashboardForm.specific_countries
        });

        // Immediately set the billing dial code.
        if (phoneDialCodeHolder) {
            var countryData = iti.getSelectedCountryData();
            phoneDialCodeHolder.value = '+' + countryData.dialCode;
        }

        // Update billing dial code on country change.
        phoneNumberHolder.addEventListener('countrychange', function() {
            if (phoneDialCodeHolder) {
                var countryData = iti.getSelectedCountryData();
                phoneDialCodeHolder.value = '+' + countryData.dialCode;
            }
        });

        // On blur, format the phone and update the newPhoneNumber field.
        phoneNumberHolder.addEventListener('blur', function() {
            var entered = phoneNumberHolder.value.trim();
            if (entered.charAt(0) === '+') {
                // If the number starts with '+', set the number and format it.
                iti.setNumber(entered);
                setTimeout(function() {
                    var countryData = iti.getSelectedCountryData();
                    if (phoneDialCodeHolder) {
                        phoneDialCodeHolder.value = '+' + countryData.dialCode;
                    }
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
    }
});
