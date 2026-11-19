<?php
defined('ABSPATH') || exit;

// Publication jobs live in plugin-owned queue tables. Queries use prepared
// values and must observe fresh rows to preserve locking and idempotency.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Durable, bounded catalogue-to-publication workflow. Never runs a full catalogue in an admin request. */
final class VilaHub_Publications {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'vilahub_publications'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key varchar(64) NOT NULL,
            tenant_id varchar(100) NOT NULL,
            connection_id varchar(100) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'QUEUED',
            data longtext NOT NULL,
            processed int unsigned NOT NULL DEFAULT 0,
            errors int unsigned NOT NULL DEFAULT 0,
            error_message text NULL,
            locked_until datetime NULL,
            lock_token varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_key (job_key),
            KEY ready (status,locked_until)
        ) $charset;");
    }
    public static function boot(): void {
        add_action('vilahub_publish_run', array(__CLASS__, 'run'), 10, 1);
        add_action('vilahub_dispatch', array(__CLASS__, 'dispatch'));
    }
    public static function dispatch(): void {
        global $wpdb;
        if (!VilaHub_Store::available()) { return; }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE status IN ('QUEUED','WAITING') ORDER BY id LIMIT 5", self::table()));
        foreach ($ids as $id) { self::schedule((int) $id); }
    }
    private static function schedule(int $id): void {
        as_schedule_single_action(time() + 30, 'vilahub_publish_run', array($id), 'vilahub', true);
    }
    public static function start(string $connection, array $ids, bool $all, string $key = ''): int {
        global $wpdb;
        VilaHub_Marketplace::require_access();
        $channels = (new VilaHub_Client())->request('GET', '/marketplace/connections')['data'];
        $destination = null;
        foreach ($channels as $channel) { if ($channel['id'] === $connection) { $destination = $channel; break; } }
        if (!$destination || !$destination['active'] || $destination['integrationStatus'] !== 'READY' || !$destination['productPublication'] || !$destination['pricingConfigured']) {
            throw new RuntimeException('Conecte o canal e salve a regra de preço antes de publicar.');
        }
        $ids = array_values(array_unique(array_map('absint', $ids)));
        if (!$all && (!$ids || count($ids) > 1000)) { throw new RuntimeException('Selecione até 1.000 produtos por operação, ou use todo o catálogo.'); }
        foreach ($ids as $id) { if (!current_user_can('edit_post', $id) || get_post_type($id) !== 'product') { throw new RuntimeException('Seleção de produtos inválida.'); } }
        if (!$key) { $key = wp_generate_uuid4(); }
        if (!preg_match('/^[a-f0-9-]{36}$/i', $key)) { throw new RuntimeException('Identificação da operação inválida.'); }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id,tenant_id,connection_id,data FROM %i WHERE job_key=%s', self::table(), $key), ARRAY_A);
        if ($existing) {
            $previous = VilaHub_Store::decode($existing['data']);
            if ($existing['tenant_id'] !== VilaHub_Store::settings()['tenant_id'] || $existing['connection_id'] !== $connection || $previous['all'] !== $all || (!$all && $previous['ids'] !== $ids)) { throw new RuntimeException('A identificação já foi usada com outra seleção.'); }
            return (int) $existing['id'];
        }
        $ceiling = (int) $wpdb->get_var("SELECT MAX(ID) FROM $wpdb->posts WHERE post_type='product' AND post_status='publish'");
        $data = array('destination' => $destination['name'] . ' · ' . $destination['channel'], 'all' => $all, 'ids' => $all ? array() : $ids, 'cursor' => 0, 'ceiling' => $ceiling, 'total' => $all ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='product' AND post_status='publish' AND ID<=%d",$ceiling)) : count($ids), 'offset' => 0,
            'pending' => array(), 'operation' => null, 'last_batch' => '', 'failed' => array());
        $ok = $wpdb->insert(self::table(), array('job_key' => $key, 'tenant_id' => VilaHub_Store::settings()['tenant_id'],
            'connection_id' => $connection, 'data' => VilaHub_Store::json($data), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')));
        if (!$ok) { throw new RuntimeException('Não foi possível salvar a fila de publicação.'); }
        $id = (int) $wpdb->insert_id; self::schedule($id); return $id;
    }
    public static function run(int $id): void {
        global $wpdb;
        if (!VilaHub_Store::available()) { return; }
        $token = wp_generate_uuid4();
        $locked = $wpdb->query($wpdb->prepare("UPDATE %i SET lock_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND) WHERE id=%d AND status IN ('QUEUED','WAITING') AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP())", self::table(), $token, $id));
        if ($locked !== 1) { return; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', self::table(), $id), ARRAY_A);
        $data = VilaHub_Store::decode($row['data']);
        try {
            VilaHub_Marketplace::require_access();
            if ($row['tenant_id'] !== VilaHub_Store::settings()['tenant_id']) { throw new RuntimeException('A empresa mudou. Revise esta operação.'); }
            $client = new VilaHub_Client();
            if (!$data['pending'] && !$data['operation']) {
                $ids = $data['all'] ? array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='product' AND post_status='publish' AND ID>%d AND ID<=%d ORDER BY ID LIMIT 20", $data['cursor'], $data['ceiling']))) : array_slice($data['ids'], $data['offset'], 20);
                if (!$ids) { self::write($id, $token, array('status' => 'COMPLETED', 'error_message' => $row['errors'] ? 'Confira os produtos com pendência abaixo e o resultado dos envios ao marketplace.' : 'Envio encaminhado. Confira o resultado do marketplace abaixo.')); return; }
                foreach ($ids as $product_id) { VilaHub_Sync::enqueue($product_id, true); }
                $data['pending'] = $ids;
                self::write($id, $token, array('status' => 'WAITING', 'data' => VilaHub_Store::json($data)));
                self::schedule($id); return;
            }
            if (!$data['operation']) {
                $remote = array(); $failed = array();
                foreach ($data['pending'] as $product_id) {
                    $source = wc_get_product($product_id);
                    if (!$source || $source->get_status() !== 'publish') { $failed[] = $product_id; continue; }
                    $product = VilaHub_Store::row($product_id);
                    if (!$product) { throw new RuntimeException('Não foi possível localizar a fila de um produto. Confira a sincronização e retome esta operação.'); }
                    if (!in_array($product['status'], array('SYNCED','ERROR','UNCERTAIN','NEEDS_LINK','SKIPPED'), true)) { self::schedule($id); return; }
                    if ($product['status'] === 'SYNCED' && $product['remote_id']) { $remote[] = $product['remote_id']; }
                    else { $failed[] = $product_id; }
                }
                $data['failed'] = array_slice(array_values(array_unique(array_merge($data['failed'], $failed))), -100);
                $data['operation'] = array('key' => wp_generate_uuid4(), 'credential' => VilaHub_Store::settings()['credential_id'],
                    'body' => array('connectionId' => $row['connection_id'], 'productIds' => $remote, 'name' => 'WooCommerce ' . $row['job_key'] . ':' . $data['offset']));
                $row['errors'] = (int) $row['errors'] + count($failed);
                // Exact body and key are durable before the first publication call.
                if (!self::write($id, $token, array('data' => VilaHub_Store::json($data), 'errors' => $row['errors']))) { return; }
            }
            $operation = $data['operation'];
            if ($operation['body']['productIds']) {
                $batch = null;
                if ($operation['credential'] !== VilaHub_Store::settings()['credential_id']) {
                    $found = $client->request('GET', '/marketplace/publications?reference=' . rawurlencode($operation['body']['name']))['data'];
                    if (count($found) !== 1) { throw new RuntimeException('A autorização mudou durante uma publicação pendente. Confira o lote antes de retomar; nenhum novo envio foi criado.'); }
                    $batch = $found[0];
                }
                try { if (!$batch) { $batch = $client->request('POST', '/marketplace/publications', $operation['body'], array('Idempotency-Key' => $operation['key']))['data']; } }
                catch (VilaHub_Api_Exception $error) {
                    if ($error->status === 0 || $error->status >= 500 || in_array($error->api_code, array('OPERATION_UNCERTAIN','OPERATION_IN_PROGRESS'), true)) {
                        $found = $client->request('GET', '/marketplace/publications?reference=' . rawurlencode($operation['body']['name']))['data'];
                        if (count($found) !== 1) { throw new RuntimeException('Resultado ainda não confirmado. A operação foi preservada para conferência; nenhum novo lote será criado automaticamente.'); }
                        $batch = $found[0];
                    } else { throw $error; }
                }
                $data['last_batch'] = $batch['id'];
            }
            $data['cursor'] = max($data['pending']); $data['offset'] += count($data['pending']);
            $data['pending'] = array(); $data['operation'] = null;
            self::write($id, $token, array('status' => 'QUEUED', 'data' => VilaHub_Store::json($data), 'processed' => $data['offset'], 'error_message' => ''));
            self::schedule($id);
        } catch (VilaHub_Api_Exception $error) {
            if (in_array($error->status, array(429,503), true) && !$data['operation']) { self::schedule($id); }
            else { self::write($id, $token, array('status' => 'REVIEW', 'error_message' => VilaHub_Product::truncate($error->getMessage(), 500))); }
        } catch (Throwable $error) {
            self::write($id, $token, array('status' => 'REVIEW', 'error_message' => VilaHub_Product::truncate($error->getMessage(), 500)));
        } finally {
            $wpdb->query($wpdb->prepare("UPDATE %i SET lock_token='',locked_until=NULL WHERE id=%d AND lock_token=%s", self::table(), $id, $token));
        }
    }
    private static function write(int $id, string $token, array $values): bool {
        global $wpdb;
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        return $wpdb->update(self::table(), $values, array('id' => $id, 'lock_token' => $token)) !== false;
    }
    public static function resume(int $id): void {
        global $wpdb;
        VilaHub_Marketplace::require_access();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND tenant_id=%s', self::table(), $id, VilaHub_Store::settings()['tenant_id']), ARRAY_A);
        if (!$row || $row['status'] !== 'REVIEW') { throw new RuntimeException('Operação não disponível para retomada.'); }
        // Resume retains the exact operation, cursor and idempotency key.
        $wpdb->update(self::table(), array('status' => 'QUEUED'), array('id' => $id)); self::schedule($id);
    }
}
