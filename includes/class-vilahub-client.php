<?php
defined('ABSPATH') || exit;

// Direct queries in this file implement an atomic, prepared rate-limit counter.
// A cached read would break concurrency protection across PHP workers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class VilaHub_Api_Exception extends RuntimeException {
    public int $status;
    public string $api_code;
    public string $request_id;
    public int $retry_after;
    public function __construct(string $message, int $status = 0, string $code = 'NETWORK_ERROR', string $request_id = '', int $retry_after = 0) {
        parent::__construct($message);
        $this->status = $status;
        $this->api_code = $code;
        $this->request_id = $request_id;
        $this->retry_after = $retry_after;
    }
}

final class VilaHub_Client {
    private string $key;
    private bool $oauth;
    public function __construct(?string $key = null) {
        $this->oauth = $key === null && (bool) get_option('vilahub_oauth_tokens');
        $this->key = $key ?? VilaHub_Store::key();
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = array()): array {
        if (!preg_match('/^vh_live_[A-Za-z0-9_-]{43}$/', $this->key)) {
            throw new VilaHub_Api_Exception('Conecte uma credencial válida do VilaHub.', 401, 'INVALID_API_KEY');
        }
        if (!preg_match('~^/[a-zA-Z0-9_/?=&%.:+-]*$~', $path) || str_contains(explode('?', $path, 2)[0], '..')) {
            throw new RuntimeException('Caminho da API inválido.');
        }
        self::pace();
        $args = array(
            'method' => $method, 'timeout' => 25, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 1048576,
            'headers' => array_merge(array('Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json', 'User-Agent' => 'VilaHub-WooCommerce/' . VILAHUB_WC_VERSION), $headers)
        );
        if ($body !== null) {
            $args['body'] = VilaHub_Store::json($body);
            if (strlen($args['body']) > 262144) {
                throw new VilaHub_Api_Exception('O produto excede o limite de 256 KB da API.', 413, 'PAYLOAD_TOO_LARGE');
            }
            $args['headers']['Content-Type'] = 'application/json';
        }
        // The credential is sent only to the official HTTPS service. No editable
        // destination, redirects, browser storage or JavaScript receives it.
        $result = wp_safe_remote_request('https://vilahub.com.br/api/v1' . $path, $args);
        if (is_wp_error($result)) {
            throw new VilaHub_Api_Exception('Não foi possível confirmar a resposta do VilaHub. A operação será conferida antes de novo envio.');
        }
        $status = (int) wp_remote_retrieve_response_code($result);
        $data = json_decode(wp_remote_retrieve_body($result), true);
        if ($status === 401 && $this->oauth) {
            // Another worker may have renewed after this client was created.
            // A rejected credential has not reached the resource mutation.
            $this->oauth = false;
            wp_cache_delete('vilahub_oauth_tokens', 'options');
            $current = VilaHub_Store::key();
            if ($current !== $this->key) { $this->key = $current; return $this->request($method, $path, $body, $headers); }
        }
        $request_id = (string) wp_remote_retrieve_header($result, 'x-request-id');
        $retry = min(3600, max(1, (int) wp_remote_retrieve_header($result, 'retry-after')));
        if ($status < 200 || $status >= 300) {
            $code = is_array($data) ? (string) ($data['code'] ?? 'HTTP_ERROR') : 'INVALID_RESPONSE';
            // Error text is deliberately allowlisted rather than logging remote bodies.
            $messages = array(
                'INVALID_API_KEY' => 'A credencial expirou ou foi revogada. Conecte novamente.',
                'INSUFFICIENT_SCOPE' => 'A credencial não tem todas as permissões necessárias.',
                'VERSION_CONFLICT' => 'O produto foi alterado no VilaHub durante o envio. Será consultado novamente.',
                'OPERATION_UNCERTAIN' => 'O resultado da operação ainda precisa ser confirmado.',
                'RATE_LIMITED' => 'O limite da API foi atingido. A fila aguardará o intervalo indicado.',
                'PAYMENT_REQUIRED' => 'Uma restrição comercial do VilaHub impede esta operação.',
                'INVALID_FIELD' => 'Um campo do produto não foi aceito pela API. Confira o cadastro e a documentação.',
                'DUPLICATE_RESOURCE' => 'Já existe um produto com esta identificação no VilaHub.',
                'OAUTH_REQUIRED' => 'Reconecte a loja ao VilaHub para autorizar a gestão de canais.',
                'INTEGRATION_UNAVAILABLE' => 'O aplicativo deste marketplace ainda precisa ser configurado no VilaHub. Contate o suporte.',
                'CHANNEL_NOT_READY' => 'Conecte e valide o canal antes de publicar.',
                'MARKUP_NOT_CONFIGURED' => 'Abra Canais, defina e salve a regra de preço desta conta.',
                'CONNECTION_LIMIT' => 'O limite de contas cadastradas foi atingido. Revise os canais existentes.',
                'PLAN_LIMIT_REACHED' => 'Seu plano atingiu o limite. O Grátis permite 10 produtos e 1 integração ativa; confira seu plano em VilaHub.',
                'PLAN_OWNER_REQUIRED' => 'Somente o proprietário da empresa pode escolher o plano no VilaHub.',
                'NOT_FOUND' => 'Este recurso não está disponível para a empresa conectada.'
            );
            $message = $messages[$code] ?? 'O VilaHub recusou a operação. Consulte o código e a identificação da requisição.';
            // These routes validate local business data before queueing; surface
            // actionable field errors, while provider test payloads stay hidden.
            if (strpos($path, '/marketplace/') === 0 && !str_ends_with(explode('?', $path, 2)[0], '/test') &&
                in_array($status, array(400,409), true) && in_array($code,array('INVALID_REQUEST','INVALID_FIELD','CHANNEL_NOT_READY','MARKUP_NOT_CONFIGURED','CONFLICT'),true) && is_string($data['detail'] ?? null)) {
                $detail = wp_strip_all_tags($data['detail']);
                foreach (array('accessToken','refreshToken') as $field) { if (!empty($body[$field])) { $detail = str_replace($body[$field], '[credencial]', $detail); } }
                $detail = preg_replace('/(?:Bearer\s+\S+|vh_(?:live|refresh)_[A-Za-z0-9_-]+)/i','[credencial]',$detail);
                $message = VilaHub_Product::truncate($detail,700);
            }
            // Exception fields are data; notices escape messages at the output boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new VilaHub_Api_Exception($message, $status, substr($code, 0, 100), substr($request_id, 0, 100), $retry);
        }
        if (!is_array($data) || !array_key_exists('data', $data)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is escaped when rendered.
            throw new VilaHub_Api_Exception('A resposta do VilaHub não pôde ser confirmada.', 0, 'INVALID_RESPONSE', $request_id);
        }
        return array('data' => $data['data'], 'pagination' => $data['pagination'] ?? null, 'etag' => (string) wp_remote_retrieve_header($result, 'etag'), 'request_id' => $request_id);
    }

    private static function pace(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,'0','no')", 'vilahub_http_next'));
        $deadline = microtime(true) + 2.0;
        do {
            $now = microtime(true);
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s AND CAST(option_value AS DECIMAL(20,6))<=%f",
                sprintf('%.6f', $now + 0.75), 'vilahub_http_next', $now
            ));
            if ($changed === 1) { return; }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new VilaHub_Api_Exception('A fila está respeitando o ritmo de envio.', 429, 'LOCAL_RATE_LIMIT', '', 3);
    }
}
