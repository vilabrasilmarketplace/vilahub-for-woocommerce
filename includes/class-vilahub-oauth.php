<?php
defined('ABSPATH') || exit;

final class VilaHub_OAuth {
    const CLIENT_ID = 'vilahub-woocommerce';
    const ISSUER = 'https://vilahub.com.br';

    public static function boot(): void {
        add_action('admin_post_vilahub_oauth_callback', array(__CLASS__, 'callback'));
        add_action('admin_post_nopriv_vilahub_oauth_callback', function () {
            wp_die('A sessão do WordPress terminou. Entre novamente e reinicie a conexão pelo menu VilaHub.', '', array('response' => 403));
        });
    }
    private static function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private static function redirect_uri(): string { return admin_url('admin-post.php?action=vilahub_oauth_callback', 'https'); }
    private static function provider_redirect(string $url): void {
        $allow_vilahub=static function(array $hosts): array {$hosts[]='vilahub.com.br';return array_values(array_unique($hosts));};
        add_filter('allowed_redirect_hosts',$allow_vilahub);
        wp_safe_redirect($url,303,'VilaHub');
        remove_filter('allowed_redirect_hosts',$allow_vilahub);
        exit;
    }
    private static function same_store(bool $interactive = false): void {
        if (($interactive && !is_ssl()) || strpos(home_url(), 'https://') !== 0 || strpos(admin_url(), 'https://') !== 0) {
            throw new RuntimeException('Ative HTTPS no WordPress e na loja antes de conectar ao VilaHub.');
        }
        if (untrailingslashit(home_url()) !== get_option('vilahub_site_origin')) {
            throw new RuntimeException('O endereço mudou ou esta é uma cópia da loja. Revise os vínculos antes de conectar.');
        }
    }
    public static function start(array $input): void {
        self::same_store(true);
        if (get_woocommerce_currency() !== 'BRL') { throw new RuntimeException('A loja precisa usar a moeda BRL.'); }
        $state = self::b64(random_bytes(32));
        $verifier = self::b64(random_bytes(48));
        $redirect = self::redirect_uri();
        $category = sanitize_text_field((string) ($input['category_id'] ?? ''));
        if (strlen($category) > 200) { throw new RuntimeException('O ID da categoria é inválido.'); }
        $pending = array('verifier' => $verifier, 'redirect_uri' => $redirect,
            'user_id' => get_current_user_id(), 'origin' => untrailingslashit(home_url()),
            'category_id' => $category, 'automatic' => !empty($input['automatic']));
        $result = self::http('requests', array('client_id' => self::CLIENT_ID, 'response_type' => 'code',
            'redirect_uri' => $redirect, 'state' => $state, 'code_challenge_method' => 'S256',
            'code_challenge' => self::b64(hash('sha256', $verifier, true))));
        $url = (string) ($result['authorization_url'] ?? '');
        if (!preg_match('~^https://vilahub\.com\.br/conectar/woocommerce\?request=[A-Za-z0-9_-]{43}$~', $url)) {
            throw new RuntimeException('O VilaHub não confirmou o endereço de autorização.');
        }
        set_transient('vilahub_oauth_' . hash('sha256', $state), VilaHub_Store::encrypt(VilaHub_Store::json($pending)), 600);
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        // The URL is restricted by the exact VilaHub authorization pattern above.
        self::provider_redirect($url);
    }
    public static function callback(): void {
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $error = true;
        $message = 'A conexão não foi concluída. Inicie novamente pelo WordPress.';
        $claim = '';
        $tokens = null;
        try {
            if (!current_user_can('manage_options')) { throw new RuntimeException('Entre como administrador da loja para concluir.'); }
            self::same_store(true);
            // OAuth state is the callback's single-use CSRF token; a WordPress nonce cannot cross this redirect.
            $state = sanitize_text_field(wp_unslash($_GET['state'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $issuer = esc_url_raw(wp_unslash($_GET['iss'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $state) || $issuer !== self::ISSUER) {
                throw new RuntimeException('O retorno da autorização não foi confirmado. Inicie novamente.');
            }
            $pending_key = 'vilahub_oauth_' . hash('sha256', $state);
            $encrypted = get_transient($pending_key);
            $pending = $encrypted ? json_decode(VilaHub_Store::decrypt($encrypted), true) : null;
            if (!is_array($pending) || (int) $pending['user_id'] !== get_current_user_id() ||
                $pending['origin'] !== untrailingslashit(home_url()) || $pending['redirect_uri'] !== self::redirect_uri()) {
                throw new RuntimeException('A conexão expirou ou pertence a outra sessão. Inicie novamente nesta loja.');
            }
            $claim = 'vilahub_oauth_claim_' . hash('sha256', $state);
            if (!add_option($claim, time(), '', false)) { $claim = ''; throw new RuntimeException('Este retorno já está sendo processado.'); }
            delete_transient($pending_key);
            if (isset($_GET['error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                throw new RuntimeException('Autorização cancelada. A conexão anterior foi preservada.');
            }
            $code = sanitize_text_field(wp_unslash($_GET['code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $code)) { throw new RuntimeException('Código de autorização inválido.'); }
            $tokens = self::http('token', array('client_id' => self::CLIENT_ID, 'grant_type' => 'authorization_code',
                'code' => $code, 'code_verifier' => $pending['verifier'], 'redirect_uri' => $pending['redirect_uri']));
            self::validate_tokens($tokens);
            $previous = self::tokens();
            VilaHub_Admin::connect(array('api_key' => $tokens['access_token'],
                'category_id' => $pending['category_id'], 'automatic' => $pending['automatic']));
            self::save_tokens($tokens);
            if ($previous && $previous['refresh_token'] !== $tokens['refresh_token']) {
                try { self::http('revoke', array('client_id' => self::CLIENT_ID, 'token' => $previous['refresh_token'])); } catch (Throwable $ignored) { /* Old grant can also be revoked in VilaHub. */ }
            }
            $error = false;
            $message = 'Integração autorizada. Conecte suas contas de marketplace, defina os preços e envie os produtos pelo WooCommerce.';
        } catch (Throwable $exception) {
            if ($tokens && !empty($tokens['refresh_token'])) {
                try { self::http('revoke', array('client_id' => self::CLIENT_ID, 'token' => $tokens['refresh_token'])); } catch (Throwable $ignored) {}
            }
            $message = VilaHub_Product::truncate($exception->getMessage(), 600);
        } finally { if ($claim) { delete_option($claim); } }
        set_transient('vilahub_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 60);
        wp_safe_redirect(admin_url('admin.php?page=vilahub'), 303);
        exit;
    }
    private static function validate_tokens(array $tokens): void {
        if (!preg_match('/^vh_live_[A-Za-z0-9_-]{43}$/', $tokens['access_token'] ?? '') ||
            !preg_match('/^vh_refresh_[A-Za-z0-9_-]{43}$/', $tokens['refresh_token'] ?? '') ||
            ($tokens['token_type'] ?? '') !== 'Bearer' || (int) ($tokens['expires_in'] ?? 0) < 60 ||
            (int) $tokens['expires_in'] > 86400 || empty($tokens['credential_id']) || empty($tokens['tenant_id'])) {
            throw new RuntimeException('O VilaHub não confirmou uma autorização válida.');
        }
    }
    private static function save_tokens(array $tokens): void {
        $tokens['expires_at'] = time() + (int) $tokens['expires_in'];
        if (!update_option('vilahub_oauth_tokens', VilaHub_Store::encrypt(VilaHub_Store::json($tokens)), false)) {
            throw new RuntimeException('O WordPress não conseguiu salvar a autorização. Conecte novamente.');
        }
    }
    private static function tokens(): ?array {
        $value = get_option('vilahub_oauth_tokens');
        if (!$value) { return null; }
        $tokens = json_decode(VilaHub_Store::decrypt((string) $value), true);
        if (!is_array($tokens)) { throw new RuntimeException('A autorização não pôde ser lida. Conecte novamente.'); }
        return $tokens;
    }
    public static function access_token(): string {
        self::same_store();
        $tokens = self::tokens();
        if (!$tokens) { return ''; }
        if ((int) $tokens['expires_at'] > time() + 120) { return $tokens['access_token']; }
        // Atomic cross-process lock: concurrent workers must not rotate the same
        // refresh token. A stale lock is recovered with a compare-and-delete.
        global $wpdb;
        $lock = (string) time() . ':' . self::b64(random_bytes(16));
        $locked = add_option('vilahub_oauth_refresh_lock', $lock, '', false);
        if (!$locked) {
            $old = (string) get_option('vilahub_oauth_refresh_lock');
            if ((int) explode(':', $old)[0] < time() - 90) {
                $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name='vilahub_oauth_refresh_lock' AND option_value=%s", $old)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete for a stale OAuth lock.
                wp_cache_delete('vilahub_oauth_refresh_lock', 'options');
            }
            throw new VilaHub_Api_Exception('A conexão está sendo renovada. A fila tentará novamente.', 429, 'LOCAL_RATE_LIMIT', '', 5);
        }
        try {
            // Bypass the per-request option cache after acquiring the lock.
            wp_cache_delete('vilahub_oauth_tokens', 'options');
            $tokens = self::tokens();
            if (!$tokens) { throw new RuntimeException('Conecte a loja novamente.'); }
            if ((int) $tokens['expires_at'] > time() + 120) { return $tokens['access_token']; }
            $next = self::http('token', array('client_id' => self::CLIENT_ID, 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']));
            self::validate_tokens($next);
            if ($next['tenant_id'] !== $tokens['tenant_id'] || $next['credential_id'] !== $tokens['credential_id']) {
                throw new RuntimeException('A empresa da conexão mudou. Conecte novamente.');
            }
            self::save_tokens($next);
            return $next['access_token'];
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name='vilahub_oauth_refresh_lock' AND option_value=%s", $lock)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete prevents releasing another worker's lock.
            wp_cache_delete('vilahub_oauth_refresh_lock', 'options');
        }
    }
    public static function disconnect(): void {
        $tokens = self::tokens();
        if ($tokens) {
            // Keep the local token if revocation could not be confirmed so the
            // merchant can retry, instead of losing the only revocation proof.
            self::http('revoke', array('client_id' => self::CLIENT_ID, 'token' => $tokens['refresh_token']));
        }
        delete_option('vilahub_oauth_tokens');
        delete_option('vilahub_api_secret');
    }
    private static function http(string $path, array $body): array {
        $response = wp_safe_remote_post(self::ISSUER . '/api/oauth/' . $path, array(
            'timeout' => 25, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 32768,
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body' => VilaHub_Store::json($body)
        ));
        if (is_wp_error($response)) { throw new VilaHub_Api_Exception('Não foi possível confirmar a conexão com o VilaHub. Tente novamente.', 503, 'OAUTH_UNAVAILABLE', '', 30); }
        $status = (int) wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($result)) {
            $invalid = is_array($result) && in_array($result['error'] ?? '', array('invalid_grant', 'invalid_client'), true);
            /* phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is escaped when rendered. */
            throw new VilaHub_Api_Exception($invalid ? 'A autorização expirou ou foi revogada. Clique em Conectar ao VilaHub.' : 'O VilaHub não concluiu a conexão. Tente novamente.',
                $invalid ? 401 : ($status >= 500 || $status === 429 ? $status : 400), $invalid ? 'INVALID_API_KEY' : 'OAUTH_UNAVAILABLE', '', 30);
            /* phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped */
        }
        return $result;
    }
}
