<?php

define('ABSPATH', __DIR__ . '/');
define('VILAHUB_WC_VERSION', '1.0.5');

function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function update_user_meta($user, $key, $value) { $GLOBALS['support_meta'][$user][$key] = $value; }
function home_url() { return 'https://example.test'; }
function wp_generate_uuid4() { return 'test-idempotency-key'; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }

class VilaHub_Api_Exception extends RuntimeException {
    public string $api_code;

    public function __construct(string $message, int $status = 0, string $code = 'NETWORK_ERROR') {
        parent::__construct($message, $status);
        $this->api_code = $code;
    }
}

class VilaHub_Client {
    public array $calls = [];

    public function request(string $method, string $path, $body = null, array $headers = []): array {
        $this->calls[] = [$method, $path, $body, $headers];
        return [
            'data' => [
                'token' => 'chat-token',
                'conversation' => ['messages' => []],
            ],
        ];
    }
}

class VilaHub_Product {
    public static function truncate(string $value, int $length): string {
        return substr($value, 0, $length);
    }
}

require_once __DIR__ . '/../includes/class-vilahub-support.php';

$_POST['page'] = 'vilahub';
$client = new VilaHub_Client();
$start = new ReflectionMethod(VilaHub_Support::class, 'start_conversation');
$started = $start->invoke(null, $client, 7);

if (($started['token'] ?? '') !== 'chat-token' || $GLOBALS['support_meta'][7]['_vilahub_support_chat_token'] !== 'chat-token') {
    throw new RuntimeException('The support conversation token was not persisted.');
}

$errorPayload = new ReflectionMethod(VilaHub_Support::class, 'error_payload');
$payload = $errorPayload->invoke(null, new VilaHub_Api_Exception('Credential rejected.', 401, 'INVALID_API_KEY'));

if (($payload['code'] ?? '') !== 'CONNECTION_REQUIRED' || strpos($payload['message'], 'vilahub-integrations') === false) {
    throw new RuntimeException('An invalid credential did not produce the connection-required response.');
}

echo "support-chat: PASS\n";
