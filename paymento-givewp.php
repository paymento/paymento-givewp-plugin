<?php
/**
 * Plugin Name: Paymento for GiveWP
 * Description: Paymento hosted checkout and verified asynchronous one-time donations for GiveWP.
 * Plugin URI: https://github.com/paymento/paymento-givewp-plugin
 * Author: Paymento
 * Author URI: https://paymento.io
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: give
 * License: GPL-3.0-or-later
 */
defined('ABSPATH') || exit;
define('PAYMENTO_GIVEWP_FILE', __FILE__);
require_once __DIR__ . '/includes/class-paymento-settings.php';
require_once __DIR__ . '/includes/class-paymento-givewp.php';
Paymento_GiveWP::instance();
register_activation_hook(__FILE__, [Paymento_GiveWP::class, 'activate']);
register_deactivation_hook(__FILE__, [Paymento_GiveWP::class, 'deactivate']);
add_action('givewp_register_payment_gateway', static function ($register) {
    require_once __DIR__ . '/includes/class-paymento-gateway.php';
    $register->registerGateway(Paymento_GiveWP_Gateway::class);
});
