<?php
defined('ABSPATH') || exit;

// GET values in this controller are sanitized display filters or OAuth callback
// values protected by a single-use state. Mutations use WordPress nonces; the
// stricter NonceVerification.Missing rule remains enabled.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class VilaHub_Marketplace {
    const SCOPES = array('connections:read','connections:manage','publications:read','publications:write');
    public static function boot(): void {
        add_action('admin_menu', function () {
            add_menu_page('VilaHub', 'VilaHub', 'manage_options', 'vilahub', array(__CLASS__, 'render'), 'dashicons-store', 56);
            add_submenu_page('vilahub', 'Início do VilaHub', 'Início', 'manage_options', 'vilahub', array(__CLASS__, 'render'));
            add_submenu_page('vilahub', 'Conectar Canal de Venda', 'Conectar Canal de Venda', 'manage_options', 'vilahub-channels', array(__CLASS__, 'render'));
            add_submenu_page('vilahub', 'Minhas Integrações', 'Minhas Integrações', 'manage_options', 'vilahub-integrations', array(__CLASS__, 'render'));
            add_submenu_page('vilahub', 'Produtos publicados', 'Produtos publicados', 'manage_options', 'vilahub-publications', array(__CLASS__, 'render'));
        }, 20);
        add_action('wp_ajax_vilahub_restrictions', array(__CLASS__, 'restrictions_ajax'));
        add_action('admin_post_vilahub_marketplace', array(__CLASS__, 'handle'));
        add_action('admin_post_vilahub_channel_callback', array(__CLASS__, 'callback'));
        add_action('admin_post_nopriv_vilahub_channel_callback', function () {
            wp_die('Sua sessão do WordPress terminou. Entre novamente e confira a conta em VilaHub → Minhas Integrações antes de reiniciar a autorização.', '', array('response'=>403));
        });
        add_filter('bulk_actions-edit-product', function ($actions) {
            if (current_user_can('manage_options')) { $actions['vilahub_publish'] = 'Enviar para marketplace'; }
            return $actions;
        });
        add_action('restrict_manage_posts', function ($post_type = '', $which = 'top') {
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'product' || $which !== 'top' || !current_user_can('manage_options')) { return; }
            self::tag_filter();
            self::destination_selector();
        }, 10, 2);
        add_action('admin_notices', array(__CLASS__, 'destination_notice'));
        add_filter('handle_bulk_actions-edit-product', array(__CLASS__, 'bulk'), 10, 3);
        add_filter('manage_edit-product_columns', function ($columns) { $columns['vilahub'] = 'Restrições'; return $columns; });
        add_action('manage_product_posts_custom_column', function ($column, $id) {
            if ($column !== 'vilahub' || !current_user_can('manage_options')) { return; }
            self::product_column((int) $id);
        }, 10, 2);
    }
    public static function label(string $status): string {
        $labels = array('QUEUED'=>'Na fila de envio','WAITING'=>'Preparando envio','REVIEW'=>'Conferir pendência','COMPLETED'=>'Envio encaminhado; confira o resultado',
            'SYNCED'=>'Catálogo sincronizado','ERROR'=>'Corrigir cadastro','UNCERTAIN'=>'Conferir resultado','NEEDS_LINK'=>'Confirmar vínculo','SKIPPED'=>'Não enviado',
            'READY'=>'Pronto','NEEDS_ATTRIBUTES'=>'Atributos pendentes','NEEDS_REVIEW'=>'Revisão necessária','CLASSIFYING'=>'Analisando categoria','RUNNING'=>'Processando','RETRY'=>'Nova tentativa agendada','PENDING'=>'Pendente','SENT'=>'Enviado ao canal','SUCCEEDED'=>'Concluído','FAILED'=>'Falhou','REJECTED'=>'Recusado','PAUSED'=>'Pausado','CANCELED'=>'Cancelado','AWAITING_APPROVAL'=>'Aguardando aprovação','RETRYING'=>'Tentando novamente');
        return $labels[$status] ?? $status;
    }
    public static function product_column(int $id): void {
        echo '<div class="vilahub-restrictions" data-product-id="' . esc_attr((string) $id) . '">Não verificado</div>';
    }
    public static function restrictions_ajax(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message'=>'Sem permissão.'),403); }
        check_ajax_referer('vilahub_restrictions');
        try {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_values(array_unique(array_map('absint', wp_unslash($_POST['ids'])))) : array();
            if (!$ids || count($ids)>50 || in_array(0,$ids,true)) { throw new RuntimeException('Seleção inválida.'); }
            $products = array();
            foreach ($ids as $id) {
                if (!current_user_can('edit_post',$id)) { throw new RuntimeException('Produto sem permissão.'); }
                $products[] = VilaHub_Product::restriction_snapshot($id);
            }
            $results = array(); $batch = array(); $bytes = 0;
            foreach ($products as $product) {
                $size = strlen(wp_json_encode($product));
                if ($batch && $bytes+$size>180000) { $results = array_merge($results,self::request('POST','/restrictions/preview',array('products'=>$batch))); $batch=array(); $bytes=0; }
                $batch[]=$product; $bytes+=$size;
            }
            if ($batch) { $results=array_merge($results,self::request('POST','/restrictions/preview',array('products'=>$batch))); }
            wp_send_json_success($results);
        } catch (Throwable $exception) { wp_send_json_error(array('message'=>'Não foi possível verificar as restrições. Tente novamente atualizando a página.'),502); }
    }
    public static function url(string $section = ''): string { return admin_url('admin.php?page=vilahub' . ($section ? '-' . $section : '')); }
    public static function accounts(): array {
        static $accounts = null;
        if ($accounts === null) { $accounts = self::request('GET', '/connections'); }
        return $accounts;
    }
    public static function can_send(array $account): bool { return !empty($account['productPublication']) && !empty($account['active']) && $account['integrationStatus']==='READY' && !empty($account['pricingConfigured']); }
    public static function account_action(string $id): string { return 'vilahub_publish_' . substr(hash('sha256', $id), 0, 16); }
    public static function tag_filter(): void {
        $selected = sanitize_title(wp_unslash($_GET['product_tag'] ?? ''));
        echo '<label class="screen-reader-text" for="vilahub-product-tag">Filtrar produtos por tag</label>';
        wp_dropdown_categories(array('show_option_all'=>'Todas as tags','taxonomy'=>'product_tag','name'=>'product_tag','id'=>'vilahub-product-tag','orderby'=>'name','order'=>'ASC','selected'=>$selected,'hierarchical'=>false,'hide_empty'=>false,'value_field'=>'slug'));
    }
    public static function destination_selector(): void {
        $context = sanitize_text_field(wp_unslash($_GET['vilahub_connection'] ?? ''));
        try {
            echo '<label class="screen-reader-text" for="vilahub-destination">Integração de destino</label><select id="vilahub-destination" name="vilahub_connection"><option value="">Escolha uma integração</option>';
            foreach (self::accounts() as $account) { if (self::can_send($account)) { echo '<option value="' . esc_attr($account['id']) . '" ' . selected($context,$account['id'],false) . '>' . esc_html(self::destination_name($account)) . '</option>'; } }
            echo '</select>';
        } catch (Throwable $ignored) { echo '</select><a class="button" href="' . esc_url(self::url('integrations')) . '">Conectar integração</a>'; }
    }
    public static function destination_name(array $account): string {
        $names = array('MERCADOLIVRE'=>'Mercado Livre','SHOPEE'=>'Shopee','MAGALU'=>'Magalu','MEDUSA'=>'VilaBrasil','TIKTOK'=>'TikTok Shop','AMAZON'=>'Amazon','NETSHOES'=>'Netshoes / Zattini','MADEIRAMADEIRA'=>'MadeiraMadeira','AMERICANAS'=>'Americanas / SkyHub','LEROYMERLIN'=>'Leroy Merlin','SHEIN'=>'SHEIN');
        return ($names[$account['channel']] ?? $account['channel']) . ' — ' . $account['name'];
    }
    public static function destination_notice(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-product' || !current_user_can('manage_options')) { return; }
        $context = sanitize_text_field(wp_unslash($_GET['vilahub_connection'] ?? ''));
        try {
            $ready = array_filter(self::accounts(), array(__CLASS__, 'can_send'));
            if ($context) {
                foreach ($ready as $account) { if ($account['id'] === $context) {
                    echo '<div class="notice notice-info"><p><strong>Destino: ' . esc_html(self::destination_name($account)) . '</strong></p><p>Filtre por tag, selecione os produtos, escolha Enviar para marketplace e clique em Aplicar. <a href="' . esc_url(self::url('integrations')) . '">Trocar integração</a></p></div>'; return;
                } }
                throw new RuntimeException('Esta conta não está pronta para envio. Confira a autorização e o preço em Minhas Integrações.');
            }
            if ($ready) {
                echo '<div class="notice notice-warning"><p><strong>Envie produtos para um marketplace.</strong> Filtre por tag, selecione os produtos, escolha Enviar para marketplace em Ações em massa, escolha a conta e clique em Aplicar. <a href="' . esc_url(self::url('integrations')) . '">Minhas Integrações</a></p></div>'; return;
            }
            $message = 'Primeiro conecte sua conta no marketplace e configure os preços. Depois você poderá enviar os produtos para essa conta.';
        } catch (Throwable $error) { $message = $error->getMessage(); }
        echo '<div class="notice notice-warning"><p>' . esc_html($message) . ' <a href="' . esc_url(self::url('integrations')) . '">Abrir Minhas Integrações</a></p></div>';
    }
    public static function require_access(): void {
        if (array_diff(self::SCOPES, VilaHub_Store::settings()['scopes'])) { throw new RuntimeException('Abra Conheça o VilaHub e reconecte a integração para gerenciar contas e publicações.'); }
    }
    private static function request(string $method, string $path, ?array $body = null, ?string $key = null): array {
        self::require_access();
        return (new VilaHub_Client())->request($method, '/marketplace' . $path, $body, $method === 'GET' ? array() : array('Idempotency-Key' => $key ?? wp_generate_uuid4()))['data'];
    }
    public static function bulk(string $redirect, string $action, array $ids): string {
        if ($action !== 'vilahub_publish' && strpos($action,'vilahub_publish_') !== 0) { return $redirect; }
        if (!current_user_can('manage_options')) { return $redirect; }
        check_admin_referer('bulk-posts');
        try {
            if (!$ids || count($ids) > 1000) { throw new RuntimeException('Selecione de 1 a 1.000 produtos por envio. Use o filtro de tag para encontrar os produtos desejados.'); }
            foreach ($ids as $id) { if (!current_user_can('edit_post', $id) || get_post_type($id) !== 'product') { throw new RuntimeException('Seleção inválida.'); } }
            $top_action = sanitize_text_field(wp_unslash($_POST['action'] ?? $_GET['action'] ?? '-1'));
            $field = $top_action === '-1' && (isset($_POST['vilahub_connection_bottom']) || isset($_GET['vilahub_connection_bottom'])) ? 'vilahub_connection_bottom' : 'vilahub_connection';
            $context = sanitize_text_field(wp_unslash($_POST[$field] ?? $_GET[$field] ?? ''));
            if (!$context) { throw new RuntimeException('Escolha uma integração na lista de produtos antes de enviar.'); }
            $connection = '';
            foreach (self::accounts() as $account) {
                if ($account['id'] === $context && self::can_send($account) && ($action === 'vilahub_publish' || self::account_action($account['id']) === $action)) { $connection = $account['id']; break; }
            }
            if (!$connection) { throw new RuntimeException('A integração escolhida não corresponde à ação de envio ou não está pronta. Escolha novamente a conta.'); }
            $selection = wp_generate_uuid4();
            set_transient('vilahub_selection_' . get_current_user_id() . '_' . $selection, array_map('absint', $ids), 1800);
            return self::url('publications') . '&selection=' . rawurlencode($selection) . ($connection ? '&connection_id=' . rawurlencode($connection) : '');
        } catch (Throwable $error) { self::notice($error->getMessage(), true); return self::url('publications'); }
    }
    private static function notice(string $text, bool $error = false): void { set_transient('vilahub_notice_' . get_current_user_id(), array('message'=>$text,'error'=>$error), 60); }
    private static function provider_redirect(string $url): void {
        $allow_vilahub=static function(array $hosts): array {$hosts[]='vilahub.com.br';return array_values(array_unique($hosts));};
        add_filter('allowed_redirect_hosts',$allow_vilahub);
        wp_safe_redirect($url,303,'VilaHub');
        remove_filter('allowed_redirect_hosts',$allow_vilahub);
        exit;
    }
    private static function form(string $action): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_marketplace"><input type="hidden" name="operation" value="' . esc_attr($action) . '">';
        wp_nonce_field('vilahub_marketplace');
        echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
    }
    public static function handle(): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso restrito.', '', array('response'=>403)); }
        check_admin_referer('vilahub_marketplace');
        $section = 'publications';
        try {
            $input = wp_unslash($_POST); $operation = sanitize_key($input['operation'] ?? '');
            $connection = sanitize_text_field($input['connection_id'] ?? '');
            if ($operation === 'plan') {
                $section = '';
                if (!in_array('plans:manage',VilaHub_Store::settings()['scopes'],true)) { throw new RuntimeException('Reconecte a integração em Conheça o VilaHub para autorizar a escolha do plano.'); }
                self::request('POST','/plan',array('plan'=>sanitize_text_field($input['plan']??''),'acceptPaidAfterTrial'=>!empty($input['accept_paid'])),sanitize_text_field($input['request_key']??''));
                self::notice('Plano atualizado no VilaHub. Os limites são conferidos pelo servidor e valem para a empresa inteira.');
            } elseif ($operation === 'published-retry') {
                if (empty($input['confirm_retry'])) { throw new RuntimeException('Confirme o reenvio após corrigir o produto.'); }
                $remote = sanitize_text_field($input['remote_id'] ?? '');
                global $wpdb;
                // Resolve the fresh plugin-owned mapping before retrying publication.
                $local = (int)$wpdb->get_var($wpdb->prepare('SELECT product_id FROM %i WHERE tenant_id=%s AND remote_id=%s',VilaHub_Store::table(),VilaHub_Store::settings()['tenant_id'],$remote)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($local) { VilaHub_Publications::start($connection,array($local),false,sanitize_text_field($input['request_key']??'')); }
                else { self::request('POST','/publications',array('connectionId'=>$connection,'productIds'=>array($remote),'name'=>'WooCommerce retry:' . sanitize_text_field($input['request_key']??'')),sanitize_text_field($input['request_key']??'')); }
                self::notice('Reenvio solicitado para a conta selecionada. Acompanhe o resultado nesta lista.');
                wp_safe_redirect(self::url('publications') . '&connection_id=' . rawurlencode($connection),303); exit;
            } elseif ($operation === 'prepare-account') {
                $remote=sanitize_text_field($input['remote_id']??'');
                self::request('POST','/products/' . rawurlencode($remote) . '/preparation',array('connectionId'=>$connection),sanitize_text_field($input['request_key']??''));
                self::notice('Preparação iniciada para esta conta. Aguarde a análise e atualize a página; nenhum anúncio foi publicado por esta ação.');
                $local=absint($input['product_id']??0);
                if ($local && (!current_user_can('edit_post',$local) || (VilaHub_Store::row($local)['remote_id']??'')!==$remote)) { $local=0; }
                wp_safe_redirect(self::url('publications') . ($local ? '&product=' . $local : '&remote_product=' . rawurlencode($remote)) . '&connection_id=' . rawurlencode($connection),303); exit;
            } elseif ($operation === 'published-correct') {
                $remote = sanitize_text_field($input['remote_id']??'');
                $body=array('title'=>sanitize_text_field($input['title']??''),'brand'=>sanitize_text_field($input['brand']??''),'gtin'=>sanitize_text_field($input['gtin']??''),'description'=>sanitize_textarea_field($input['description']??''));
                (new VilaHub_Client())->request('PATCH','/products/'.rawurlencode($remote),$body,array('If-Match'=>sanitize_text_field($input['etag']??''),'Idempotency-Key'=>sanitize_text_field($input['request_key']??'')));
                self::notice('Correções salvas. Volte à lista da conta para reenviar o produto.');
                wp_safe_redirect(self::url('publications') . '&remote_product=' . rawurlencode($remote) . '&connection_id=' . rawurlencode($connection),303); exit;
            } elseif ($operation === 'connect') {
                $section = 'channels';
                $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $body = array('channel'=>sanitize_text_field($input['channel'] ?? ''), 'name'=>sanitize_text_field($input['name'] ?? ''), 'state'=>$state);
                if ($connection) { $body['connectionId'] = $connection; }
                $result = self::request('POST', '/connections/authorize', $body);
                $url = (string) ($result['authorizationUrl'] ?? '');
                if (!preg_match('~^https://vilahub\.com\.br/api/oauth/channels/[A-Za-z0-9_-]{43}$~', $url)) { throw new RuntimeException('Endereço de autorização não confirmado.'); }
                set_transient('vilahub_channel_' . hash('sha256', $state), array('user'=>get_current_user_id(), 'origin'=>untrailingslashit(home_url()), 'tenant'=>VilaHub_Store::settings()['tenant_id']), 600);
                // The URL is restricted by the exact VilaHub channel authorization pattern above.
                self::provider_redirect($url);
            } elseif ($operation === 'channel-settings') {
                $section = 'integrations'; $channel = sanitize_text_field($input['channel'] ?? '');
                $body = array('name'=>sanitize_text_field($input['name'] ?? ''),'cnpj'=>sanitize_text_field($input['cnpj'] ?? ''),'active'=>!empty($input['active']));
                if ($connection) { $body['connectionId']=$connection; }
                foreach (array('externalShopId','listingTypeId','warehouseId','anatelCode','anatelCompany','shippingProfileId','stockLocationId') as $field) { if(isset($input[$field])) { $body[$field]=sanitize_text_field($input[$field]); } }
                foreach (array('accessToken','refreshToken') as $field) {
                    if(!array_key_exists($field,$input)||$input[$field]==='')continue;
                    if(!is_string($input[$field])||strlen($input[$field])>8192)throw new RuntimeException('A credencial informada para o canal é inválida.');
                    $body[$field]=trim($input[$field]);
                }
                foreach (array('exportBatchSize','exportIntervalMs','exportConcurrency') as $field) { if(isset($input[$field])) { $body[$field]=absint($input[$field]); } }
                self::request('POST','/connections/'.rawurlencode($channel).'/settings',$body);
                self::notice('Configuração salva. Use Testar conexão antes de publicar com novas credenciais.');
            } elseif ($operation === 'channel-test') {
                $section = 'integrations'; self::request('POST','/connections/'.rawurlencode($connection).'/test',array());
                self::notice('Conexão testada. Confira o estado do canal abaixo.');
            } elseif ($operation === 'pricing') {
                $section = 'integrations';
                self::request('PATCH', '/connections/' . rawurlencode($connection) . '/pricing', array('pricingMode'=>sanitize_text_field($input['pricing_mode'] ?? ''), 'markupValue'=>(float) ($input['markup'] ?? ''), 'priceRounding'=>sanitize_text_field($input['rounding'] ?? '')));
                self::notice('Regra de preço salva. Ela será aplicada aos próximos envios.');
            } elseif ($operation === 'publish') {
                $selection = sanitize_text_field($input['selection'] ?? '');
                $ids = get_transient('vilahub_selection_' . get_current_user_id() . '_' . $selection);
                if (!is_array($ids) || !$ids) { throw new RuntimeException('A seleção expirou. Selecione os produtos novamente.'); }
                if (empty($input['confirm_publish'])) { throw new RuntimeException('Confirme o destino antes de iniciar a publicação.'); }
                $id = VilaHub_Publications::start($connection, $ids, false, sanitize_text_field($input['request_key'] ?? ''));
                self::notice('Envio agendado. Os produtos serão preparados automaticamente e enviados ao marketplace escolhido. Acompanhe o resultado abaixo.');
            }
            elseif ($operation === 'attributes') {
                $id = absint($input['product_id'] ?? 0);
                if ($id && !current_user_can('edit_post', $id)) { throw new RuntimeException('Produto não permitido.'); }
                $remote = sanitize_text_field($input['remote_id'] ?? '');
                $row = $id ? VilaHub_Store::row($id) : array('remote_id'=>$remote);
                if (!$row || !$row['remote_id']) { throw new RuntimeException('Sincronize o produto antes de revisar os atributos.'); }
                $values = array();
                foreach ((array) ($input['attribute'] ?? array()) as $key=>$value) { if (preg_match('/^[A-Z0-9_]{1,100}$/', (string) $key)) { $values[$key] = sanitize_text_field($value); } }
                self::request('PATCH', '/products/' . rawurlencode($row['remote_id']) . '/preparation/' . rawurlencode(sanitize_text_field($input['channel'] ?? 'MERCADOLIVRE')), array('externalCategoryId'=>sanitize_text_field($input['category'] ?? ''), 'externalCategoryName'=>VilaHub_Product::truncate(sanitize_text_field($input['category_name']??''),200), 'attributeValues'=>$values, ...($connection ? array('connectionId'=>$connection) : array())));
                self::notice('Dados confirmados. A preparação será reavaliada pelo conector.');
                wp_safe_redirect(self::url('publications') . ($id ? '&product=' . $id : '&remote_product=' . rawurlencode($remote)) . '&connection_id=' . rawurlencode($connection), 303); exit;
            } else { throw new RuntimeException('Operação inválida.'); }
        } catch (Throwable $error) { self::notice(VilaHub_Product::truncate($error->getMessage(), 700), true); }
        wp_safe_redirect(self::url($section), 303); exit;
    }
    public static function callback(): void {
        nocache_headers(); header('Referrer-Policy: no-referrer');
        try {
            if (!current_user_can('manage_options')) { throw new RuntimeException('Entre como administrador e confira a conexão nos canais.'); }
            // OAuth state is the callback's single-use CSRF token; a WordPress nonce cannot cross this redirect.
            $state = sanitize_text_field(wp_unslash($_GET['state'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $issuer = esc_url_raw(wp_unslash($_GET['iss'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $state) || $issuer !== 'https://vilahub.com.br') { throw new RuntimeException('Retorno não reconhecido.'); }
            $key = 'vilahub_channel_' . hash('sha256', $state); $pending = get_transient($key);
            if (!$pending || $pending['user'] !== get_current_user_id() || $pending['origin'] !== untrailingslashit(home_url()) || $pending['tenant'] !== VilaHub_Store::settings()['tenant_id']) { throw new RuntimeException('O retorno expirou ou pertence a outra sessão. Confira o canal antes de reconectar.'); }
            delete_transient($key);
            $result = sanitize_key(wp_unslash($_GET['result'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $reason = sanitize_key(wp_unslash($_GET['reason'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($result !== 'connected') { throw new RuntimeException($reason==='provider_configuration'?'O início da autorização não foi concluído. O aplicativo ou ambiente deste canal pode precisar de configuração no servidor VilaHub; contate o suporte antes de repetir.':'O marketplace não confirmou a autorização. Confira a conta e tente novamente.'); }
            self::notice('Autorização recebida. Confira a situação da conta e salve a regra de preço abaixo.');
        } catch (Throwable $error) { self::notice($error->getMessage(), true); }
        wp_safe_redirect(self::url('integrations'), 303); exit;
    }
    public static function navigation(string $page): void {
        echo '<nav class="nav-tab-wrapper" aria-label="Seções do VilaHub">';
        foreach (array(''=>'Conheça o VilaHub','channels'=>'Conectar Canal de Venda','integrations'=>'Minhas Integrações','publications'=>'Produtos publicados','orders'=>'Pedidos','fiscal'=>'Fiscal','billing'=>'Financeiro') as $slug=>$name) {
            $active = $page === 'vilahub' . ($slug ? '-' . $slug : '');
            $url=$slug==='orders'?VilaHub_Order_Admin::list_url():($slug==='fiscal'?VilaHub_Order_Admin::fiscal_url():($slug==='billing'?VilaHub_Order_Admin::billing_url():self::url($slug)));echo '<a class="nav-tab' . ($active ? ' nav-tab-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . ' href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
        }
        echo '</nav>';
        self::support();
    }
    public static function support(): void {
        echo '<div class="vilahub-support"><a class="vilahub-whatsapp" href="https://wa.me/5545999135339?text=Ol%C3%A1%21%20Quero%20conversar%20sobre%20o%20plugin%20VilaHub%20para%20WooCommerce." target="_blank" rel="noopener noreferrer" aria-label="Fale sobre o plugin no WhatsApp (abre em nova aba)"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20.52 3.48A11.91 11.91 0 0 0 12.05 0C5.46 0 .1 5.36.1 11.95c0 2.11.55 4.17 1.6 5.99L0 24l6.23-1.63a11.94 11.94 0 0 0 5.82 1.49h.01c6.59 0 11.95-5.36 11.95-11.95a11.88 11.88 0 0 0-3.49-8.43ZM12.06 21.84h-.01a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.7.97.99-3.61-.24-.37a9.88 9.88 0 0 1-1.51-5.29c0-5.48 4.46-9.94 9.95-9.94a9.87 9.87 0 0 1 7.03 2.92 9.87 9.87 0 0 1 2.91 7.03c0 5.48-4.46 9.94-9.94 9.94Zm5.45-7.44c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.49s1.07 2.89 1.22 3.09c.15.2 2.11 3.22 5.11 4.51.71.31 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg><span>Fale sobre o plugin</span><span class="screen-reader-text"> — WhatsApp: (45) 99913-5339</span></a></div>';
        VilaHub_Support::widget();

    }
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $page = sanitize_key($_GET['page'] ?? 'vilahub');
        echo '<div class="wrap vilahub-admin"><h1>VilaHub</h1>';
        self::navigation($page);
        $notice = get_transient('vilahub_notice_' . get_current_user_id()); delete_transient('vilahub_notice_' . get_current_user_id());
        if ($notice) { echo '<div class="notice ' . ($notice['error']?'notice-error':'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
        if ($page === 'vilahub') { VilaHub_Welcome::render(); echo '</div>'; return; }
        if (!get_option('vilahub_api_secret')) {
            echo '<section class="vilahub-card"><h2>Comece a vender nos marketplaces</h2><p>Crie o acesso da sua loja ou entre na conta que você já possui. Depois conecte o marketplace, escolha os produtos e envie pelo WooCommerce.</p><p>A conexão é operada pelo serviço VilaHub. O cadastro e a confirmação por e-mail acontecem em uma tela própria, com retorno para esta loja.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_action"><input type="hidden" name="vilahub_do" value="connect">';
            wp_nonce_field('vilahub_admin_action'); submit_button('Começar'); echo '</form></section></div>'; return;
        }
        try {
            self::require_access();
            if ($page === 'vilahub-channels') { self::channels(true); }
            elseif ($page === 'vilahub-publications') { self::publications(); }
            else { self::channels(false); }
        } catch (Throwable $error) { echo '<div class="notice notice-warning"><p>' . esc_html($error->getMessage()) . '</p><p><a href="' . esc_url(self::url() . '#vilahub-connection') . '">Revisar conexão da loja</a></p></div>'; }
        echo '</div>';
    }
    private static function channels(bool $connect = true): void {
        $providers=self::request('GET','/providers');
        if ($connect) {
            echo '<section class="vilahub-card"><h2>Conectar Canal de Venda</h2><p>Escolha um marketplace para conectar sua conta. As autorizações abrem a página oficial e retornam para Minhas Integrações. Canais que usam credenciais apresentam os campos de conexão.</p></section><div class="vilahub-channel-grid">';
            $logos = array('MERCADOLIVRE'=>'mercadolivre.svg','SHOPEE'=>'shopee.svg','MAGALU'=>'magalu.png','AMAZON'=>'amazon.svg','TIKTOK'=>'tiktok-shop.svg','MEDUSA'=>'vila-brasil.svg','SHEIN'=>'shein.svg','NETSHOES'=>'netshoes.svg','MADEIRAMADEIRA'=>'madeiramadeira.svg','AMERICANAS'=>'americanas.svg','LEROYMERLIN'=>'leroy-merlin.svg');
            foreach ($providers as $provider) {
                $logo = $logos[$provider['code']] ?? '';
                $image = $logo ? '<img src="' . esc_url(plugins_url('assets/marketplaces/' . $logo, VILAHUB_WC_FILE)) . '" alt="" width="160" height="110">' : '';
                if ($provider['mode'] === 'OAUTH') {
                    echo '<article class="vilahub-channel-option">'; self::form('connect');
                    echo '<input type="hidden" name="channel" value="' . esc_attr($provider['code']) . '"><input type="hidden" name="name" value="' . esc_attr(VilaHub_Product::truncate($provider['name'] . ' — ' . get_bloginfo('name'),100)) . '"><button class="vilahub-channel-choice" type="submit">' . wp_kses_post($image) . '<strong>' . esc_html($provider['name']) . '</strong><span>Conectar minha conta →</span></button></form></article>';
                } else {
                    echo '<details class="vilahub-channel-option"><summary class="vilahub-channel-choice">' . wp_kses_post($image) . '<strong>' . esc_html($provider['name']) . '</strong><span>Conectar com credenciais →</span></summary><div class="vilahub-channel-fields">';
                    self::settings_form($provider,null); echo '</div></details>';
                }
            }
            echo '</div>'; return;
        }
        $channels=self::request('GET','/connections');
        echo '<section class="vilahub-card"><h2>Minhas Integrações</h2><p>Gerencie as contas conectadas, configure os preços e envie seus produtos para a conta desejada.</p></section>';
        if (!$channels) { echo '<section class="vilahub-card"><h2>Você ainda não tem contas conectadas</h2><p>Abra Conectar Canal de Venda para escolher seu primeiro marketplace.</p><a class="button button-primary" href="' . esc_url(self::url('channels')) . '">Conectar Canal de Venda</a></section>'; return; }
        $card_index = 0;
        foreach($channels as $channel) {
            $provider=null; foreach($providers as $item) { if($item['code']===$channel['channel']) { $provider=$item; break; } }
            if(!$provider) { continue; }
            $connection_status = array('READY'=>'Conectada','NOT_CONFIGURED'=>'Configuração pendente','ERROR'=>'Precisa de atenção','PENDING'=>'Aguardando autorização');
            $tone = $card_index++ % 12;
            echo '<section class="vilahub-card vilahub-integration vilahub-integration-tone-' . absint($tone) . '"><header class="vilahub-integration-heading"><div><span class="vilahub-integration-marketplace">' . esc_html($provider['name']) . '</span><h2>' . esc_html($channel['name']) . '</h2></div><span class="vilahub-integration-status">' . esc_html($connection_status[$channel['integrationStatus']] ?? 'Confira a conexão') . ' · ' . ($channel['active']?'Ativa':'Inativa') . '</span></header>';
            if(!empty($channel['lastErrorMessage'])) { echo '<p>'.esc_html($channel['lastErrorMessage']).'</p>'; }
            if (self::can_send($channel)) {
                echo '<p>Esta conta está pronta. Filtre e selecione os produtos que deseja enviar para <strong>' . esc_html(self::destination_name($channel)) . '</strong>.</p><p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=product') . '&vilahub_connection=' . rawurlencode($channel['id'])) . '">Enviar produtos</a></p>';
            } elseif (!empty($channel['productPublication'])) { echo '<p>Antes de enviar, autorize esta conta, mantenha-a ativa e salve a regra de preço nas opções abaixo.</p>'; }
            else { echo '<p>Esta integração permite configurar e testar a conta; o envio de novos produtos ainda não está disponível.</p>'; }
            echo '<details' . (!self::can_send($channel) ? ' open' : '') . '><summary>Configurações da conta e markup</summary>';
            if($provider['mode']==='OAUTH') { self::form('connect'); echo '<input type="hidden" name="channel" value="'.esc_attr($provider['code']).'"><input type="hidden" name="connection_id" value="'.esc_attr($channel['id']).'">'; submit_button('Reconectar esta conta','secondary'); echo '</form>'; }
            self::settings_form($provider,$channel);
            self::form('channel-test'); echo '<input type="hidden" name="connection_id" value="'.esc_attr($channel['id']).'">'; submit_button('Testar conexão','secondary'); echo '</form>';
            if(!empty($channel['pricingRules'])) {
                self::form('pricing'); echo '<input type="hidden" name="connection_id" value="'.esc_attr($channel['id']).'"><p><label>Método <select name="pricing_mode">';
                foreach(array('PERCENTAGE'=>'Percentual sobre o preço WooCommerce','MULTIPLIER'=>'Multiplicador do preço WooCommerce') as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($channel['pricingMode'],$value,false).'>'.esc_html($label).'</option>'; }
                echo '</select></label> <label>Valor <input type="number" name="markup" step="0.01" required value="'.esc_attr($channel['markupValue']).'"></label> <label>Arredondamento <select name="rounding">';
                foreach(array('NONE'=>'Sem arredondamento','END_90'=>'Final ,90','END_99'=>'Final ,99','INTEGER'=>'Reais inteiros') as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($channel['priceRounding'],$value,false).'>'.esc_html($label).'</option>'; }
                echo '</select></label></p><p>Percentual 0 mantém o preço-base; 20 acrescenta 20%. Multiplicador 1 mantém o preço-base. Confira taxas, frete e margem antes de confirmar.</p>'; submit_button('Salvar regra de preço'); echo '</form>';
            } else { echo '<p>Este conector não oferece publicação pelo fluxo de lotes atual. A configuração e o teste não habilitam operações que o provedor ou o conector ainda não liberaram.</p>'; }
            echo '</details></section>';
        }
    }
    private static function settings_form(array $provider, ?array $channel): void {
        self::form('channel-settings'); echo '<input type="hidden" name="channel" value="'.esc_attr($provider['code']).'">';
        if($channel) { echo '<input type="hidden" name="connection_id" value="'.esc_attr($channel['id']).'">'; }
        echo '<p><label>Nome da conta <input name="name" required maxlength="100" value="'.esc_attr($channel['name']??'').'"></label> <label>CNPJ/CPF, quando exigido <input name="cnpj" maxlength="20" value="'.esc_attr($channel['cnpj']??'').'"></label></p>';
        $labels=array('externalShopId'=>'ID do seller / loja','accessToken'=>'Token de acesso','refreshToken'=>'Token adicional','listingTypeId'=>'Tipo do anúncio Mercado Livre','warehouseId'=>'ID do depósito TikTok','anatelCode'=>'Código de homologação Anatel','anatelCompany'=>'Empresa homologadora Anatel','shippingProfileId'=>'Perfil de frete VilaBrasil','stockLocationId'=>'Local de estoque VilaBrasil');
        if($provider['code']==='NETSHOES') { $labels['accessToken']='Client ID'; $labels['refreshToken']='Access Token'; }
        if($provider['code']==='AMERICANAS') { $labels['externalShopId']='E-mail da conta SkyHub'; $labels['accessToken']='Chave da API SkyHub'; $labels['refreshToken']='Chave de plataforma SkyHub'; }
        foreach($provider['fields'] as $field) {
            $secret=in_array($field,array('accessToken','refreshToken'),true);
            $value=$secret?'':($field==='externalShopId'?($channel['externalShopId']??''):($channel['settings'][$field]??''));
            echo '<p><label>'.esc_html($labels[$field]??$field).' ';
            if($field==='listingTypeId') { echo '<select name="listingTypeId">'; foreach(array('gold_special'=>'Clássico','gold_pro'=>'Premium','free'=>'Grátis, quando permitido') as $key=>$title) { echo '<option value="'.esc_attr($key).'" '.selected($value?:'gold_special',$key,false).'>'.esc_html($title).'</option>'; } echo '</select>'; }
            else { echo '<input type="'.($secret?'password':'text').'" name="'.esc_attr($field).'" value="'.esc_attr($value).'" maxlength="'.($secret?'8192':'500').'" autocomplete="off" '.(!$channel&&$secret?'required':'').'>'; }
            echo '</label></p>';
        }
        if($provider['mode']==='KEY' && $channel) { echo '<p>Deixe os campos de credencial vazios para manter as chaves atuais. Elas não são exibidas novamente.</p>'; }
        echo '<details><summary>Ritmo de publicação</summary>';
        foreach(array('exportBatchSize'=>array('Produtos por bloco',20,1,100),'exportIntervalMs'=>array('Intervalo entre envios, em milissegundos',1000,100,60000),'exportConcurrency'=>array('Envios simultâneos',1,1,5)) as $field=>$meta) { echo '<p><label>'.esc_html($meta[0]).' <input type="number" name="'.esc_attr($field).'" value="'.absint($channel[$field]??$meta[1]).'" min="'.absint($meta[2]).'" max="'.absint($meta[3]).'" required></label></p>'; }
        echo '</details><p><label><input type="checkbox" name="active" value="1" '.checked($channel? !empty($channel['active']):true,true,false).'> Conta ativa</label></p>'; submit_button($channel?'Salvar configuração':'Salvar conta e credenciais'); echo '</form>';
    }
    private static function publications(): void {
        $remote = sanitize_text_field(wp_unslash($_GET['remote_product'] ?? ''));
        if ($remote) { VilaHub_Published_Products::correction($remote); self::product(0,$remote); return; }
        $product_id = absint($_GET['product'] ?? 0);
        if ($product_id) { self::product($product_id); return; }
        $selection = sanitize_text_field(wp_unslash($_GET['selection'] ?? ''));
        $ids = $selection ? get_transient('vilahub_selection_' . get_current_user_id() . '_' . $selection) : false;
        if ($selection && !is_array($ids)) { throw new RuntimeException('A seleção expirou. Selecione os produtos novamente.'); }
        if ($ids) {
        $channels = self::request('GET', '/connections');
        $selected_connection = sanitize_text_field(wp_unslash($_GET['connection_id'] ?? '')); $destination = null;
        echo '<section class="vilahub-card"><h2>Destino do envio</h2><form class="vilahub-destination" method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="vilahub-publications"><input type="hidden" name="selection" value="' . esc_attr($selection) . '">';
        echo '<label>Marketplace e conta <select name="connection_id" required><option value="">Selecione o destino</option>';
        foreach ($channels as $channel) {
            if (!$channel['productPublication'] || !$channel['active'] || $channel['integrationStatus']!=='READY' || !$channel['pricingConfigured']) { continue; }
            if ($channel['id']===$selected_connection) { $destination=$channel; }
            echo '<option value="' . esc_attr($channel['id']) . '" ' . selected($selected_connection,$channel['id'],false) . '>' . esc_html(self::destination_name($channel)) . '</option>';
        }
        echo '</select></label> <button class="button">Usar esta conta</button></form><p>Não encontrou sua conta? <a href="' . esc_url(self::url('channels')) . '">Conecte o marketplace e defina os preços</a>.</p></section>';
        echo '<section class="vilahub-card"><h2>Enviar ' . count($ids) . (count($ids)===1?' produto':' produtos') . '</h2>';
        $preview_ids = $ids;
        if ($destination) { echo '<p>Destino: <strong>' . esc_html(self::destination_name($destination)) . '</strong> · Regra: ' . esc_html(($destination['pricingMode']==='PERCENTAGE'?'Percentual ':'Multiplicador ') . $destination['markupValue']) . '</p>'; }
        if ($preview_ids) {
            echo '<details><summary><strong>Ver produtos e preços</strong></summary><table class="widefat"><thead><tr><th>Produto</th><th>SKU</th><th>Preço-base</th><th>Preço no canal</th><th>Estoque</th></tr></thead><tbody>';
            foreach (array_slice($preview_ids, 0, 100) as $id) { $product = wc_get_product($id); if (!$product) { continue; } echo '<tr><td>' . esc_html($product->get_name()) . '</td><td>' . esc_html($product->get_sku()) . '</td><td>' . wp_kses_post(wc_price($product->get_price())) . '</td><td>' . ($destination ? wp_kses_post(wc_price(self::price((float)$product->get_price(), $destination))) : 'Escolha o canal') . '</td><td>' . esc_html((string) $product->get_stock_quantity()) . '</td></tr>'; }
            echo '</tbody></table><p>Exibidos até 100 produtos; o envio inclui toda a seleção. Produtos variáveis seguem os preços de cada variação.</p></details>';
        }
        self::form('publish'); echo '<input type="hidden" name="selection" value="' . esc_attr($selection) . '">';
        echo '<input type="hidden" name="connection_id" value="' . esc_attr($destination['id'] ?? '') . '"><p>Clique em enviar para iniciar. O VilaHub processará os produtos em segundo plano e mostrará somente os itens que precisarem de correção.</p>';
        echo '<input type="hidden" name="confirm_publish" value="1">';
        submit_button($destination?'Enviar ' . count($ids) . ' produto(s) para ' . self::destination_name($destination):'Escolha uma conta conectada para enviar','primary','submit',true,$destination?array():array('disabled'=>'disabled')); echo '</form></section>';
        } else {
            VilaHub_Published_Products::render();
        }
    }
    private static function price(float $base, array $channel): float {
        $cents = round($base * 100);
        $cents = (int) round($channel['pricingMode']==='PERCENTAGE' ? $cents*(1+(float)$channel['markupValue']/100) : $cents*(float)$channel['markupValue']);
        if ($channel['priceRounding']==='INTEGER') { $cents=(int)round($cents/100)*100; }
        elseif ($channel['priceRounding']==='END_90') { $cents=max(0,(int)floor($cents/100)*100+90); }
        elseif ($channel['priceRounding']==='END_99') { $cents=max(0,(int)floor($cents/100)*100+99); }
        return $cents/100;
    }
    private static function product(int $id, string $remote = ''): void {
        if ($id && !current_user_can('edit_post', $id)) { throw new RuntimeException('Produto não permitido.'); }
        $row = $id ? VilaHub_Store::row($id) : array('remote_id'=>$remote);
        if (!$row || !$row['remote_id']) { throw new RuntimeException('O produto ainda não foi sincronizado. Confira a fila.'); }
        $context = sanitize_text_field(wp_unslash($_GET['connection_id'] ?? ''));
        $product = self::request('GET', '/products/' . rawurlencode($row['remote_id']) . ($context ? '?connectionId=' . rawurlencode($context) : ''));
        echo '<h2>' . esc_html($product['title'] . ' · ' . $product['sku']) . '</h2>';
        foreach ($product['listings'] as $listing) {
            if ($context && $listing['connectionId'] !== $context) { continue; }
            echo '<p>Anúncio ' . esc_html($listing['externalId'] ?? 'ainda sem confirmação') . ' · ' . ($listing['active']?'Vínculo ativo no VilaHub':'Vínculo não ativo no VilaHub') . '</p>';
            if (!empty($listing['lastError'])) { echo '<div class="notice notice-warning inline"><p><strong>Último aviso registrado para este anúncio:</strong> ' . esc_html($listing['lastError']) . '</p><p>Esta mensagem se refere à validação ou sincronização deste produto. Ela não confirma a situação atual da loja. Confira o anúncio e a conta no portal do marketplace antes de alterar a conexão.</p></div>'; }
        }
        $current_category=$product['currentCategory']??null;
        if (is_array($current_category)) {
            echo '<section class="vilahub-card vilahub-current-category"><h3>Categoria atual do anúncio no Mercado Livre</h3>';
            if (($current_category['status']??'')==='FOUND') {
                echo '<p><strong>' . esc_html($current_category['path']?:$current_category['name']) . '</strong></p><p>Código: ' . esc_html($current_category['id']) . ' · Anúncio: ' . esc_html($current_category['externalId']) . '</p><p>Consultada diretamente no Mercado Livre para esta conta. Esta consulta não altera o anúncio.</p>';
            }
            if (!empty($current_category['message'])) { echo '<p role="status">' . esc_html($current_category['message']) . '</p>'; }
            echo '</section>';
        }
        $preparations = $product['marketplacePreparations'] ?? array();

        $context = sanitize_text_field(wp_unslash($_GET['connection_id'] ?? ''));
        if ($context) {
            $account = null;
            foreach (self::accounts() as $candidate) { if ($candidate['id'] === $context) { $account=$candidate; break; } }
            if (!$account) { throw new RuntimeException('Conta de destino indisponível. Volte à lista e selecione a conta.'); }
            echo '<p><strong>Conta em revisão: ' . esc_html(self::destination_name($account)) . '</strong></p>';
            $preparations=array_filter($preparations,fn($prep)=>$prep['channel']===$account['channel'] && ($prep['connectionId']??'')===$context);
            if (!$preparations) {
                if (in_array($account['channel'],array('MERCADOLIVRE','SHOPEE'),true)) {
                    echo '<section class="vilahub-card"><h3>Categoria e atributos desta conta</h3><p>Este anúncio ainda não tem preparação nesta conta. Inicie a análise para consultar a categoria sugerida e os atributos exigidos. Preparações de outras contas serão preservadas.</p>';
                    self::form('prepare-account'); echo '<input type="hidden" name="product_id" value="' . absint($id) . '"><input type="hidden" name="remote_id" value="' . esc_attr($row['remote_id']) . '"><input type="hidden" name="connection_id" value="' . esc_attr($context) . '">'; submit_button('Preparar categoria e atributos para esta conta'); echo '</form></section>';
                } else { echo '<p>A edição de categoria e atributos para este canal ainda não está disponível neste fluxo.</p>'; }
                return;
            }
        }
        foreach ($preparations as $preparation) {
        echo '<section class="vilahub-card" id="vilahub-category-' . esc_attr($preparation['channel']) . '">';
        echo '<h3>Dados exigidos por ' . esc_html($preparation['channel']) . '</h3><p>' . esc_html(self::label($preparation['status'])) . ' · ' . esc_html($preparation['lastError'] ?? '') . '</p>';
        if (in_array($preparation['status'],array('QUEUED','CLASSIFYING'),true)) { echo '<p>A análise está em andamento. Aguarde alguns instantes e <a href="' . esc_url(self::url('publications') . ($id ? '&product=' . $id : '&remote_product=' . rawurlencode($remote)) . '&connection_id=' . rawurlencode($context)) . '">atualize a análise</a> para conferir as exigências desta conta.</p>'; }
        $category_options=array();
        $current_id=(($current_category['status']??'')==='FOUND' && $preparation['channel']==='MERCADOLIVRE') ? $current_category['id'] : '';
        if ($current_id) { $category_options[$current_id]=($current_category['path']?:$current_category['name']) . ' (atual no anúncio)'; }
        $selected_category=$preparation['externalCategoryId']??$current_id;
        if (!$selected_category) { $selected_category=$current_id; }
        if(!empty($preparation['externalCategoryId']) && !isset($category_options[$preparation['externalCategoryId']])) { $category_options[$preparation['externalCategoryId']]=$preparation['externalCategoryName']?:$preparation['externalCategoryId']; }
        foreach((array)($preparation['suggestions']??array()) as $suggestion) { if(!empty($suggestion['categoryId']) && !isset($category_options[$suggestion['categoryId']])) { $category_options[$suggestion['categoryId']]=$suggestion['categoryName']??$suggestion['categoryId']; } }
        $tree_links=array('page'=>'vilahub-publications','product'=>$id,'remote_product'=>$remote,'connection_id'=>$context,'category_channel'=>$preparation['channel']);
        $tree_url=static function(string $node) use ($tree_links,$preparation): string { return add_query_arg(array_merge($tree_links,array('category_node'=>$node)),admin_url('admin.php')) . '#vilahub-category-' . $preparation['channel']; };
        if ($preparation['channel']==='MERCADOLIVRE' && $context) {
            $node=(sanitize_text_field(wp_unslash($_GET['category_channel']??''))===$preparation['channel'] && isset($_GET['category_node'])) ? sanitize_text_field(wp_unslash($_GET['category_node'])) : ($selected_category?:'ROOT');
            try {
                $tree=self::request('GET','/category-tree/MERCADOLIVRE?connectionId='.rawurlencode($context) . ($node==='ROOT'?'':'&categoryId='.rawurlencode($node)));
                if (!empty($tree['leaf']) && !empty($tree['id'])) { $category_options[$tree['id']]=$tree['fullPath']; }
                if ($selected_category && ($tree['id']??'')===$selected_category) {
                    if (!empty($tree['leaf'])) { $category_options[$selected_category]=$tree['fullPath']; }
                    else { unset($category_options[$selected_category]); $selected_category=''; }
                }
                echo '<div class="vilahub-category-tree"><h4>Árvore de categorias</h4><p>Navegue por todos os níveis até chegar à categoria final do produto.</p><nav aria-label="Caminho da categoria"><ol><li><a href="'.esc_url($tree_url('ROOT')).'">Todas as categorias</a></li>';
                foreach ($tree['path'] as $level) { echo '<li><a href="'.esc_url($tree_url($level['id'])).'">'.esc_html($level['name']).'</a></li>'; }
                echo '</ol></nav>';
                if ($tree['children']) { echo '<p><strong>Subcategorias de '.esc_html($tree['name']).'</strong></p><ul>'; foreach ($tree['children'] as $child) { echo '<li><a class="button" href="'.esc_url($tree_url($child['id'])).'">'.esc_html($child['name']).' →</a></li>'; } echo '</ul>'; }
                elseif (!empty($tree['leaf'])) { echo '<p><strong>Categoria final: '.esc_html($tree['fullPath']).'</strong></p><button type="button" class="button button-primary" data-vilahub-category="'.esc_attr($tree['id']).'">Usar esta categoria</button>'; }
                echo '</div>';
            } catch (Throwable $error) { echo '<div class="notice notice-warning inline"><p>Não foi possível carregar a árvore: '.esc_html($error->getMessage()).'</p><a href="'.esc_url($tree_url('ROOT')).'">Tentar abrir a árvore novamente</a></div>'; }
        }
        $search=sanitize_text_field(wp_unslash($_GET['category_search']??''));
        $found=null; $search_error='';
        if($search && sanitize_text_field(wp_unslash($_GET['category_channel']??''))===$preparation['channel']) {
            try {
                $found=self::request('GET','/categories/'.rawurlencode($preparation['channel']).'?productId='.rawurlencode($row['remote_id']).'&search='.rawurlencode($search) . ($context ? '&connectionId=' . rawurlencode($context) : ''));
                foreach($found as $category) { if ($preparation['channel']==='MERCADOLIVRE' && array_key_exists('leaf',$category) && $category['leaf']!==true) { continue; } $category_options[$category['externalId']]=$category['fullPath']?:$category['name']; }
            } catch (Throwable $error) { $search_error=$error->getMessage(); }
        }
        echo '<form method="get" action="'.esc_url(admin_url('admin.php') . '#vilahub-category-' . $preparation['channel']).'"><input type="hidden" name="page" value="vilahub-publications"><input type="hidden" name="product" value="'.absint($id).'"><input type="hidden" name="remote_product" value="'.esc_attr($remote).'"><input type="hidden" name="connection_id" value="'.esc_attr($context).'"><input type="hidden" name="category_channel" value="'.esc_attr($preparation['channel']).'"><p><label>Pesquisar categoria pelo nome <input name="category_search" value="'.esc_attr($search).'" minlength="2" maxlength="150" required></label> <button class="button">Pesquisar</button></p></form>';
        if ($search_error) { echo '<div class="notice notice-error inline" role="alert"><p>Não foi possível pesquisar: ' . esc_html($search_error) . '</p></div>'; }
        if ($found !== null) {
            echo '<div class="vilahub-category-results" role="status"><strong>' . count($found) . ' categoria(s) encontrada(s) para “' . esc_html($search) . '”.</strong>';
            if (!$found) { echo '<p>O canal não retornou sugestões para esta pesquisa. Tente um termo mais específico, como o tipo e a finalidade do produto. A categoria atual não foi alterada.</p>'; }
            else { echo '<p>Clique em uma opção abaixo para selecionar a nova categoria. Depois confira os atributos e confirme.</p><ul>'; foreach ($found as $category) {
                if ($preparation['channel']==='MERCADOLIVRE' && $context && array_key_exists('leaf',$category) && $category['leaf']!==true) { echo '<li><a class="button" href="'.esc_url($tree_url($category['externalId'])).'">'.esc_html($category['fullPath']?:$category['name']).' — Abrir subcategorias</a></li>'; }
                else { echo '<li><button type="button" class="button" data-vilahub-category="' . esc_attr($category['externalId']) . '">' . esc_html($category['fullPath']?:$category['name']) . '</button></li>'; }
            } echo '</ul>'; }
            echo '</div>';
        }
        self::form('attributes'); echo '<input type="hidden" name="connection_id" value="' . esc_attr($context) . '"><input type="hidden" name="remote_id" value="' . esc_attr($remote) . '"><input type="hidden" name="channel" value="' . esc_attr($preparation['channel']) . '"><input type="hidden" name="product_id" value="' . absint($id) . '"><p><label>Categoria para revisar e confirmar <select name="category" required><option value="">Selecione uma categoria</option>';
        foreach($category_options as $category=>$name) { echo '<option value="'.esc_attr($category).'" '.selected($selected_category, $category, false).'>'.esc_html($name).'</option>'; }
        echo '</select></label></p><input type="hidden" name="category_name" value="' . esc_attr($category_options[$selected_category]??'') . '">';

        foreach ($preparation['requiredAttributes'] as $field) {
            $value = $preparation['attributeValues'][$field['id']] ?? ''; echo '<p><label>' . esc_html($field['name']) . ' ';
            if ($field['choices']) { echo '<select name="attribute[' . esc_attr($field['id']) . ']"><option value="">Selecione</option>'; foreach ($field['choices'] as $choice) { echo '<option value="' . esc_attr($choice['name']) . '" ' . selected($value,$choice['name'],false) . '>' . esc_html($choice['name']) . '</option>'; } echo '</select>'; }
            else { echo '<input name="attribute[' . esc_attr($field['id']) . ']" value="' . esc_attr(is_scalar($value)?$value:'') . '" maxlength="500">'; } echo '</label></p>';
        }
        submit_button('Confirmar categoria e atributos'); echo '</form><p>Depois da correção, selecione o produto na listagem e envie novamente ao canal. Anúncios já existentes seguem as verificações de vínculo do conector.</p></section>';
        }
    }
}
