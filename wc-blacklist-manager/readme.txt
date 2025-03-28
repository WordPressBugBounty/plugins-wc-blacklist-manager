=== WooCommerce Blacklist Manager - Anti-Fraud / Checkout Verification ===
Contributors: yoohw, baonguyen0310
Tags: blacklist customers, block ip, fraud prevention, woocommerce anti fraud, Prevent fake orders
Requires at least: 6.3
Tested up to: 6.7
WC tested up to: 9.6
Requires PHP: 5.6
Stable tag: 1.4.10
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Easily helps store owners to avoid unwanted customers.

== Description ==

The **WooCommerce Blacklist Manager - Anti-Fraud / Checkout Verification** plugin is an essential tool for WooCommerce store owners. It provides the ability to blacklist specific phone numbers, email addresses, IP addresses, and email domains, effectively blocking users from placing orders, canceling them, or even creating an account.

Additionally, this plugin includes a **Checkout Verification** feature, ensuring that only legitimate customers can complete their purchases. Store owners can require verification for phone numbers, email addresses, and customer names before allowing checkout, adding multiple layers of fraud protection. Customers may be prompted to verify their phone via SMS, confirm their email through a one-time code, or validate their identity using their registered name, significantly reducing fraudulent orders.

With an easy-to-use interface integrated into the WordPress dashboard, managing your blacklist and verification settings is both straightforward and efficient. This plugin ensures your store remains secure by preventing unwanted or problematic orders while enhancing trust and safety for legitimate customers.

[Premium version](https://yoohw.com/product/woocommerce-blacklist-manager-premium/) | [Documentation](https://yoohw.com/docs/category/woocommerce-blacklist-manager/) | [Support](https://yoohw.com/support/)  | [Demo](https://sandbox.yoohw.com/create-sandbox-user/)

== Features ==

* **Blacklist Management**: Suspects, Blocklist for Phone number, Email address, IP address and Domain.
* **Friendly Controller**: Easily add the phone number, email address, ip address from the Edit Order page; multi ip addresses/domains addition into blocking list.
* **Multi Notifications**: Email, alert and error notices for both admin and users are customizable.
* **Prevent Ordering**: Option to prevent the customer place an order if their email/phone/ip/domain is on the Blocklist.
* **Prevent Registration**: Option to prevent registration if the email/ip/domain is on the Blocklist.
* **Timed Cancellation**: Option to cancel the order if the email/phone is on the Blocklist in the delay of time.
* **Email Verification**: Customers are required to verify their email by entering a code sent to them during checkout to complete their order.
* **Phone Verification**: Customers must verify their phone number by entering an SMS code received during checkout before proceeding with their order.
* **User Blocking**: When the order has been placed by a user and has been added to the Blocklist, then the user is also set as Blocked. Optional in the Settings.

== Premium Features ==

Building on the robust features of the free version, the premium version offers advanced functionalities to safeguard your business against fraud and unauthorized transactions.

**Fully Automation**

Fully Automated-Protecting against fraud and unauthorized transactions, hands-free to focus on growing your E-commerce website!

* **Set risk score thresholds**: Manually adjust each rule's risk value!
* **Choose the right score**: Select a score for every option to let your own rules work.
* **Check phone and email**: Check the phone number and email address that were used in multiple orders but with a different IP or customer address.
* **Check order value & attempts**: Make sure the order value isn't abnormal and the customer has placed too many orders within the time period.
* **Suspects the IP address**: Detect the customer that uses a VPN or proxy server and the IP's country mismatches with the billing country.
* **Detect IP coordinates**: Action if the IP coordinates radius does not match the address coordinates radius.
* **Card country & AVS checks**: High-level checking of the payment card country and billing country is not the same, also AVS.
* **Set High risk card country**: Manually establishing the list of nations in order to safeguard payments made via your website.
* **Blacklist based on order statuses**: Set the statues to automatically add the customer to the suspect and blocked list.

[Explore the Automation features](https://yoohw.com/product/woocommerce-blacklist-manager-premium/#automation)

**Advanced Blocking**

* **Customer Name Blocking**: Adds the first and last name of the customer to the blocklist.
* **Address Blacklisting**: Block orders from specific addresses listed in your blocklist.
* **Prevent VPN & Proxy Registration**: Prevent visitors from registering if they use Proxy server or VPN.
* **IP Access Prevention**: Stop users from accessing your website from IP countries that you have selected.
* **Browser Blocking**: Restrict accessing your website for users of browsers.
* **Prevent Disposable Emails**: Block orders and registration if the customer uses a disposable email address.
* **Prevent Disposable Phones**: Block orders and automate adding to the blocklist  if the customer is using a disposable phone number.
* **Optional Payment Methods**: Disable the payment methods for the customers are in the Suspects list.

**Real-time Automatic Validation**

Our plugin ensures a seamless and error-free checkout experience with Real-time Automatic Validation. This feature automatically validates customer-provided details, including names, email addresses, and phone numbers, as they are entered. By detecting and alerting users of issues such as incomplete or invalid data, it helps to reduce errors and ensure compliance with your data integrity rules.

With intelligent validation logic, the plugin checks for:

* **Name Validation**: Detects invalid characters, excessive spaces, repeated characters, and ensures adherence to your custom length rules.
* **Email Validation**: Confirms that email addresses are correctly formatted and free from common typos or invalid domains.
* **Phone Number Validation**: Verifies phone numbers against predefined formats and country codes, with optional integration for SMS-based verification.

This real-time validation not only improves the user experience by providing instant feedback but also ensures that your customer database remains accurate and clean.

**Prevent Orders from Bots**

This solution secures your WooCommerce checkout with a combination of Google reCAPTCHA v3, v2, hCaptcha and an invisible honeypot trap to block bot-generated orders. Key features include:

* **Advanced bot detection**: Uses AI-driven behavioral analysis and hidden honeypot fields to stop spam.
* **Seamless user experience**: Invisible security layers ensure a frictionless checkout for legitimate users.
* **API protection**: Safeguards the checkout process from bots attempting to place orders via API access.
* **Efficient and lightweight**: Protects your store without impacting performance.

**Blacklist Connection for Multiple Stores**

Blacklist Connection allows you to sync and consolidate blacklists from multiple WooCommerce stores, creating a centralized network of blacklisted emails, phone numbers, IP addresses, customer addresses, and email domains. This unified blacklist ensures a comprehensive defense against fraud across all your online stores, improving security and saving you time.

Ideal for multi-store owners and agencies managing numerous client sites—save time and boost security.

[See more about Blacklist Connection feature](https://yoohw.com/docs/woocommerce-blacklist-manager/settings/connection/)

**Universal Checkout Compatibility**

Our plugin is compatible with all types of checkout pages, including WooCommerce Classic, [Block-based Checkout](https://woocommerce.com/checkout-blocks/), and third-party checkout plugins. It also features address autocompletion on the checkout page to ensure accuracy and clarity through seamless Google Maps API integration.

**Permission Settings**

The Permission Settings feature of our plugin allows you to set both default and custom user roles to control access to the Dashboard, Notifications, and Settings. Tailor the access levels to ensure that only the appropriate users can manage and view the plugin's critical features.

**Enhanced Protection**

Our premier solution for combating fraud and unauthorized transactions: we've integrated up with the finest third-party services to deliver the highest level of protection for your business. Each service we chose excels in identifying and preventing fraudulent activities. Moreover, these services offer free plans designed to support small and medium-sized businesses, enabling you to focus on growth while safeguarding your transactions.

Service integrations: [Cloudflare](https://www.cloudflare.com/), [Google reCaptcha v3/v2](https://www.google.com/recaptcha/about/), [hCaptcha](https://www.hcaptcha.com/), [IPinfo](https://ipinfo.io/), [ip-api](https://ip-api.com/), [Usercheck](https://www.usercheck.com/), [ZeroBounce](https://www.zerobounce.net?ref=owqwzgy) [NumCheckr](https://numcheckr.com/), [Google Maps Platform](https://mapsplatform.google.com/), [SMS Credits](https://yoohw.com/product/sms-credits/).

Plugin integrations: [WooComerce Advanced Account](https://wordpress.org/plugins/wc-advanced-accounts/), [WooCommerce Stripe Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/), [Payment Plugins for Stripe WooCommerce](https://wordpress.org/plugins/woo-stripe-payment/). 

**Import / Export**

Easily manage your blacklist data with our Import/Export feature. Quickly upload entries via CSV or export your blacklist data for backup, review, or migration purposes, ensuring seamless data management.

**Premium Support**

[Access our Premium Support site](https://yoohw.com/support/)

**Dedicated Assistance**: Access to our premium support team for any issues or questions you may have.
**Priority Response**: Receive faster response times and personalized support to ensure your plugin operates smoothly.

[Explore the Premium version here](https://yoohw.com/product/woocommerce-blacklist-manager-premium/)

With these premium features and dedicated support, the WooCommerce Blacklist Manager Premium plugin provides unparalleled security and efficiency, giving you peace of mind and allowing you to focus on growing your business.

== Installation ==

1. **Upload Plugin**: Download the plugin and upload it to your WordPress site under `wp-content/plugins`.
2. **Activate**: Navigate to the WordPress admin area, go to the 'Plugins' section, and activate the 'WooCommerce Blacklist Manager' plugin.
3. **Database Setup**: Upon activation, the plugin automatically creates the necessary database tables.

== Frequently Asked Questions ==

**Q: Do I need to configure any settings after installation?**  
A: Yes, additional configuration is needed for the plugin to work as your expectation. Go to menu Blacklist Manager > Settings.

**Q: Why is there a Suspects list for?**
A: You don't want to lose customers carelessly, do you? That's why there should be the Suspects list, for you to marked the customers and suspect them a while before you decide to block them or not.

**Q: Can this plugin prevent the customer to checkout through a separate payment page such as Paypal, Stripe etc..?**
A: The logic of the WooCommerce Blacklist Manager plugin is that to prevent the blocked customer can checkout through your website, the payment gateways have nothing to do with that. So, the answer is absolutely YES.

**Q: Is there a limit to the number of entries I can add to the blacklist?**  
A: There is no set limit within the plugin, but practical limitations depend on your server and database performance.

== Screenshots ==

1. Easily control your blacklist with a friendly dashboard.
2. Blocklist tab at dashboard.
3. IP address entries with manual addition form.
4. Smart addition address form.
5. Blocking email domain.
6. Review orders list with risk score column.
7. Check IP, verification, actions at the order page.
8. Review the IP address details directly by clicking on customer IP.
9. Risk score metabox to display the details.
10. Quick block/unblock and track down blocked users.
11. Block the user directly from the user page.
12. Unblock the user.
13. Verification settings.
14. Phone number format settings.
15. Name format & validation settings.
16. Email & Phone verifications merging.
17. Email notification, alert notices are customizable.
18. Flexible settings allow you to decide what is on your site.
19. Automation settings to automate protecting your business.
20. Set risk score and risk score thresholds.
21. The finest third-party services in the market are integrated.
22. Payment gateways integrated, safeguarding your transactions.
23. Set user roles are able to manage the Blacklist plugin.
24. Easily import your existing data or export.
25. Set up a host site for your blacklist connection.
26. Easy to connect with the host site.
27. Risk score is in the new order email to admin and shop manager.
28. Alert email notification with a custom template.

== Changelog ==

= 1.4.10 (Mar 24, 2025) =
* Update: Adding the phone number with dial code into the verification list.
* Fix: Cannot verify the phone number with the country dial code.
* Fix: Translation loading was triggered too early.
* Improve: Remove update the sms key option after upgrader complete.

= 1.4.9 (Mar 7, 2025) =
* Update: Optimize blocking function performance.
* Update: Added strict logic to prevent the phone number.
* Fix: Error phone format of the auto-cancel the blocked order.
* Fix: Added missing action of removing suspected phone after verifying during checkout.
* Fix: Remove the suspected instead of blocked email after verifying during checkout.
* Fix: Cannot generate a new SMS key for the new site register.
* Improve: Optimize verification form displaying.
* Improve: Limit resend code even when refreshing checkout page.
* Improve: Optimized and cleaned up.

= 1.4.8 (Feb 10, 2025) =
* New: Upgrade the user verification with our integrated WooCommerce Advanced Accounts.
* New: Auto-place the order after verification successfully.
* New: Alert the customer to review their phone number if SMS verification failed.
* Improve: Customize JavaScript files to run only on the exact pages.

= 1.4.7 (Jan 13, 2025) =
* New: Email notifications to admin if sending the phone verification code has failed.
* Fix: The verification form does not display with some themes and third-party checkout plugins.
* Improve: Make sure the verification form displays when triggering the verification code.

= 1.4.6 (Jan 1, 2025) =
* Fix: Blocked name displays in add new order page.
* Improve: Not prevent a blocked domain if an empty email field is allowed on the checkout page.

HAPPY NEW YEAR!!!

= 1.4.5 (Dec 13, 2024) =
* Fix: Prevent registration for suspected IP addresses.
* Improve: The notices will only display for administrators.

= 1.4.4 (Dec 1, 2024) =
* Fix: Blocking email checkbox option does not display correctly.
* Improve: Optimized the email and phone verification functions.
* Improve: Some improvements.

= 1.4.3 (Nov 12, 2024) =
* New: Optional for receiving emails during blocked user attempts to place orders or register an account.
* Fix: New logged-in users do not receive the phone verification code.

= 1.4.2 (Nov 4, 2024) =
* Fix: Verification form does not display in some themes.
* Fix: Duplicated success notice after verification.
* Fix: Block button displaying when the phone or email field is empty at order page.
* Fix: Missing phone number country code when resend verification code.
* Fix: Minor typo errors.
* Improve: Customize, reorder, and add class for verification form.
* Improve: Added verification email content translation.
* Improve: Security updated.

= 1.4.1 (Oct 25, 2024) =
* Improve: Optimize the scripts.
* Improve: Language file updated.

= 1.4.0 (Oct 16, 2024) =
* New: Verifications feature is now available.
* New: Require the new customer to verify email address when checkout.
* New: Require the new customer to verify phone number when checkout.
* Improve: Minor improvement.

= 1.3.14 (Sep 19, 2024) =
* Improve: Minor improvement.
* Fix: Removed the missing file.

= 1.3.13 (Sep 16, 2024) =
* New: Supported the website uses Cloudflare to block user IPs.
* Improve: Some minor improvement.

= 1.3.12 (Sep 4, 2024) =
* Fix: The rule to display the Suspect & Blocklist buttons in Order page.
* Improve: Minor changes for better performance.

= 1.3.11 (Jul 31, 2024) =
* Fix: The Add to Blocklist button logic has been updated.
* Fix: Avoid to block administrator users.
* Improve: Auto cancel action logic has been updated.

= 1.3.10 (Jul 23, 2024) =
* Fix: Blank entries are removed at blocklist.
* Fix: Missing messages at dashboard.
* Improve: Display only one notice a time at dashboard.
* Improve: Updated CSS at dashboard.
* Improve: Minor improvement.

= 1.3.9 (Jul 18, 2024) =
* Fix: Removed duplicate messages are on the dashboard.
* Improve: The search function has improved.

= 1.3.8 (Jul 9, 2024) =
* New: Email sent to admin when blocked customer attempts detection.
* Improve: Updated text content at Settings.
* Improve: Core improvement.

= 1.3.7 (Jul 3, 2024) =
* Fix: Bug at Settings page.
* Improve: Added Settings notice for the new installs.

= 1.3.6 (Jun 28, 2024) =
* Improve: Changed the display of blocked user row at Users page.
* Improve: Core improvement.

= 1.3.5 =
* Improve: Language file updated.
* Improve: Core improvement.

= 1.3.4 =
* New: Added selection of status at Addition manual form.
* Improve: Minor bug fixed.

= 1.3.3 =
* Improve: Language file updated.
* Improve: Minor improvement.

= 1.3.2 =
* New: Added blocked user notice customizable in Notifications.
* Improve: Changed the blocked user notice from browser pop-up to error notice.
* Improve: Minor bugs fixed.

= 1.3.1 =
* New: Notices when the customer is in suspect list or blocklist at edit order page.
* Fix: Fixed domain addition form did not open when IP address option disabled.
* Improve: Updated the logics of Add to suspect list and blocklist at edit order page.
* Improve: Added missing date & time when click on Add to suspect button at edit order page.
* Improve: Some minor bugs fixed and improved.

= 1.3.0 =
* New: Upgrade entire code to be OOP style.
* New: User blocking option now is available.
* New: Source added, allowing you to know the entry's source.
* New: IP status, allowing you to know Suspect or Blocked.
* New: Email notification template added.
* Improve: Dashboard tab will stay where you left off.
* Improve: Duplicated checkout notice fixed.
* Improve: Unexpected strings fixed.

Premium version is now available, check it out on our website!

= 1.2.1 =
* Improve: The activation notice was dismissed to be a bit more robust. To ensure the notice behavior persists even with caching plugins like WP Rocket etc...

= 1.2.0 =
* Improve: CSS conflict fixed.
* Improve: Solved the issue of settings notice displays when cache cleared.

= 1.1.9.2 =
* Improve: Minor javascript bugs are fixed.

= 1.1.9.1 =
* Improve: A bug fixed.

= 1.1.9 =
* Improve: Avoid a hardcore security for some themes.
* Improve: Codes improved.

= 1.1.8 =
* Change: Text buttons to be icon buttons in the lists.
* Change: Rename Blacklist to Suspects to avoid confusing .
* Improve: Reorganised the files.
* Improve: Codes improved.

= 1.1.7 =
* New: IP Addresses multi lines addition.
* Improve: Small fixes.

= 1.1.6 =
* Error: Important bugs fixed.

= 1.1.5 =
* New: Email domain blocking added.
* New: Bulk action, easily delete multi rows in every lists.
* Improve: Small fixes.

= 1.1.4 =
* Change: Email notification setting became Notifications settings.
* New: Checkout, Registration Notice now is customizable.
* New: Prevent registration option for the user ip address is on the blacklist.
* Improve: Reorganized the codes to make them smoother and cleaner.

= 1.1.3 =
* New: Added an option (Settings) to prevent placing an order.
* Improve: Clear some unused codes. Small fixes.

= 1.1.2 =
* New: IP Blacklist released.
* New: Add customer IP into IP Blacklist by click on Add to Blacklist button (Flag icon) in the Order page (Admin).
* Improve: Popup message to confirm if you are sure to do the actions in the Blacklist Management.

= 1.1.1 =
* New: Declined to create an account if the email address is on Blocked list.
* New: Added the popup message to confirm if you are sure to do the action in the Order page (Admin).
* Improve: Change the text button to be icon button in the Order page (Admin).
* Improve: Refresh the Order page after Add to Blacklist message's displaying automatically (in 3 seconds).

= 1.1.0 =
* Settings: Prevent Order selection added.
* Language updated.

= 1.0.1 =
* JavaScript file is specifically enqueued only on the plugin.
* Small bugs fixed.

= 1.0.0 =
* Initial release.