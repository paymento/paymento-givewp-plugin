<?php
defined('ABSPATH') || exit;

final class Paymento_GiveWP_Settings {
    private const VAULT = 'paymento_givewp_credentials';

    private static function encode(array $value): string {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt(wp_json_encode($value), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new RuntimeException('credential_encryption_failed'); }
        return base64_encode($iv . $tag . $encrypted);
    }

    private static function decode(string $value): array {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) { throw new RuntimeException('credential_unavailable'); }
        $text = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $result = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($result)) { throw new RuntimeException('credential_unavailable'); }
        return $result;
    }

    public static function all(): array {
        $result = [];
        foreach ((array) get_option(self::VAULT, []) as $ref => $cipher) {
            $result[$ref] = self::decode($cipher);
        }
        return $result;
    }

    private static function reference(array $config): string { return hash_hmac('sha256', wp_json_encode($config), wp_salt('auth')); }

    public static function current(): array {
        $all = self::all();
        $ref = (string) get_option('paymento_givewp_current_credential', '');
        if (!$ref || !isset($all[$ref]) || !$all[$ref]['api_key'] || !$all[$ref]['ipn_secret']) { throw new RuntimeException('credentials_missing'); }
        return ['reference' => $ref] + $all[$ref];
    }

    public static function get(string $ref): array {
        $all = self::all();
        if (!isset($all[$ref])) { throw new RuntimeException('credential_unavailable'); }
        return ['reference' => $ref] + $all[$ref];
    }

    public static function archive(array $config): void {
        $ref = $config['reference']; unset($config['reference']);
        global $wpdb; $lock = 'pgw-vault-' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { throw new RuntimeException('credential_storage_busy'); }
        try {
            $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::VAULT));
            $vault = $stored === null ? [] : maybe_unserialize($stored);
            if (!is_array($vault)) { throw new RuntimeException('credential_storage_invalid'); }
            if (isset($vault[$ref])) { return; }
            if (count($vault) >= 20) { throw new RuntimeException('credential_archive_requires_review'); }
            $vault[$ref] = self::encode($config);
            wp_cache_delete(self::VAULT, 'options'); wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
            $saved = $stored === null ? add_option(self::VAULT, $vault, '', false) : $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($vault), 'autoload' => 'off'], ['option_name' => self::VAULT]);
            wp_cache_delete(self::VAULT, 'options'); wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
            $after = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::VAULT));
            if ($saved === false || maybe_unserialize($after) !== $vault) { throw new RuntimeException('credential_storage_failed'); }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    public static function register(): void {
        add_action('admin_menu', static function () { add_options_page('Paymento GiveWP', 'Paymento GiveWP', 'manage_options', 'paymento-givewp', [self::class, 'page']); });
        add_action('admin_post_paymento_givewp_save', [self::class, 'save']);
        add_action('admin_post_paymento_givewp_callback', [self::class, 'callback']);
    }

    private static function authorize(string $action): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer($action);
    }

    public static function save(): void {
        self::authorize('paymento_givewp_save');
        $key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in authorize().
        $secret = sanitize_text_field(wp_unslash($_POST['ipn_secret'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in authorize().
        try {
            if ($key === '' && $secret === '') { wp_safe_redirect(admin_url('options-general.php?page=paymento-givewp')); exit; }
            if ($key === '' || $secret === '' || strlen($key) > 512 || strlen($secret) > 512) { throw new RuntimeException('Both credentials are required.'); }
            $config = ['environment' => 'production', 'api_key' => $key, 'ipn_secret' => $secret, 'api_base' => 'https://api.paymento.io', 'checkout_base' => 'https://app.paymento.io/gateway'];
            $ref = self::reference($config); self::archive(['reference' => $ref] + $config);
            if (!update_option('paymento_givewp_current_credential', $ref, false) && get_option('paymento_givewp_current_credential') !== $ref) { throw new RuntimeException('Credential selection could not be saved.'); }
        } catch (Throwable $error) { wp_die('Paymento credentials could not be saved. Check both values and server encryption configuration.', '', ['response' => 400]); }
        wp_safe_redirect(admin_url('options-general.php?page=paymento-givewp')); exit;
    }

    public static function callback(): void {
        self::authorize('paymento_givewp_callback');
        if (sanitize_key(wp_unslash($_POST['dedicated_store'] ?? '')) !== 'yes') { wp_die('Confirm a dedicated Paymento store.', '', ['response' => 400]); } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in authorize().
        $url = rest_url('paymento-givewp/v1/ipn');
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') { wp_die('A public HTTPS callback URL is required.', '', ['response' => 400]); }
        try { self::configure_callback($url); }
        catch (Throwable $error) { wp_die('Paymento callback configuration failed.', '', ['response' => 502]); }
        wp_safe_redirect(admin_url('options-general.php?page=paymento-givewp')); exit;
    }

    public static function configure_callback(string $url): void {
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('public_https_required'); }
            $manager = Paymento_GiveWP::instance(); $config = self::current();
            $existing = $manager->api($config, '/v1/payment/settings', [], true, 'GET');
            if (!is_array($existing['body'] ?? null)) { throw new RuntimeException('settings_invalid'); }
            $old = $existing['body']['ipN_URL'] ?? '';
            if (!is_string($old) || ($old !== '' && $old !== $url)) { throw new RuntimeException('existing_callback_conflict'); }
            $manager->api($config, '/v1/payment/settings', ['ipN_URL' => $url, 'ipN_Method' => 1], true);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $configured = false;
        try { self::current(); $configured = true; } catch (Throwable $error) {}
        global $wpdb;
        $pending = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE processed IS NULL', Paymento_GiveWP::table('inbox')));
        $review = $wpdb->get_results($wpdb->prepare("SELECT donation_id,review_code FROM %i WHERE review_code<>'' ORDER BY donation_id DESC LIMIT 30", Paymento_GiveWP::table('donations')), ARRAY_A);
        echo '<div class="wrap"><h1>Paymento for GiveWP</h1><p>Credentials: ' . ($configured ? 'configured' : 'missing') . '. Pending receipts: ' . esc_html((string) $pending) . '.</p>';
        echo '<p>One-time donations only. Enable Paymento separately for modern and legacy forms in GiveWP payment settings. Configure a real cron worker; visitor traffic is not a reliable worker.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="paymento_givewp_save">';
        wp_nonce_field('paymento_givewp_save');
        echo '<p><label>API key <input type="password" name="api_key" value="" autocomplete="new-password"></label></p><p><label>Secret key <input type="password" name="ipn_secret" value="" autocomplete="new-password"></label></p><p>Enter both to rotate credentials. Blank values preserve current credentials. Existing attempt credentials remain encrypted for callbacks; protect WordPress authentication salts and database backups.</p>';
        submit_button('Save credentials'); echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="paymento_givewp_callback">';
        wp_nonce_field('paymento_givewp_callback');
        echo '<p><label><input type="checkbox" name="dedicated_store" value="yes" required> This is a dedicated Paymento store. Replace its store-wide callback with this site.</label></p>';
        submit_button('Configure callback'); echo '</form><h2>Merchant review</h2><p>Uncertain native lifecycles require manual reconciliation of donor/form totals and receipts. Do not blindly replay completion hooks.</p><ul>';
        foreach ($review as $row) { echo '<li>Donation ' . esc_html($row['donation_id']) . ': ' . esc_html($row['review_code']) . '</li>'; }
        echo '</ul></div>';
    }
}
