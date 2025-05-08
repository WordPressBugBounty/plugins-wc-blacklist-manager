=== Blacklist Manager – WooCommerce Anti-Fraud & Spam Prevention (Contact Form 7, Gravity Forms, WPForms) ===
Contributors: yoohw, baonguyen0310
Tags: blacklist customers, spam prevention, fraud prevention, woocommerce anti fraud, Prevent fake orders
Requires at least: 6.3
Tested up to: 6.8
WC tested up to: 9.8
Requires PHP: 5.6
Stable tag: 2.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

An anti-fraud and spam prevention plugin for WooCommerce and WordPress forms.

== Description ==

Blacklist Manager is a powerful anti-fraud and spam-prevention plugin for WooCommerce and WordPress forms. It blocks fraudulent orders, fake registrations, and spam form submissions by banning IPs, email addresses, and phone numbers at checkout or on contact forms. With real-time blacklist checks, you can stop chargebacks, unwanted sign-ups, and abusive bots before they hit your store. 

Easily blacklist phone numbers, email addresses, IP addresses, and email domains to block unwanted users from placing orders, submitting forms, canceling transactions, or registering accounts. Whether you're running an online store or collecting leads through forms, Blacklist Manager adds a critical layer of defense to your site.

Originally built for WooCommerce, it now extends its protection to popular form plugins including Contact Form 7, Gravity Forms, and WPForms.

[Premium version](https://yoohw.com/product/woocommerce-blacklist-manager-premium/) | [Documentation](https://yoohw.com/docs/category/woocommerce-blacklist-manager/) | [Support](https://yoohw.com/support/)  | [Demo](https://sandbox.yoohw.com/create-sandbox-user/)

== Features ==

* **Real-Time Blacklist Blocking**: Instantly block customers by IP, email, or phone number during checkout or form submission.
* **Easy Blacklist Management**: Easily add the phone number, email address, ip address from the order page; multi ip addresses/domains addition into blocking list.
* **Multi Notifications**: Email, alert and error notices for both admin and users are customizable.
* **WooCommerce Order Protection**: Prevent fake orders, duplicate orders, and fraudulent transactions.
* **Prevent Registration**: Option to prevent registration if the email/ip/domain is on the Blocklist.
* **Form Spam Shield**: Integrates with Contact Form 7, Gravity Forms, WPForms—blocks blacklist matches on all major WordPress forms.
* **Timed Cancellation**: Option to cancel the order if the email/phone is on the Blocklist in the delay of time.
* **Email Verification**: Customers are required to verify their email by entering a code sent to them during checkout to complete their order.
* **Phone Verification**: Customers must verify their phone number by entering an SMS code received during checkout before proceeding with their order.
* **User Blocking**: When the order has been placed by a user and has been added to the Blocklist, then the user is also set as blocked.

== Premium Features ==

Building on the robust features of the free version, the premium version offers advanced functionalities to safeguard your business against fraud and unauthorized transactions.

**Advanced Blocking**

* **Customer Name Blocking**: Adds the first and last name of the customer to the blocklist.
* **Address Blacklisting**: Block orders from specific billing and shipping addresses listed in your blocklist.
* **Prevent Submission**: Block form submissions from Contact Form 7, Gravity Forms, and WPForms if the IP address is on the Blocklist.
* **Prevent VPN & Proxy Submission**: Automatically block form submissions and registrations if the visitor is using a Proxy server or VPN connection.
* **IP Access Prevention**: Stop users from accessing your website from IP countries that you have selected.
* **Browser Blocking**: Restrict accessing your website for users of browsers.
* **Prevent Disposable Emails**: Block orders and registration if the customer uses a disposable email address.
* **Prevent Disposable Phones**: Block orders and automate adding to the blocklist  if the customer is using a disposable phone number.
* **Optional Payment Methods**: Disable the payment methods for the customers are in the Suspects list.

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

**Real-time Automatic Validation**

Our plugin ensures a seamless and error-free checkout experience with Real-time Automatic Validation. This feature automatically validates customer-provided details, including names, email addresses, and phone numbers, as they are entered. By detecting and alerting users of issues such as incomplete or invalid data, it helps to reduce errors and ensure compliance with your data integrity rules.

With intelligent validation logic, the plugin checks for:

* **Name Validation**: Detects invalid characters, excessive spaces, repeated characters, and ensures adherence to your custom length rules.
* **Email Validation**: Confirms that email addresses are correctly formatted and free from common typos or invalid domains.
* **Phone Validation**: Verifies phone numbers against predefined formats and country codes, with optional integration for SMS-based verification with the popular services Twilio and Textmagic.

This real-time validation not only improves the user experience by providing instant feedback but also ensures that your customer database remains accurate and clean.

**Prevent Orders and Access from Bots**

Protect every corner of your website and WooCommerce store with our comprehensive bot-blocking solution, now with expanded coverage for user authentication flows. Key features include:

* **Advanced bot detection**: AI-driven behavioral analysis stops spam bots in checkout, login, and registration forms alike.
* **Seamless user experience**: Invisible security layers—Google reCAPTCHA v3, v2, Cloudflare Turnstile, and hCaptcha—ensure legitimate customers sail through checkout, login, and signup without friction.
* **Expanded form protection**: Require CAPTCHA challenges on login and registration pages to block automated account creation and credential stuffing attacks.
* **API protection**: Safeguards your checkout, login, and registration endpoints from bots abusing your REST API.
* **Efficient and lightweight**: Delivers enterprise-grade security across all forms without slowing down your store.

**Blacklist Connection for Multiple Sites**

Blacklist Connection allows you to sync and consolidate blacklists from multiple WooCommerce stores or Sites, creating a centralized network of blacklisted emails, phone numbers, IP addresses, customer addresses, and email domains. This unified blacklist ensures a comprehensive defense against fraud across all your online stores and websites, improving security and saving you time.

Ideal for multi-store and websites owners and agencies managing numerous client sites—save time and boost security.

[See more about Blacklist Connection feature](https://yoohw.com/docs/woocommerce-blacklist-manager/settings/connection/)

**Universal Checkout Compatibility**

Our plugin is compatible with all types of checkout pages, including WooCommerce Classic, [Block-based Checkout](https://woocommerce.com/checkout-blocks/), and third-party checkout plugins. It also features address autocompletion on the checkout page to ensure accuracy and clarity through seamless Google Maps API integration.

**Permission Settings**

The Permission Settings feature of our plugin allows you to set both default and custom user roles to control access to the Dashboard, Notifications, and Settings. Tailor the access levels to ensure that only the appropriate users can manage and view the plugin's critical features.

**Import / Export**

Easily manage your blacklist data with our Import/Export feature. Quickly upload entries via CSV or export your blacklist data for backup, review, or migration purposes, ensuring seamless data management.

**Enhanced Protection**

Our premier solution for combating fraud and unauthorized transactions: we've integrated up with the finest third-party services to deliver the highest level of protection for your business. Each service we chose excels in identifying and preventing fraudulent activities. Moreover, these services offer free plans designed to support small and medium-sized businesses, enabling you to focus on growth while safeguarding your transactions.

Plugin supported: 
* [WooCommerce](https://wordpress.org/plugins/woocommerce/)
* [Contact Form 7](https://wordpress.org/plugins/contact-form-7/)
* [Gravity Forms](https://www.gravityforms.com/)
* [WPForms](https://wordpress.org/plugins/wpforms-lite/)

Plugin integrations: 
* [WooComerce Advanced Account](https://wordpress.org/plugins/wc-advanced-accounts/)
* [WooCommerce Stripe Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/)
* [Payment Plugins for Stripe WooCommerce](https://wordpress.org/plugins/woo-stripe-payment/). 

Service integrations:
* [reCaptcha v3/v2](https://www.google.com/recaptcha/about/)
* [Cloudflare](https://www.cloudflare.com/)
* [hCaptcha](https://www.hcaptcha.com/)
* [ip-api](https://ip-api.com/)
* [Usercheck](https://www.usercheck.com/)
* [ZeroBounce](https://www.zerobounce.net?ref=owqwzgy) 
* [NumCheckr](https://numcheckr.com/)
* [Google Maps Platform](https://mapsplatform.google.com/)
* [Yo Credits](https://yoohw.com/product/sms-credits/)
* [Twilio](https://www.twilio.com/)
* [Textmagic](https://www.textmagic.com/)

**Premium Support**

[Access our Premium Support site](https://yoohw.com/support/)

**Dedicated Assistance**: Access to our premium support team for any issues or questions you may have.
**Priority Response**: Receive faster response times and personalized support to ensure your plugin operates smoothly.

[Explore the Premium version here](https://yoohw.com/product/woocommerce-blacklist-manager-premium/)

With these premium features and dedicated support, the Blacklist Manager Premium plugin provides unparalleled security and efficiency, giving you peace of mind and allowing you to focus on growing your business.

== Use Cases ==

* Stop fraudulent WooCommerce orders and prevent chargebacks.
* Block spam submissions on registration or contact forms.
* Enforce email/phone verification at checkout.
* Maintain a dynamic blacklist across your entire WordPress site.

== Installation ==

1. **Upload Plugin**: Download the plugin and upload it to your WordPress site under `wp-content/plugins`.
2. **Activate**: Navigate to the WordPress admin area, go to the 'Plugins' section, and activate the 'Blacklist Manager' plugin.
3. **Database Setup**: Upon activation, the plugin automatically creates the necessary database tables.

== Frequently Asked Questions ==

**Q: Do I need to configure any settings after installation?**  
A: Yes, additional configuration is needed for the plugin to work as your expectation. Go to menu Blacklist Manager > Settings.

**Q: What is the purpose of the ‘Suspects’ list?**
A: You don't want to lose customers carelessly, do you? That's why there should be the Suspects list, for you to marked the customers and suspect them a while before you decide to block them or not.

**Q: Can this plugin prevent the customer to checkout through a separate payment page such as Paypal, Stripe etc..?**
A: The logic of the Blacklist Manager plugin is that to prevent the blocked customer can checkout through your website, the payment gateways have nothing to do with that. So, the answer is absolutely YES.

**Q: Can this plugin stop contact form spam?**
A: Yes, Blacklist Manager integrates with Contact Form 7, Gravity Forms, and WPForms to block form submissions from blacklisted emails or phone numbers.

**Q: Is there a limit to the number of entries I can add to the blacklist?**  
A: There is no set limit within the plugin, but practical limitations depend on your server and database performance.

**Q: Does Blacklist Manager slow down my site?**
A: No, the plugin is optimized for performance. It performs blacklist checks efficiently and only at appropriate times (like form submission or checkout), so it won’t noticeably impact your site speed.

== Changelog ==

= 2.0.2 (May 8, 2025) =
* Fix: The block email notification option did not work correctly.
* Improve: Minor improvement.

= 2.0.1 (Apr 23, 2025) =
* Fix: Avoid to trigger sending email of registration blocking notifications.
* Fix: Incorrect email notifications footer.

= 2.0 (Apr 17, 2025) =
* New: WooCommerce Blacklist Manager is now Blacklist Manager.
* New: Blacklist supported the forms (Contact Form 7, Gravity Forms, WPForms).
* New: Prevent submission from the blocked visitors and users.
* New: Stats overview is now available to see a summary of blacklist entries and detection attempts.
* New: Added the sender name, address, and recipients for email notifications.
* Update: Some new things on the panel and pages.
* Update: Function to send alert email to administrators.
* Update: Function to display the registration prevention notice.
* Fix: Duplicated settings submenu.
* Fix: Duplicated registration prevention notice.
* Fix: Cannot add the IP address to the blacklist from the order.
* Fix: Avoid blocking the administrator.
* Fix: Time zone of inserting suspected order.
* Improve: Added translation strings to all email notifications.
* Improve: Optimized and cleaned up.

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