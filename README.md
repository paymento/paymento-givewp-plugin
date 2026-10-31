# Paymento for GiveWP

Accept cryptocurrency donations on your WordPress site. This plugin adds **Paymento** as a payment option to your [GiveWP](https://givewp.com/) donation forms.

When a donor picks "Crypto by Paymento", they are sent to Paymento's secure checkout page to pay. Paymento is non-custodial: the money goes straight to your own wallet. The donation in GiveWP is marked **Completed** automatically once Paymento confirms the payment.

## What you need

- WordPress 6.9 or newer, with PHP 8.2 or newer
- The GiveWP plugin, installed and active
- A website on **HTTPS** that the internet can reach. Paymento has to be able to call your site to report payments, so a local or plain-HTTP site won't work.
- A [Paymento](https://paymento.io) merchant account with a store for this website
- Access to your server's scheduled tasks (cron). See step 5.

## Installation

### 1. Install the plugin

1. Download `paymento-givewp.zip` from the [latest release](https://github.com/paymento/paymento-givewp-plugin/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP file and click **Install Now**.
3. Click **Activate**.

### 2. Enter your Paymento keys

1. In your Paymento dashboard, open the store you'll use for this website. Copy its **API Key** and **Secret Key**.
2. In WordPress, go to **Settings → Paymento GiveWP**.
3. Paste both values and click **Save credentials**.

The fields show as empty again after saving. This is expected: your keys are saved encrypted and never shown again. To change them, enter both new values and save.

### 3. Connect Paymento to your site

On the same settings page, tick **This is a dedicated Paymento store** and click **Configure callback**.

This tells Paymento where to send payment updates for your site. Use a Paymento store that serves only this website. A store has one callback address, so sharing it with another shop or site would break one of them. If the store already sends updates somewhere else, the plugin refuses to overwrite it.

### 4. Turn on Paymento in GiveWP

1. Go to **Donations → Settings → Payment Gateways** and enable **Paymento**.
2. Open your donation form and make sure Paymento is one of its payment options.

### 5. Add a scheduled task (required)

The plugin confirms payments in the background. Ask your host, or add this to your server's crontab, so the task runs **every minute** from your WordPress folder:

```sh
* * * * * cd /path/to/wordpress && wp cron event run paymento_givewp_retry --quiet
```

This needs [WP-CLI](https://wp-cli.org/), which most hosts have. Without this task, donations can stay **Pending** even after the donor has paid.

## Make a test donation

Before you share your form, make one small real donation:

1. Donate with **Crypto by Paymento**. You should land on Paymento's checkout page.
2. Pay from your wallet. While the payment is waiting for blockchain confirmations, the donation stays **Pending** in GiveWP.
3. Within a minute or two of Paymento confirming the payment, the donation becomes **Completed**, and the donor's receipt is sent.

## How it works

1. **The donor checks out.** GiveWP creates the donation as Pending, and the plugin sends the donor to Paymento's checkout.
2. **Paymento reports the payment.** When the payment status changes, Paymento sends a signed update to your site. The plugin checks the signature and saves the update.
3. **The plugin checks with Paymento.** The scheduled task asks Paymento directly whether the payment is complete. Only that answer completes the donation. The update itself is never trusted on its own, and neither is the page the donor returns to.
4. **Status changes:**
   - **Completed:** the payment is confirmed.
   - **Refunded:** Paymento reports a full refund.
   - **Pending:** the payment expired, was cancelled, or was rejected.

## Good to know

- **One-time donations only.** Recurring donations are not supported.
- **Refunds:** issue refunds from Paymento. The plugin updates GiveWP when Paymento reports them, but it can't start a refund itself.
- **Privacy:** the plugin sends Paymento only the amount, the currency, an order reference and the address to return the donor to. It never sends donor names, emails or addresses. See Paymento's [terms](https://paymento.io/term-of-service/) and [privacy policy](https://paymento.io/privacy-policy/).
- **Your keys** are encrypted using your WordPress security keys (salts) in `wp-config.php`. If you change those salts, enter your Paymento keys again.
- **Merchant review:** occasionally a donation needs a human look. For example, a donor might pay twice for the same donation. These donations are listed under **Merchant review** on the settings page. Check them against your Paymento dashboard.
- **Uninstalling:** deactivating stops the background task but keeps your payment records. Also remove the cron line from step 5.

## Troubleshooting

| Problem | What to check |
|---------|---------------|
| Paymento doesn't appear on the form | Is it enabled in GiveWP's Payment Gateways settings and on the form itself? Is the donation set as one-time? |
| "Paymento checkout is temporarily unavailable" | Re-enter your API Key and Secret Key. Make sure they come from the same Paymento store. |
| The donor paid, but the donation is still Pending | Is the step 5 task running every minute? Did you click **Configure callback**? Is the site reachable over HTTPS? Does a security plugin or firewall block `/wp-json/paymento-givewp/v1/ipn`? |
| "Paymento callback configuration failed" | The store may already send updates to another address. Use a store dedicated to this site. |

Need help? Contact [Paymento support](https://paymento.io).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
