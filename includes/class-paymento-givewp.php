<?php
defined('ABSPATH') || exit;

use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\CommandHandlers\PaymentCompleteHandler;

final class Paymento_GiveWP {
    private static ?self $instance = null;
    public static function instance(): self { return self::$instance ??= new self(); }

    private function __construct() {
        add_action('init', [self::class, 'maybe_upgrade'], 0);
        add_filter('cron_schedules', static function (array $s): array { $s['paymento_givewp_minute'] = ['interval' => 60, 'display' => 'Paymento minute']; return $s; });
        add_action('init', static function () { if (!wp_next_scheduled('paymento_givewp_retry')) { wp_schedule_event(time() + 60, 'paymento_givewp_minute', 'paymento_givewp_retry'); } });
        add_action('paymento_givewp_retry', [$this, 'process_inbox']);
        add_action('rest_api_init', function () {
            self::maybe_upgrade();
            register_rest_route('paymento-givewp/v1', '/ipn', ['methods' => ['POST', 'PUT'], 'permission_callback' => '__return_true', 'callback' => [$this, 'receive_ipn']]);
            register_rest_route('paymento-givewp/v1', '/return', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [$this, 'return_page']]);
        });
        add_filter('rest_pre_serve_request', [$this, 'serve_return'], 10, 3);
        Paymento_GiveWP_Settings::register();
    }

    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'paymento_givewp_' . $name; }
    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table('attempts') . " (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 donation_id bigint unsigned NOT NULL,
 form_id bigint unsigned NOT NULL,
 donor_id bigint unsigned NOT NULL,
 campaign_id bigint unsigned NOT NULL DEFAULT 0,
 mode varchar(10) NOT NULL,
 identity_hash char(64) NOT NULL,
 amount varchar(64) NOT NULL,
 minor_amount varchar(64) NOT NULL,
 currency varchar(6) NOT NULL,
 external_id varchar(100) NOT NULL,
 token varchar(255) DEFAULT NULL,
 return_key char(64) NOT NULL,
 credential_ref char(64) NOT NULL,
 api_base varchar(255) NOT NULL,
 checkout_base varchar(255) NOT NULL,
 success_url longtext NOT NULL,
 receipt_url longtext NOT NULL,
 cancel_url longtext NOT NULL,
 refunded tinyint NOT NULL DEFAULT 0,
 terminal_status int NOT NULL DEFAULT 0,
 review_code varchar(80) NOT NULL DEFAULT '',
 created bigint unsigned NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY token (token),
 UNIQUE KEY external_id (external_id),
 KEY donation_id (donation_id)
) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE ' . self::table('inbox') . " (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 attempt_id bigint unsigned NOT NULL,
 payload longtext NOT NULL,
 verified longtext DEFAULT NULL,
 received bigint unsigned NOT NULL,
 processed bigint unsigned DEFAULT NULL,
 retries int unsigned NOT NULL DEFAULT 0,
 next_attempt bigint unsigned NOT NULL DEFAULT 0,
 last_error varchar(100) NOT NULL DEFAULT '',
 PRIMARY KEY  (id),
 KEY due (processed,next_attempt),
 KEY attempt_id (attempt_id)
) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE ' . self::table('donations') . " (
 donation_id bigint unsigned NOT NULL,
 winner_attempt bigint unsigned DEFAULT NULL,
 terminal_refund tinyint NOT NULL DEFAULT 0,
 transition_kind varchar(20) NOT NULL DEFAULT '',
 transition_phase varchar(20) NOT NULL DEFAULT '',
 review_code varchar(100) NOT NULL DEFAULT '',
 PRIMARY KEY  (donation_id)
) ENGINE=InnoDB $collate;");
        $required = ['attempts' => ['id', 'donation_id', 'identity_hash', 'token', 'terminal_status', 'refunded', 'return_key', 'credential_ref', 'success_url', 'receipt_url', 'cancel_url'], 'inbox' => ['id', 'attempt_id', 'payload', 'verified', 'processed', 'retries', 'next_attempt', 'last_error'], 'donations' => ['donation_id', 'winner_attempt', 'terminal_refund', 'transition_kind', 'transition_phase', 'review_code']];
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) { if (!$wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', self::table($table), $column))) { throw new RuntimeException('schema_install_failed'); } }
        }
        if (!update_option('paymento_givewp_schema', 3, false) && (int) get_option('paymento_givewp_schema') !== 3) { throw new RuntimeException('schema_version_storage_failed'); }
        if (!wp_next_scheduled('paymento_givewp_retry')) { wp_schedule_event(time() + 60, 'paymento_givewp_minute', 'paymento_givewp_retry'); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('paymento_givewp_retry'); }
    public static function maybe_upgrade(): void { if ((int) get_option('paymento_givewp_schema') !== 3) { self::activate(); } }

    public function api(array $config, string $path, array $body, bool $require_success = false, string $method = 'POST'): array {
        $options = ['method' => $method, 'timeout' => 15, 'redirection' => 0, 'headers' => ['Api-Key' => $config['api_key'], 'Content-Type' => 'application/json']];
        if ($method !== 'GET') { $options['body'] = wp_json_encode($body); }
        $response = wp_remote_request($config['api_base'] . $path, $options);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new RuntimeException('paymento_api_unavailable'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !is_bool($data['success'] ?? null) || ($require_success && $data['success'] !== true)) { throw new RuntimeException('paymento_api_refused'); }
        return $data;
    }

    private function lock(string $name): bool { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name)) === 1; }
    private function unlock(string $name): void { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    public function parent_lock_name(int $id): string { global $wpdb; return 'pgw-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id), 0, 50); }
    public function lifecycle_lock_name(): string { global $wpdb; return 'pgw-life-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 45); }
    private function update(string $table, array $values, array $where): void { global $wpdb; if ($wpdb->update(self::table($table), $values, $where) === false) { throw new RuntimeException('storage_update_failed'); } }
    private function ledger(int $id): array {
        global $wpdb;
        if ($wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i (donation_id) VALUES (%d)', self::table('donations'), $id)) === false) { throw new RuntimeException('ledger_storage_failed'); }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE donation_id=%d', self::table('donations'), $id), ARRAY_A);
        if (!$row) { throw new RuntimeException('ledger_storage_failed'); } return $row;
    }

    private function identity(Donation $donation): string {
        $address = [];
        foreach (['country', 'address1', 'address2', 'city', 'state', 'zip'] as $field) { $address[$field] = (string) ($donation->billingAddress->$field ?? ''); }
        return hash('sha256', wp_json_encode([(int) $donation->formId, (int) $donation->donorId, (int) $donation->campaignId, $donation->email, $donation->firstName, $donation->lastName, $donation->mode->getValue(), $donation->type->getValue(), $address]));
    }
    private function assert_native(Donation $donation, ?array $attempt = null): void {
        if (!$donation->id || $donation->gatewayId !== 'paymento' || $donation->subscriptionId || !$donation->type->isSingle() || !$donation->donorId || $donation->amount->isZero() || $donation->amount->isNegative()) { throw new RuntimeException('native_donation_invalid'); }
        if ($attempt && ((int) $attempt['donation_id'] !== $donation->id || $attempt['identity_hash'] !== $this->identity($donation) || $attempt['minor_amount'] !== $donation->amount->formatToMinorAmount() || $attempt['currency'] !== $donation->amount->getCurrency()->getCode())) { throw new RuntimeException('native_snapshot_changed'); }
    }
    private function result(array $attempt): array { return ['attempt_id' => (int) $attempt['id'], 'donation_id' => (int) $attempt['donation_id'], 'token' => $attempt['token'], 'external_id' => $attempt['external_id'], 'redirect' => $attempt['checkout_base'] . '?token=' . rawurlencode($attempt['token'])]; }
    private function native_url(string $input): string {
        $url = preg_match('#^https?://#i', $input) ? $input : rawurldecode($input);
        $parts = wp_parse_url($url); $home = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || preg_match('/[\x00-\x20]/', $url) || isset($parts['user']) || isset($parts['pass'])) { throw new RuntimeException('native_return_url_invalid'); }
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80); $homePort = $home['port'] ?? (strtolower($home['scheme']) === 'https' ? 443 : 80);
        if (strtolower($parts['scheme']) !== strtolower($home['scheme']) || strtolower($parts['host'] ?? '') !== strtolower($home['host'] ?? '') || $port !== $homePort) { throw new RuntimeException('native_return_url_external'); }
        return esc_url_raw($url);
    }

    public function create_payment(Donation $donation, array $gatewayData = [], bool $force = false): array {
        self::maybe_upgrade(); global $wpdb;
        $lock = $this->parent_lock_name((int) $donation->id);
        if (!$this->lock($lock)) { throw new RuntimeException('donation_busy'); }
        try {
            $donation = Donation::find($donation->id); if (!$donation) { throw new RuntimeException('native_donation_missing'); }
            $this->assert_native($donation); $ledger = $this->ledger($donation->id);
            if ($ledger['terminal_refund'] || $ledger['winner_attempt'] || $donation->status->getValue() !== 'pending') { throw new RuntimeException('native_donation_not_pending'); }
            $previous = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE donation_id=%d AND token IS NOT NULL AND refunded=0 AND terminal_status NOT IN (4,5,9,10) ORDER BY id DESC LIMIT 1', self::table('attempts'), $donation->id), ARRAY_A);
            if ($previous && !$force) { $this->assert_native($donation, $previous); return $this->result($previous); }
            $config = Paymento_GiveWP_Settings::current(); Paymento_GiveWP_Settings::archive($config);
            $isVisual = Give\Helpers\Form\Utils::isV3Form($donation->formId);
            $fallbackReceipt = $isVisual ? (new Give\DonationForms\Actions\GenerateDonationConfirmationReceiptViewRouteUrl())($donation->purchaseKey) : give_get_success_page_url();
            $success = $this->native_url((string) ($gatewayData['successUrl'] ?? $fallbackReceipt));
            $cancel = $this->native_url((string) ($gatewayData['cancelUrl'] ?? get_permalink($donation->formId)));
            $receipt = $isVisual ? $success : $this->native_url(add_query_arg(['action' => 'view_in_browser', '_give_hash' => $donation->purchaseKey], $success));
            $external = 'givewp-' . $donation->id . '-' . wp_generate_uuid4(); $key = bin2hex(random_bytes(32));
            $record = ['donation_id' => $donation->id, 'form_id' => $donation->formId, 'donor_id' => $donation->donorId, 'campaign_id' => (int) $donation->campaignId, 'mode' => $donation->mode->getValue(), 'identity_hash' => $this->identity($donation), 'amount' => $donation->amount->formatToDecimal(), 'minor_amount' => $donation->amount->formatToMinorAmount(), 'currency' => $donation->amount->getCurrency()->getCode(), 'external_id' => $external, 'return_key' => $key, 'credential_ref' => $config['reference'], 'api_base' => $config['api_base'], 'checkout_base' => $config['checkout_base'], 'success_url' => $success, 'cancel_url' => $cancel, 'receipt_url' => $receipt, 'created' => time()];
            if (!$wpdb->insert(self::table('attempts'), $record)) { throw new RuntimeException('attempt_storage_failed'); }
            $id = (int) $wpdb->insert_id;
            $return = add_query_arg(['attempt' => $id, 'key' => $key], rest_url('paymento-givewp/v1/return'));
            $response = $this->api($config, '/v1/payment/request', ['fiatAmount' => $record['amount'], 'fiatCurrency' => $record['currency'], 'orderId' => $external, 'returnUrl' => $return], true);
            $token = $response['body'] ?? null;
            if (!is_string($token) || $token === '' || strlen($token) > 255 || preg_match('/[\x00-\x20]/', $token)) { throw new RuntimeException('paymento_token_invalid'); }
            $this->update('attempts', ['token' => $token], ['id' => $id]);
            $saved = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('attempts'), $id), ARRAY_A);
            if (!$saved || $saved['token'] !== $token) { throw new RuntimeException('token_storage_failed'); }
            return $this->result($saved);
        } finally { $this->unlock($lock); }
    }

    public function receive_ipn(WP_REST_Request $request): WP_REST_Response {
        self::maybe_upgrade(); global $wpdb;
        $raw = $request->get_body(); $signature = $request->get_header('X-HMAC-SHA256-Signature');
        if (strlen($raw) > 65536) { return new WP_REST_Response(['error' => 'body_too_large'], 413); }
        if (!preg_match('/^[A-F0-9]{64}$/D', $signature)) { return new WP_REST_Response(['error' => 'invalid_signature'], 401); }
        $matched = [];
        try { foreach (Paymento_GiveWP_Settings::all() as $ref => $config) { if (hash_equals(strtoupper(hash_hmac('sha256', $raw, $config['ipn_secret'])), $signature)) { $matched[] = $ref; } } }
        catch (Throwable $error) { return new WP_REST_Response(['error' => 'credentials_unavailable'], 503); }
        if (!$matched) { return new WP_REST_Response(['error' => 'invalid_signature'], 401); }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['Token'] ?? null) || !is_string($data['OrderId'] ?? null)) { return new WP_REST_Response(['error' => 'invalid_payload'], 400); }
        $attempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE token=%s', self::table('attempts'), $data['Token']), ARRAY_A);
        if (!$attempt || !hash_equals($attempt['token'], $data['Token']) || !hash_equals($attempt['external_id'], $data['OrderId']) || !in_array($attempt['credential_ref'], $matched, true)) { return new WP_REST_Response(['error' => 'unknown_payment'], 404); }
        if (!$wpdb->insert(self::table('inbox'), ['attempt_id' => $attempt['id'], 'payload' => $raw, 'received' => time()])) { return new WP_REST_Response(['error' => 'storage_unavailable'], 503); }
        return new WP_REST_Response(['received' => true], 200);
    }

    private function decimal($value): string {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^\d+(?:\.\d+)?$/D', (string) $value)) { throw new RuntimeException('settlement_amount_invalid'); }
        $parts = explode('.', (string) $value); $whole = ltrim($parts[0], '0'); $fraction = rtrim($parts[1] ?? '', '0');
        return ($whole === '' ? '0' : $whole) . ($fraction !== '' ? '.' . $fraction : '');
    }
    private function reviewed(int $donation, string $reason): void { $this->update('donations', ['review_code' => $reason], ['donation_id' => $donation]); }
    private function done(int $id): void { $this->update('inbox', ['processed' => time(), 'last_error' => ''], ['id' => $id]); }

    public function process_row(int $id): void {
        self::maybe_upgrade(); global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT i.*,a.donation_id FROM %i i JOIN %i a ON a.id=i.attempt_id WHERE i.id=%d', self::table('inbox'), self::table('attempts'), $id), ARRAY_A);
        if (!$row || $row['processed'] !== null) { return; }
        $lock = $this->parent_lock_name((int) $row['donation_id']); if (!$this->lock($lock)) { return; }
        try {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('inbox'), $id), ARRAY_A);
            if (!$row || $row['processed'] !== null) { return; }
            $attempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('attempts'), $row['attempt_id']), ARRAY_A);
            $donation = Donation::find((int) $attempt['donation_id']); if (!$donation) { throw new RuntimeException('native_donation_missing'); }
            $ledger = $this->ledger($donation->id); $this->assert_native($donation, $attempt);
            $nativeStatus = $donation->status->getValue();
            if ($nativeStatus === 'refunded' && !($ledger['transition_kind'] === 'refund' && $ledger['transition_phase'] === 'started')) {
                $this->update('donations', ['terminal_refund' => 1], ['donation_id' => $donation->id]); $ledger['terminal_refund'] = 1;
            }
            $verified = $row['verified'] !== null ? json_decode($row['verified'], true) : null;
            if (!$verified) {
                $config = Paymento_GiveWP_Settings::get($attempt['credential_ref']);
                if ($config['api_base'] !== $attempt['api_base'] || $config['checkout_base'] !== $attempt['checkout_base']) { throw new RuntimeException('endpoint_binding_changed'); }
                $verified = $this->api($config, '/v1/payment/verify', ['token' => $attempt['token']]);
                $body = $verified['body'] ?? null;
                if (!is_array($body) || ($body['token'] ?? null) !== $attempt['token'] || ($body['orderId'] ?? null) !== $attempt['external_id'] || !is_int($body['orderStatus'] ?? null) || $body['orderStatus'] < 0 || $body['orderStatus'] > 10) { throw new RuntimeException('verify_identity_invalid'); }
                if (isset($body['settlement']['requestedFiatAmount']) && $this->decimal($body['settlement']['requestedFiatAmount']) !== $this->decimal($attempt['amount'])) { throw new RuntimeException('verify_amount_mismatch'); }
                if (in_array($body['orderStatus'], [7, 8], true) && $verified['success'] !== true) { throw new RuntimeException('verify_not_approved'); }
                $this->update('inbox', ['verified' => wp_json_encode($verified)], ['id' => $id]);
            }
            $status = (int) $verified['body']['orderStatus'];
            $proof = $verified['body'] ?? null;
            if (!is_array($proof) || ($proof['token'] ?? null) !== $attempt['token'] || ($proof['orderId'] ?? null) !== $attempt['external_id'] || !is_int($proof['orderStatus'] ?? null) || $proof['orderStatus'] < 0 || $proof['orderStatus'] > 10) { throw new RuntimeException('verify_identity_invalid'); }
            if (isset($proof['settlement']) && (!is_array($proof['settlement']) || (isset($proof['settlement']['requestedFiatAmount']) && $this->decimal($proof['settlement']['requestedFiatAmount']) !== $this->decimal($attempt['amount'])))) { throw new RuntimeException('verify_amount_mismatch'); }
            if (in_array($status, [7, 8], true) && ($verified['success'] ?? false) !== true) { throw new RuntimeException('verify_not_approved'); }
            if ($status === 10) {
                $this->update('attempts', ['refunded' => 1, 'terminal_status' => 10], ['id' => $attempt['id']]);
                if ((int) $ledger['winner_attempt'] !== (int) $attempt['id']) { $this->done($id); return; }
                if ($ledger['terminal_refund']) { $this->done($id); return; }
                $this->transition($donation, $attempt, $ledger, 'refund'); $this->done($id); return;
            }
            if (in_array($status, [4, 5, 9], true)) { $this->update('attempts', ['terminal_status' => $status], ['id' => $attempt['id']]); }
            if (!in_array($status, [7, 8], true) || $attempt['refunded']) { $this->done($id); return; }
            if (($ledger['winner_attempt'] && (int) $ledger['winner_attempt'] !== (int) $attempt['id']) || ($ledger['terminal_refund'] && (int) $ledger['winner_attempt'] !== (int) $attempt['id'])) {
                $this->update('attempts', ['review_code' => 'duplicate_financial_payment'], ['id' => $attempt['id']]);
                if ($ledger['review_code'] !== 'native_lifecycle_uncertain') { $this->reviewed($donation->id, 'duplicate_financial_payment'); } $this->done($id); return;
            }
            if ($ledger['review_code'] === 'native_lifecycle_uncertain') { throw new RuntimeException('native_lifecycle_uncertain'); }
            if ($ledger['terminal_refund']) { $this->done($id); return; }
            if (!$ledger['winner_attempt']) {
                if ($nativeStatus !== 'pending' && $nativeStatus !== 'processing') { $this->reviewed($donation->id, 'unmapped_native_payment'); throw new RuntimeException('unmapped_native_payment'); }
                $this->update('donations', ['winner_attempt' => $attempt['id']], ['donation_id' => $donation->id]);
                $ledger = $this->ledger($donation->id);
                if ((int) $ledger['winner_attempt'] !== (int) $attempt['id']) { throw new RuntimeException('winner_storage_failed'); }
            }
            $this->transition($donation, $attempt, $ledger, 'complete'); $this->done($id);
        } catch (Throwable $error) {
            $safe = $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'native_or_storage_failure';
            if (isset($attempt['id'], $attempt['donation_id'])) {
                try {
                    $fresh = Donation::find((int) $attempt['donation_id']);
                    $intent = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE donation_id=%d', self::table('donations'), $attempt['donation_id']), ARRAY_A);
                    if ($fresh && $intent && $intent['transition_phase'] === 'started' && in_array($fresh->status->getValue(), ['publish', 'refunded'], true) && $fresh->gatewayTransactionId === 'paymento-givewp-' . $attempt['id']) {
                        $wpdb->update(self::table('donations'), ['review_code' => 'native_lifecycle_uncertain'], ['donation_id' => $attempt['donation_id']]);
                    }
                } catch (Throwable $ignored) { /* Keep durable work pending when storage itself is unavailable. */ }
            }
            $retries = (int) ($row['retries'] ?? 0) + 1;
            $wpdb->update(self::table('inbox'), ['retries' => $retries, 'next_attempt' => time() + min(3600, 30 * (2 ** min(7, $retries))), 'last_error' => $safe], ['id' => $id]);
        } finally { $this->unlock($lock); }
    }

    private function transition(Donation $donation, array $attempt, array $ledger, string $kind): void {
        $expected = $kind === 'complete' ? 'publish' : 'refunded'; $ref = 'paymento-givewp-' . $attempt['id'];
        $lock = $this->lifecycle_lock_name(); if (!$this->lock($lock)) { throw new RuntimeException('native_lifecycle_busy'); }
        try {
            // Other plugin workers may have committed since this process preloaded native caches.
            // Refresh only the affected Give entities and the native aggregate option.
            wp_cache_delete((int) $attempt['form_id'], 'form_meta');
            wp_cache_delete((int) $attempt['donor_id'], 'donor_meta');
            wp_cache_delete((int) $attempt['donation_id'], 'donation_meta');
            Give_Cache::delete_group((int) $attempt['form_id'], 'give-forms');
            Give_Cache::delete_group((int) $attempt['donor_id'], 'give-donors');
            Give_Cache::delete_group((int) $attempt['donation_id'], 'give-donations');
            wp_cache_delete('give_earnings_total', 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            // Native admin/provider saves do not take our advisory parent lock.
            // Re-read after the remote call and after acquiring the shared native lock.
            $donation = Donation::find($donation->id);
            if (!$donation) { throw new RuntimeException('native_donation_missing'); }
            $this->assert_native($donation, $attempt); $ledger = $this->ledger($donation->id);
            if ($ledger['review_code'] === 'native_lifecycle_uncertain' || ($kind === 'refund' && $ledger['transition_kind'] === 'complete' && $ledger['transition_phase'] !== 'finished')) {
                $this->reviewed($donation->id, 'native_lifecycle_uncertain'); throw new RuntimeException('native_lifecycle_uncertain');
            }
            if ($kind === 'complete' && $donation->status->getValue() === 'refunded') {
                if ($ledger['transition_kind'] === 'refund' && $ledger['transition_phase'] === 'started') { $this->reviewed($donation->id, 'native_lifecycle_uncertain'); throw new RuntimeException('native_lifecycle_uncertain'); }
                $this->update('donations', ['terminal_refund' => 1], ['donation_id' => $donation->id]); return;
            }
            if ($donation->status->getValue() === $expected) {
                if ($donation->gatewayTransactionId === $ref && $ledger['transition_kind'] === $kind && $ledger['transition_phase'] === 'finished') { return; }
                $this->reviewed($donation->id, 'native_lifecycle_uncertain'); throw new RuntimeException('native_lifecycle_uncertain');
            }
            if ($kind === 'complete' && !in_array($donation->status->getValue(), ['pending', 'processing'], true)) { throw new RuntimeException('native_status_not_recoverable'); }
            if ($kind === 'refund' && ($donation->status->getValue() !== 'publish' || $donation->gatewayTransactionId !== $ref)) { throw new RuntimeException('native_winner_mismatch'); }
            $this->update('donations', ['transition_kind' => $kind, 'transition_phase' => 'started'], ['donation_id' => $donation->id]);
            if ($kind === 'complete') { (new PaymentCompleteHandler(PaymentComplete::make($ref)))->handle($donation); }
            else {
                // The modern refunded command only changes status. Native Give_Payment
                // additionally reverses form, donor and global statistics via process_status.
                $payment = new Give_Payment($donation->id); $payment->refund();
            }
            $fresh = Donation::find($donation->id);
            if (!$fresh || $fresh->status->getValue() !== $expected || $fresh->gatewayTransactionId !== $ref) { throw new RuntimeException('native_transition_failed'); }
            $this->assert_native($fresh, $attempt);
            $values = ['transition_phase' => 'finished']; if ($kind === 'refund') { $values['terminal_refund'] = 1; }
            $this->update('donations', $values, ['donation_id' => $donation->id]);
        } finally { $this->unlock($lock); }
    }

    public function process_inbox(bool $force = false): void {
        if (!class_exists(Donation::class)) { return; }
        self::maybe_upgrade(); global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE processed IS NULL AND next_attempt<=%d ORDER BY retries ASC,id ASC LIMIT 50', self::table('inbox'), $force ? PHP_INT_MAX : time()));
        $started = microtime(true);
        foreach ($ids as $id) { if (microtime(true) - $started > 20) { break; } $this->process_row((int) $id); }
    }

    public function return_page(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table('attempts'), absint($request['attempt'])), ARRAY_A);
        $key = (string) ($request['key'] ?? '');
        if (!$attempt || strlen($key) !== 64 || !hash_equals($attempt['return_key'], $key)) { return new WP_REST_Response(['error' => 'not_found'], 404); }
        $donation = Donation::find((int) $attempt['donation_id']);
        $ledger = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE donation_id=%d', self::table('donations'), $attempt['donation_id']), ARRAY_A) ?: ['terminal_refund' => 0, 'review_code' => ''];
        $uncertain = ($ledger['transition_phase'] ?? '') === 'started' && $donation && in_array($donation->status->getValue(), ['publish', 'refunded'], true);
        $state = $ledger['review_code'] !== '' || $uncertain ? 'merchant_review' : ($ledger['terminal_refund'] ? 'refunded' : ($donation && $donation->status->getValue() === 'publish' ? 'confirmed' : 'pending'));
        $response = new WP_REST_Response(['state' => $state, 'receipt_url' => $this->native_url($attempt['receipt_url'] ?: $attempt['success_url']), 'cancel_url' => $this->native_url($attempt['cancel_url'])], 200);
        $response->header('Cache-Control', 'no-store, private'); return $response;
    }

    public function serve_return(bool $served, $result, WP_REST_Request $request): bool {
        if ($request->get_route() !== '/paymento-givewp/v1/return' || $result->get_status() !== 200) { return $served; }
        $data = $result->get_data();
        $messages = ['pending' => 'Your donation is awaiting payment confirmation.', 'confirmed' => 'Your donation has been confirmed. Thank you.', 'refunded' => 'Your donation has been refunded.', 'merchant_review' => 'Please contact the organization about your donation.'];
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Donation status</title></head><body><main><h1>Donation status</h1><p data-paymento-state="' . esc_attr($data['state']) . '">' . esc_html($messages[$data['state']]) . '</p><p><a class="paymento-receipt-link" href="' . esc_url($data['receipt_url']) . '">View donation receipt</a></p><p><a class="paymento-form-link" href="' . esc_url($data['cancel_url']) . '">Return to donation form</a></p></main></body></html>';
        return true;
    }
}
