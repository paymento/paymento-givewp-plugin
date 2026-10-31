=== Paymento for GiveWP ===
Contributors: paymento
Tags: givewp, donations, cryptocurrency, crypto, payment gateway
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Requires Plugins: give
Stable tag: 0.1.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept cryptocurrency donations in GiveWP forms with Paymento.

== Description ==

Adds "Crypto by Paymento" as a payment option to GiveWP donation forms. Donors pay on Paymento's secure checkout page, and the money goes straight to your own wallet. The donation is marked Completed automatically after Paymento confirms the payment.

* One-time donations, on visual (modern) and legacy GiveWP forms
* Payments are confirmed with Paymento directly. The page the donor returns to can't mark a donation as paid.
* Full refunds made in Paymento are reflected in GiveWP
* No donor names, emails or addresses are sent to Paymento

Recurring donations are not supported. Refunds must be issued from Paymento.

== External service ==

This plugin uses the Paymento service (https://api.paymento.io and https://app.paymento.io), and you need a Paymento merchant account to use it. When a donor checks out, the plugin sends Paymento the amount, the currency, an order reference and the address to return the donor to. To confirm a payment, it sends the payment token. Your API key is sent with each request. Donors pay directly on Paymento's checkout page.

Terms of service: https://paymento.io/term-of-service/
Privacy policy: https://paymento.io/privacy-policy/

== Installation ==

1. Install and activate GiveWP.
2. Upload this plugin's ZIP under Plugins > Add New > Upload Plugin, then activate it.
3. In your Paymento dashboard, copy the API Key and Secret Key of a store used only for this website.
4. Go to Settings > Paymento GiveWP, paste both keys and click Save credentials.
5. On the same page, tick "This is a dedicated Paymento store" and click Configure callback. Your site must use HTTPS.
6. Enable Paymento under Donations > Settings > Payment Gateways, and on your donation forms.
7. Set up a task that runs every minute from your WordPress folder:
   wp cron event run paymento_givewp_retry --quiet
   Without it, paid donations may stay Pending.
8. Make one small test donation before going live.

== Frequently Asked Questions ==

= The donor paid but the donation is still Pending =

Check that the step 7 task runs every minute and that you clicked Configure callback. Your site must be reachable over HTTPS, and a firewall or security plugin must not block /wp-json/paymento-givewp/v1/ipn.

= Why are the key fields empty after saving? =

Keys are stored encrypted and never shown again. To change them, enter both new values and save.

= What is "Merchant review"? =

Occasionally a donation needs a human look. For example, a donor might pay twice for the same donation. These donations are listed on the settings page. Check them against your Paymento dashboard.

= What happens when I deactivate the plugin? =

The background task stops, and your payment records are kept. Remove the cron task as well.

== Changelog ==

= 0.1.1 =
* WordPress.org Plugin Check fixes: prepared table names and sanitized settings input.

= 0.1.0 =
* First public release: one-time crypto donations, verified payment confirmation and refund updates.
