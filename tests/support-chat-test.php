<?php

define('ABSPATH', __DIR__ . '/');
define('VILAHUB_WC_VERSION', '1.0.7');

function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function update_user_meta($user, $key, $value) { $GLOBALS['support_meta'][$user][$key] = $value; }
function home_url() { return 'https://example.test'; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($value) { return $value['response']['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_safe_remote_request($url, $args) {
    $GLOBALS['support_request'] = [$url, $args];
    return [
        'response' => ['code' => 200],
        'body' => json_encode([
            'token' => 'chat-token',
            'conversation' => ['messages' => []],
        ]),
    ];
}

class VilaHub_Api_Exception extends RuntimeException {
    public string $api_code;

    public function __construct(string $message, int $status = 0, string $code = 'NETWORK_ERROR') {
        parent::__construct($message, $status);
        $this->api_code = $code;
    }
}

class VilaHub_Product {
    public static function truncate(string $value, int $length): string {
        return substr($value, 0, $length);
    }
}

require_once __DIR__ . '/../includes/class-vilahub-support.php';

$_POST['page'] = 'vilahub-integrations';
$start = new ReflectionMethod(VilaHub_Support::class, 'start_conversation');
$started = $start->invoke(null, 7);

if (($started['token'] ?? '') !== 'chat-token' || $GLOBALS['support_meta'][7]['_vilahub_support_chat_token'] !== 'chat-token') {
    throw new RuntimeException('The support conversation token was not persisted.');
}
if (($GLOBALS['support_request'][0] ?? '') !== 'https://vilahub.com.br/api/support-ai/start') {
    throw new RuntimeException('The public support endpoint was not used.');
}
$requestHeaders = $GLOBALS['support_request'][1]['headers'] ?? [];
if (isset($requestHeaders['Authorization'])) {
    throw new RuntimeException('The public support request must not require a store credential.');
}
$requestBody = json_decode($GLOBALS['support_request'][1]['body'] ?? '{}', true);
if (($requestBody['pageUrl'] ?? '') !== 'https://example.test/wp-admin/admin.php?page=vilahub-integrations') {
    throw new RuntimeException('The current plugin page was not sent to the public support endpoint.');
}

$errorPayload = new ReflectionMethod(VilaHub_Support::class, 'error_payload');
$payload = $errorPayload->invoke(null, new VilaHub_Api_Exception('Credential rejected.', 401, 'INVALID_API_KEY'));

if (($payload['code'] ?? '') !== 'SUPPORT_CHAT_ERROR' || ($payload['message'] ?? '') !== 'Credential rejected.') {
    throw new RuntimeException('Unexpected support chat error payload.');
}

echo "support-chat: PASS\n";
