<?php
defined('ABSPATH') || exit;

// Synchronization claims plugin-owned queue rows with prepared atomic queries.
// Object caching would allow two workers to claim the same catalog operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

final class VilaHub_Sync {
    public static function boot(): void {
        add_action('action_scheduler_init', array(__CLASS__, 'schedule'));
        add_action('vilahub_dispatch', array(__CLASS__, 'dispatch'));
        add_action('vilahub_sync_product', array(__CLASS__, 'run'), 10, 1);
        add_action('vilahub_catalog_batch', array(__CLASS__, 'batch'));
        foreach (array('woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation') as $hook) {
            add_action($hook, array(__CLASS__, 'changed'), 20, 1);
        }
        foreach (array('woocommerce_product_set_stock', 'woocommerce_variation_set_stock') as $hook) {
            add_action($hook, function ($product) { self::changed($product->get_id()); }, 20, 1);
        }
        foreach (array('woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status') as $hook) {
            add_action($hook, array(__CLASS__, 'changed'), 20, 1);
        }
        add_action('transition_post_status', function ($new, $old, $post) {
            if ($new !== $old && in_array($post->post_type, array('product', 'product_variation'), true)) { self::changed((int) $post->ID); }
        }, 20, 3);
        add_action('before_delete_post', function ($id, $post) {
            if (in_array($post->post_type, array('product', 'product_variation'), true)) { self::changed((int) $id); }
        }, 20, 2);
    }

    public static function schedule(): void {
        if (!as_has_scheduled_action('vilahub_dispatch', array(), 'vilahub')) {
            as_schedule_recurring_action(time() + 60, 60, 'vilahub_dispatch', array(), 'vilahub', true);
        }
    }

    public static function changed(int $id): void {
        if (!VilaHub_Store::available() || !VilaHub_Store::settings()['automatic']) { return; }
        try { self::enqueue($id); }
        catch (Throwable $error) {
            // A temporary queue failure must never abort a purchase or stock save.
            // The merchant can retry from the VilaHub queue; no server log is needed.
        }
    }

    public static function enqueue(int $id, bool $force = false): void {
        $product = wc_get_product($id);
        if (!$product) { return; }
        if ($product->is_type('variation')) { $id = $product->get_parent_id(); }
        VilaHub_Store::enqueue($id, $force);
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 3, 'vilahub_sync_product', array($id), 'vilahub', true);
        }
    }

    public static function dispatch(): void {
        global $wpdb;
        if (!VilaHub_Store::available()) { return; }
        self::batch();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT product_id FROM %i WHERE status IN ('QUEUED','RETRY','RUNNING') AND (retry_at IS NULL OR retry_at<=UTC_TIMESTAMP()) AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP()) ORDER BY updated_at ASC LIMIT 10", VilaHub_Store::table()));
        foreach ($ids as $id) {
            as_schedule_single_action(time() + 1, 'vilahub_sync_product', array((int) $id), 'vilahub', true);
        }
    }

    public static function start_batch(): void {
        if (!VilaHub_Store::available()) { throw new RuntimeException('Conecte a loja antes de preparar a fila.'); }
        if (get_option('vilahub_batch')) { throw new RuntimeException('Já existe uma preparação de catálogo em andamento.'); }
        add_option('vilahub_batch', array('cursor' => 0, 'queued' => 0), '', false);
        as_schedule_single_action(time() + 1, 'vilahub_catalog_batch', array(), 'vilahub', true);
    }

    public static function batch(): void {
        global $wpdb;
        if (!VilaHub_Store::available()) { return; }
        $batch = get_option('vilahub_batch');
        if (!is_array($batch)) { return; }
        // Only identifiers are selected directly. Product data is always read
        // through WooCommerce CRUD, and no order tables are read or written.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='product' AND post_status='publish' AND ID>%d ORDER BY ID ASC LIMIT 20", (int) $batch['cursor']));
        foreach ($ids as $id) { self::enqueue((int) $id, true); }
        if ($ids) {
            $batch['cursor'] = (int) end($ids);
            $batch['queued'] += count($ids);
            update_option('vilahub_batch', $batch, false);
        }
        if (count($ids) < 20) {
            update_option('vilahub_last_batch', array('queued' => $batch['queued'], 'finished_at' => gmdate('Y-m-d H:i:s')), false);
            delete_option('vilahub_batch');
        } else {
            as_schedule_single_action(time() + 5, 'vilahub_catalog_batch', array(), 'vilahub', false);
        }
    }

    public static function run(int $id): void {
        if (!VilaHub_Store::available()) { return; }
        $token = VilaHub_Store::lock($id);
        if (!$token) { return; }
        try {
            $row = VilaHub_Store::row($id);
            $settings = VilaHub_Store::settings();
            if (!$row || $row['tenant_id'] !== $settings['tenant_id']) { return; }
            if ($row['retry_at'] && strtotime($row['retry_at'] . ' UTC') > time()) { return; }
            if (in_array($row['status'], array('NEEDS_LINK', 'ERROR', 'UNCERTAIN'), true)) { return; }
            $operation = VilaHub_Store::decode($row['operation']);
            if ($operation) {
                self::execute($id, $token, $row, $operation);
                return;
            }
            $previous = VilaHub_Store::decode($row['last_payload']);
            $source = wc_get_product($id);
            $withdrawal = !$source || $source->get_status() !== 'publish';
            if ($withdrawal) {
                if (!$row['remote_id'] || !$previous) {
                    VilaHub_Store::write($id, $token, array('status' => 'SKIPPED', 'dirty' => 0, 'error_code' => 'NOT_PUBLISHED', 'error_message' => 'Produto não publicado no WooCommerce. Nenhum novo cadastro foi enviado.'));
                    return;
                }
                $payload = $previous;
                $payload['stock'] = 0;
                foreach ($payload['variations'] as &$variant) { $variant['stock'] = 0; }
                unset($variant);
            } else {
                $payload = VilaHub_Product::payload($id);
            }
            if (!$row['force_sync'] && $previous && VilaHub_Product::hash($payload) === VilaHub_Product::hash($previous)) {
                VilaHub_Store::write($id, $token, array('status' => 'SYNCED', 'dirty' => 0, 'error_code' => '', 'error_message' => ''));
                return;
            }
            $client = new VilaHub_Client();
            $remote = null;
            if ($row['remote_id']) {
                $remote = $client->request('GET', '/products/' . rawurlencode($row['remote_id']));
            } else {
                $found = $client->request('GET', '/products?sku=' . rawurlencode($payload['sku']) . '&limit=2');
                if (!empty($found['data'])) {
                    $candidate = $found['data'][0];
                    if (self::owned($candidate, $id)) {
                        $row['remote_id'] = $candidate['id'];
                        VilaHub_Store::write($id, $token, array('remote_id' => $candidate['id']));
                        $remote = $client->request('GET', '/products/' . rawurlencode($candidate['id']));
                    } else {
                        VilaHub_Store::write($id, $token, array('status' => 'NEEDS_LINK', 'candidate_id' => $candidate['id'], 'error_code' => 'SKU_ALREADY_EXISTS', 'error_message' => 'Já existe este SKU no VilaHub. Confirme o vínculo para autorizar a atualização pelo WooCommerce.'));
                        return;
                    }
                }
            }
            $body = $remote ? ($row['force_sync'] || !$previous ? $payload : VilaHub_Product::delta($payload, $previous)) : $payload;
            if ($withdrawal) { $body = array('stock' => 0, 'variations' => $payload['variations']); }
            if ($remote && !empty($remote['data']['variations']) && (isset($body['stock']) || isset($body['priceInCents']))) {
                $body['variations'] = $payload['variations'];
            }
            if(get_option('vilahub_order_stock_authority')==='ERP'){
                if($remote)unset($body['stock']);else $body['stock']=0;
                if(isset($body['variations'])){
                    $remote_stocks=array_column($remote['data']['variations']??array(),'stock','sku');
                    foreach($body['variations'] as &$variant)$variant['stock']=isset($remote_stocks[$variant['sku']])?(int)$remote_stocks[$variant['sku']]:0;unset($variant);
                }
            }
            $operation_id = wp_generate_uuid4();
            $body['attributes']['wordpressOrigin'] = array('siteId' => get_option('vilahub_site_id'), 'productId' => $id, 'operationId' => $operation_id);
            $operation = array(
                'id' => $operation_id, 'credential_id' => $settings['credential_id'],
                'method' => $remote ? 'PATCH' : 'POST',
                'path' => $remote ? '/products/' . rawurlencode($row['remote_id']) : '/products',
                'etag' => $remote ? $remote['etag'] : '', 'body' => $body, 'snapshot' => $payload,
                'remote_id' => $row['remote_id'], 'sku' => $payload['sku']
            );
            // Persist the exact operation BEFORE sending. Timeouts and restarts
            // reuse the same body, ETag and idempotency key.
            if (!VilaHub_Store::write($id, $token, array('operation' => VilaHub_Store::json($operation), 'status' => 'RUNNING', 'dirty' => 0, 'force_sync' => 0))) { return; }
            self::execute($id, $token, $row, $operation);
        } catch (VilaHub_Api_Exception $error) {
            self::failure($id, $token, $error);
        } catch (Throwable $error) {
            VilaHub_Store::write($id, $token, array('status' => 'ERROR', 'error_code' => 'PRODUCT_CONFIGURATION', 'error_message' => VilaHub_Product::truncate($error->getMessage(), 500)));
        } finally {
            VilaHub_Store::unlock($id, $token);
        }
    }

    private static function execute(int $id, string $token, array $row, array $operation): void {
        $client = new VilaHub_Client();
        if ($operation['credential_id'] !== VilaHub_Store::settings()['credential_id']) {
            if (!self::reconcile($id, $token, $operation, $client)) {
                VilaHub_Store::write($id, $token, array('status' => 'UNCERTAIN', 'error_code' => 'CREDENTIAL_CHANGED', 'error_message' => 'A credencial mudou durante uma operação pendente. Confira o resultado antes de autorizar outro envio.'));
            }
            return;
        }
        $headers = array('Idempotency-Key' => $operation['id']);
        if ($operation['etag']) { $headers['If-Match'] = $operation['etag']; }
        try {
            $response = $client->request($operation['method'], $operation['path'], $operation['body'], $headers);
            if (empty($response['data']['id'])) { throw new VilaHub_Api_Exception('Não foi possível identificar o produto gravado.', 0, 'INVALID_RESPONSE'); }
            self::success($id, $token, $operation, $response['data']['id']);
        } catch (VilaHub_Api_Exception $error) {
            if ($error->status === 412) {
                // A definitive precondition failure means no product write was
                // performed. The next attempt can obtain a new version/key.
                VilaHub_Store::write($id, $token, array('operation' => null));
                self::failure($id, $token, $error, true);
                return;
            }
            if ($error->status === 0 || $error->status >= 500 || in_array($error->api_code, array('OPERATION_UNCERTAIN', 'OPERATION_IN_PROGRESS', 'DUPLICATE_RESOURCE'), true)) {
                if (self::reconcile($id, $token, $operation, $client)) { return; }
                if (in_array($error->api_code, array('OPERATION_UNCERTAIN', 'DUPLICATE_RESOURCE'), true)) {
                    VilaHub_Store::write($id, $token, array('status' => 'UNCERTAIN', 'error_code' => $error->api_code, 'error_message' => 'A gravação não pôde ser confirmada. Use “Verificar e tentar novamente”; a operação original foi preservada.', 'request_id' => $error->request_id));
                    return;
                }
            }
            throw $error;
        }
    }

    private static function reconcile(int $id, string $token, array $operation, VilaHub_Client $client): bool {
        try {
            if ($operation['remote_id']) {
                $response = $client->request('GET', '/products/' . rawurlencode($operation['remote_id']));
                $candidate = $response['data'];
            } else {
                $response = $client->request('GET', '/products?sku=' . rawurlencode($operation['sku']) . '&limit=2');
                $candidate = $response['data'][0] ?? array();
            }
            $origin = $candidate['attributes']['wordpressOrigin'] ?? array();
            if (self::owned($candidate, $id) && ($origin['operationId'] ?? '') === $operation['id']) {
                self::success($id, $token, $operation, $candidate['id']);
                return true;
            }
        } catch (VilaHub_Api_Exception $error) {
            // Keep the original operation; a failed lookup never authorizes a new POST.
        }
        return false;
    }

    private static function owned(array $remote, int $id): bool {
        $origin = $remote['attributes']['wordpressOrigin'] ?? array();
        return ($origin['siteId'] ?? '') === get_option('vilahub_site_id') && (int) ($origin['productId'] ?? 0) === $id;
    }

    private static function success(int $id, string $token, array $operation, string $remote_id): void {
        $current = VilaHub_Store::row($id);
        VilaHub_Store::write($id, $token, array(
            'remote_id' => $remote_id, 'candidate_id' => '', 'last_payload' => VilaHub_Store::json($operation['snapshot']),
            'operation' => null, 'status' => !empty($current['dirty']) ? 'QUEUED' : 'SYNCED', 'attempts' => 0,
            'retry_at' => null, 'error_code' => '', 'error_message' => '', 'request_id' => '', 'synced_at' => gmdate('Y-m-d H:i:s')
        ));
    }

    private static function failure(int $id, string $token, VilaHub_Api_Exception $error, bool $version_conflict = false): void {
        $row = VilaHub_Store::row($id);
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $retryable = $version_conflict || $error->status === 0 || $error->status >= 500 || in_array($error->status, array(408, 429), true) || $error->api_code === 'OPERATION_IN_PROGRESS';
        $retry = $retryable && $attempts < 10;
        $delay = max($error->retry_after, min(3600, 15 * (2 ** min($attempts - 1, 8)))) + wp_rand(1, 5);
        VilaHub_Store::write($id, $token, array(
            'status' => $retry ? 'RETRY' : (($retryable && !empty($row['operation'])) ? 'UNCERTAIN' : 'ERROR'),
            'attempts' => $attempts, 'retry_at' => $retry ? gmdate('Y-m-d H:i:s', time() + $delay) : null,
            'error_code' => $error->api_code, 'error_message' => $error->getMessage(), 'request_id' => $error->request_id
        ));
    }

    public static function retry(int $id): void {
        global $wpdb;
        $row = VilaHub_Store::row($id);
        if (!$row || $row['status'] === 'NEEDS_LINK') { throw new RuntimeException('Confirme o vínculo do produto antes de enviá-lo.'); }
        $safe_validation_errors = array('INVALID_FIELD', 'EMPTY_PATCH', 'UNKNOWN_FIELDS', 'RESERVED_ATTRIBUTE', 'REQUIRED_FIELD', 'INVALID_VARIATIONS', 'DUPLICATE_VARIATION_SKU', 'PAYLOAD_TOO_LARGE', 'INVALID_IMAGE', 'INVALID_API_KEY', 'UNAUTHORIZED', 'CREDENTIAL_OWNER_UNAUTHORIZED', 'INSUFFICIENT_SCOPE', 'PAYMENT_REQUIRED');
        if ($row['status'] === 'ERROR' && in_array($row['error_code'], $safe_validation_errors, true)) {
            // These API rejections happen before the domain mutation. Corrected
            // data or credentials may start a new operation after explicit retry.
            $wpdb->update(VilaHub_Store::table(), array('operation' => null), array('product_id' => $id));
        }
        // Never discard a pending operation merely because the user clicked retry.
        $wpdb->update(VilaHub_Store::table(), array('status' => 'QUEUED', 'attempts' => 0, 'retry_at' => null, 'force_sync' => 1), array('product_id' => $id));
        self::enqueue($id, true);
    }

    public static function bind(int $id): void {
        $token = VilaHub_Store::lock($id);
        if (!$token) { throw new RuntimeException('Este produto está em processamento. Aguarde.'); }
        try {
            $row = VilaHub_Store::row($id);
            if (!$row || $row['status'] !== 'NEEDS_LINK' || !$row['candidate_id']) { throw new RuntimeException('Não há um vínculo pendente para este produto.'); }
            $payload = VilaHub_Product::payload($id);
            $remote = (new VilaHub_Client())->request('GET', '/products/' . rawurlencode($row['candidate_id']));
            if (($remote['data']['sku'] ?? '') !== $payload['sku']) { throw new RuntimeException('O SKU remoto mudou. Confira os dois cadastros antes de vincular.'); }
            VilaHub_Store::write($id, $token, array('remote_id' => $row['candidate_id'], 'candidate_id' => '', 'status' => 'QUEUED', 'force_sync' => 1, 'last_payload' => null, 'operation' => null, 'error_code' => '', 'error_message' => ''));
        } finally { VilaHub_Store::unlock($id, $token); }
        self::enqueue($id, true);
    }
}
