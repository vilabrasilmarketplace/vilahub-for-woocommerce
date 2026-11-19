<?php
defined('ABSPATH') || exit;

// GET is used only to identify the current admin page. Mutating admin actions
// still use check_admin_referer(), and the stricter Missing rule remains enabled.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class VilaHub_Admin {
    public static function boot(): void {
        add_action('admin_notices',function(){
            if(!current_user_can('manage_options')||!get_option('vilahub_activation_notice'))return;
            $screen=get_current_screen();
            $page=sanitize_key(wp_unslash($_GET['page']??''));
            if((!$screen||$screen->id!=='plugins')&&strpos($page,'vilahub')!==0)return;
            delete_option('vilahub_activation_notice');
            echo '<div class="notice notice-success"><p><strong>VilaHub instalado.</strong> Acesse pelo menu principal <strong>VilaHub</strong> na lateral do WordPress.</p><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=vilahub')).'">Abrir VilaHub</a></p></div>';
        });
        add_action('admin_init', function () {
            if (sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'vilahub-settings' && current_user_can('manage_options')) { wp_safe_redirect(admin_url('admin.php?page=vilahub'),303); exit; }
        });
        add_action('admin_post_vilahub_action', array(__CLASS__, 'handle'));
        add_action('admin_enqueue_scripts', function ($hook) {
            $screen = get_current_screen();
            if ($hook === 'edit.php' && $screen && $screen->post_type === 'product') {
                wp_enqueue_script('vilahub-product-list', plugins_url('assets/product-list.js', VILAHUB_WC_FILE), array(), VILAHUB_WC_VERSION, true);
                if (current_user_can('manage_options')) {
                    wp_enqueue_script('vilahub-restrictions', plugins_url('assets/restrictions.js', VILAHUB_WC_FILE), array(), VILAHUB_WC_VERSION, true);
                    wp_localize_script('vilahub-restrictions', 'vilahubRestrictions', array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('vilahub_restrictions')));
                }
                wp_enqueue_style('vilahub-product-list', plugins_url('assets/product-list.css', VILAHUB_WC_FILE), array(), VILAHUB_WC_VERSION);
            }
            if (strpos($hook, 'vilahub') !== false) {
                wp_enqueue_style('vilahub-admin', plugins_url('assets/admin.css', VILAHUB_WC_FILE), array(), VILAHUB_WC_VERSION);
                wp_enqueue_style('vilahub-welcome', plugins_url('assets/welcome.css', VILAHUB_WC_FILE), array(), VILAHUB_WC_VERSION);
                wp_enqueue_script('vilahub-admin', plugins_url('assets/admin.js', VILAHUB_WC_FILE), array(), VILAHUB_WC_VERSION, true);
            }
        });
        add_filter('plugin_action_links_' . plugin_basename(VILAHUB_WC_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=vilahub')) . '"><strong>Abrir VilaHub</strong></a>');
            return $links;
        });
    }

    public static function authorize(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Você não tem permissão para gerenciar esta integração.', 'vilahub-for-woocommerce'), '', array('response' => 403)); }
        check_admin_referer('vilahub_admin_action');
    }

    public static function handle(): void {
        self::authorize();
        // The request nonce and capability were verified by authorize() above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $message = 'Operação concluída.';
        $error = false;
        try {
            $action = sanitize_key(wp_unslash($_POST['vilahub_do'] ?? ''));
            if ($action === 'connect') {
                VilaHub_OAuth::start(wp_unslash($_POST));
            } elseif ($action === 'disconnect') {
                if (empty($_POST['confirm_disconnect'])) { throw new RuntimeException('Confirme que deseja desconectar a integração desta loja.'); }
                $settings = VilaHub_Store::settings();
                $settings['automatic'] = false; $settings['paused'] = true;
                VilaHub_Store::save_settings($settings);
                VilaHub_OAuth::disconnect();
                $message = 'Integração desta loja desconectada. Os produtos e vínculos foram preservados.';
            } else { throw new RuntimeException('Operação inválida.'); }
        } catch (Throwable $exception) {
            $message = VilaHub_Product::truncate($exception->getMessage(), 600);
            $error = true;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        set_transient('vilahub_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 60);
        wp_safe_redirect(admin_url('admin.php?page=vilahub'));
        exit;
    }

    public static function connect(array $input): void {
        $key = trim((string) ($input['api_key'] ?? ''));
        $key = $key !== '' ? $key : VilaHub_Store::key();
        $client = new VilaHub_Client($key);
        $response = $client->request('GET', '/me');
        $me = $response['data'];
        $required = array('products:read', 'products:write', 'inventory:write', 'prices:write');
        if (!is_array($me) || empty($me['tenantId']) || empty($me['credentialId']) || ($me['apiVersion'] ?? '') !== 'v1') {
            throw new RuntimeException('O serviço não confirmou uma autorização compatível com a API v1.');
        }
        $missing = array_diff($required, $me['scopes'] ?? array());
        // The exception is escaped by the destination page when displayed as a notice.
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if ($missing) { throw new RuntimeException('Autorize estas permissões na credencial: ' . implode(', ', $missing) . '.'); }
        if (get_woocommerce_currency() !== 'BRL') { throw new RuntimeException('Esta versão precisa de uma loja com moeda BRL.'); }
        $previous = VilaHub_Store::settings();
        if ($previous['tenant_id'] && $previous['tenant_id'] !== $me['tenantId']) {
            throw new RuntimeException('Esta instalação já está vinculada a outra empresa. A troca exige revisão dos vínculos para preservar seus produtos.');
        }
        if (untrailingslashit(home_url()) !== get_option('vilahub_site_origin')) {
            throw new RuntimeException('O endereço da loja mudou ou esta é uma cópia do site. A conexão foi bloqueada para evitar envios por duas instalações com os mesmos vínculos.');
        }
        $capabilities = $client->request('GET', '/capabilities');
        if (($capabilities['data']['apiVersion'] ?? '') !== 'v1' || !in_array('products', $capabilities['data']['resources'] ?? array(), true)) {
            throw new RuntimeException('O serviço não confirmou a sincronização de produtos.');
        }
        $category = sanitize_text_field((string) ($input['category_id'] ?? ''));
        if (strlen($category) > 200) { throw new RuntimeException('O ID da categoria é inválido.'); }
        update_option('vilahub_api_secret', VilaHub_Store::encrypt($key), false);
        VilaHub_Store::save_settings(array(
            'tenant_id' => $me['tenantId'], 'credential_id' => $me['credentialId'],
            'name' => sanitize_text_field($me['name'] ?? ''), 'scopes' => $me['scopes'],
            'expires_at' => $me['expiresAt'] ?? '', 'automatic' => !empty($input['automatic']),
            'category_id' => $category, 'connected_at' => gmdate('Y-m-d H:i:s'), 'paused' => false
        ));
    }

}
