<?php
defined('ABSPATH') || exit;
use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;

final class Paymento_GiveWP_Gateway extends PaymentGateway {
    public static function id(): string { return 'paymento'; }
    public function getId(): string { return self::id(); }
    public function getName(): string { return 'Paymento'; }
    public function getPaymentMethodLabel(): string { return 'Crypto by Paymento'; }
    public function supportsSubscriptions(): bool { return false; }
    public function enqueueScript(int $formId) {
        wp_enqueue_script('paymento-givewp-gateway', plugins_url('assets/gateway.js', PAYMENTO_GIVEWP_FILE), ['givewp-donation-form-registrars', 'wp-element'], '0.1.0', true);
    }
    public function formSettings(int $formId): array { return ['notice' => 'Continue to Paymento to pay your donation using cryptocurrency.']; }
    public function getLegacyFormFieldMarkup(int $formId, array $args): string { return '<p class="paymento-gateway-notice">Continue to Paymento to pay your donation using cryptocurrency.</p>'; }
    public function createPayment(Donation $donation, $gatewayData = []) {
        try { $result = Paymento_GiveWP::instance()->create_payment($donation, (array) $gatewayData); }
        catch (Throwable $error) { throw new RuntimeException('Paymento checkout is temporarily unavailable. Contact the merchant if this continues.'); }
        return new RedirectOffsite($result['redirect']);
    }
}
