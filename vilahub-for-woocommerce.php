<?php
/**
 * Plugin Name: VilaHub – Marketplaces for WooCommerce
 * Plugin URI: https://vilahub.com.br/woocommerce
 * Description: Integração do WooCommerce com Mercado Livre, Shopee, Magalu, Amazon, SHEIN e outros marketplaces. Gerencie produtos, pedidos, estoque e NF-e.
 * Version: 1.0.6
 * Author: VilaHub
 * Author URI: https://vilahub.com.br
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.2.2
 * Text Domain: vilahub-for-woocommerce
 */

defined('ABSPATH') || exit;
define('VILAHUB_WC_VERSION', '1.0.6');
define('VILAHUB_WC_FILE', __FILE__);

foreach (array('store', 'client', 'product', 'sync', 'admin', 'oauth', 'marketplace', 'publications', 'welcome', 'published-products', 'order-record', 'order-migration', 'orders', 'order-admin', 'support') as $vilahub_class) {
    require_once __DIR__ . '/includes/class-vilahub-' . $vilahub_class . '.php';
}

register_activation_hook(__FILE__, function ($network_wide) {
    if ($network_wide) {
        wp_die(esc_html__('Ative o VilaHub individualmente em cada loja da rede.', 'vilahub-for-woocommerce'));
    }
    VilaHub_Store::install();
    update_option('vilahub_activation_notice',1,false);
});

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('', array(), 'vilahub');
    }
});

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', VILAHUB_WC_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce') || version_compare(WC_VERSION, '9.0', '<')) {
        add_action('admin_notices', function () {
            $screen=get_current_screen();
            if (!current_user_can('activate_plugins') || !$screen || $screen->id!=='plugins') return;
            echo '<div class="notice notice-error"><p>' . esc_html__('O VilaHub precisa do WooCommerce 9.0 ou superior ativo.', 'vilahub-for-woocommerce') . '</p></div>';
        });
        return;
    }
    if (get_option('vilahub_db_version') !== VILAHUB_WC_VERSION) {
        VilaHub_Store::install();
    }
    VilaHub_Sync::boot();
    VilaHub_Admin::boot();
    VilaHub_OAuth::boot();
    VilaHub_Marketplace::boot();
    VilaHub_Publications::boot();
    VilaHub_Orders::boot();
    VilaHub_Support::boot();
}, 20);
