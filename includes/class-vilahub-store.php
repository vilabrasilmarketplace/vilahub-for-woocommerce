<?php
defined('ABSPATH') || exit;

// Persistence repository for plugin-owned catalog and queue tables. Dynamic
// values are prepared; uncached reads are required for current queue ownership.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class VilaHub_Store {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'vilahub_sync';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            product_id bigint(20) unsigned NOT NULL,
            tenant_id varchar(100) NOT NULL DEFAULT '',
            remote_id varchar(100) NOT NULL DEFAULT '',
            candidate_id varchar(100) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'QUEUED',
            dirty tinyint(1) NOT NULL DEFAULT 1,
            force_sync tinyint(1) NOT NULL DEFAULT 0,
            last_payload longtext NULL,
            operation longtext NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            retry_at datetime NULL,
            error_code varchar(100) NOT NULL DEFAULT '',
            error_message text NULL,
            request_id varchar(100) NOT NULL DEFAULT '',
            synced_at datetime NULL,
            updated_at datetime NOT NULL,
            locked_until datetime NULL,
            lock_token varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (product_id),
            KEY ready (status,retry_at),
            KEY updated (updated_at)
        ) $charset;");
        add_option('vilahub_site_id', wp_generate_uuid4(), '', false);
        add_option('vilahub_site_origin', untrailingslashit(home_url()), '', false);
        VilaHub_Publications::install();
        VilaHub_Orders::install();
        update_option('vilahub_db_version', VILAHUB_WC_VERSION, false);
    }

    public static function settings(): array {
        return wp_parse_args((array) get_option('vilahub_settings', array()), array(
            'tenant_id' => '', 'credential_id' => '', 'name' => '', 'scopes' => array(),
            'expires_at' => '', 'automatic' => false, 'category_id' => '', 'connected_at' => '', 'paused' => false
        ));
    }

    public static function save_settings(array $settings): void {
        update_option('vilahub_settings', $settings, false);
    }

    public static function encrypt(string $secret): string {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('A extensão OpenSSL do PHP precisa estar ativa.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($secret, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new RuntimeException('Não foi possível proteger a credencial.');
        }
        return 'v1:' . base64_encode($iv . $tag . $encrypted);
    }

    public static function key(): string {
        if (get_option('vilahub_oauth_tokens')) { return VilaHub_OAuth::access_token(); }
        $value = (string) get_option('vilahub_api_secret', '');
        if (!$value) { return ''; }
        return self::decrypt($value);
    }

    public static function decrypt(string $value): string {
        $raw = base64_decode(substr($value, 3), true);
        if (strpos($value, 'v1:') !== 0 || $raw === false || strlen($raw) < 29) {
            throw new RuntimeException('A credencial armazenada está inválida. Conecte a loja novamente.');
        }
        $secret = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($secret === false) {
            throw new RuntimeException('As chaves de segurança do WordPress mudaram. Conecte a loja novamente.');
        }
        return $secret;
    }

    private static function encryption_key(): string {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }

    public static function available(): bool {
        $settings = self::settings();
        return empty($settings['paused']) && !empty($settings['credential_id']) && (bool) get_option('vilahub_api_secret')
            && untrailingslashit(home_url()) === get_option('vilahub_site_origin');
    }

    public static function row(int $id): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE product_id=%d', self::table(), $id), ARRAY_A) ?: null;
    }

    public static function enqueue(int $id, bool $force = false): void {
        global $wpdb;
        $settings = self::settings();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (product_id,tenant_id,status,dirty,force_sync,updated_at) VALUES (%d,%s,'QUEUED',1,%d,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE dirty=1,force_sync=GREATEST(force_sync,VALUES(force_sync)),
            status=IF(status IN ('SYNCED','QUEUED','RETRY','SKIPPED'),'QUEUED',status),updated_at=UTC_TIMESTAMP()",
            self::table(), $id, $settings['tenant_id'], $force ? 1 : 0
        ));
    }

    public static function lock(int $id): string {
        global $wpdb;
        $token = wp_generate_uuid4();
        $count = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET lock_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND)
            WHERE product_id=%d AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP())", self::table(), $token, $id
        ));
        return $count === 1 ? $token : '';
    }

    public static function write(int $id, string $token, array $values): bool {
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare('SELECT product_id FROM %i WHERE product_id=%d AND lock_token=%s AND locked_until>=UTC_TIMESTAMP()', self::table(), $id, $token))) { return false; }
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        $changed = $wpdb->update(self::table(), $values, array('product_id' => $id, 'lock_token' => $token));
        if ($changed === false) { return false; }
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT product_id FROM %i WHERE product_id=%d AND lock_token=%s AND locked_until>=UTC_TIMESTAMP()', self::table(), $id, $token));
    }

    public static function unlock(int $id, string $token): void {
        global $wpdb;
        $wpdb->update(self::table(), array('lock_token' => '', 'locked_until' => null), array('product_id' => $id, 'lock_token' => $token));
    }

    public static function decode(?string $value): array {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public static function json(array $value): string {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) { throw new RuntimeException('Não foi possível preparar os dados do produto.'); }
        return $encoded;
    }
}
