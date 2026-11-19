<?php
defined('ABSPATH') || exit;

// Admin order lists read plugin-owned tables with prepared values. These reads
// intentionally bypass object caching so pending stock and fiscal state is current.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// GET values select pages, filters and previews only. All order, stock and fiscal
// mutations pass through handle(), which verifies vilahub_order_action first.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class VilaHub_Order_Admin {
    public static function list_url(): string {return admin_url('admin.php?page=vilahub-orders');}
    public static function fiscal_url(): string {return admin_url('admin.php?page=vilahub-fiscal');}
    public static function billing_url(): string {return admin_url('admin.php?page=vilahub-billing');}
    public static function boot(): void {
        // The parent Marketplaces menu registers at priority 20. Register its children afterwards.
        add_action('admin_menu',function(){add_submenu_page('vilahub','Pedidos dos marketplaces','Pedidos','manage_woocommerce','vilahub-orders',array(__CLASS__,'listing'));add_submenu_page('vilahub','Fiscal','Fiscal','manage_woocommerce','vilahub-fiscal',array(__CLASS__,'fiscal'));add_submenu_page('vilahub','Financeiro','Financeiro','manage_woocommerce','vilahub-billing',array(__CLASS__,'billing'));add_submenu_page(null,'Pedido do marketplace','Pedido do marketplace','manage_woocommerce','vilahub-order-review',array(__CLASS__,'review'));add_submenu_page(null,'Configurar pedidos','Configurar pedidos','manage_options','vilahub-order-settings',array(__CLASS__,'configure'));},30);
        add_action('woocommerce_product_options_general_product_data',function(){woocommerce_wp_text_input(array('id'=>'_vilahub_ncm','label'=>'NCM fiscal VilaHub','description'=>'Informe os 8 dígitos com orientação do contador.'));woocommerce_wp_text_input(array('id'=>'_vilahub_fiscal_unit','label'=>'Unidade fiscal VilaHub','description'=>'Exemplo: UN. Usada na emissão fiscal pelo serviço.'));});
        add_action('woocommerce_admin_process_product_object',function($product){
            if(!isset($_POST['_vilahub_ncm']))return;
            $raw_nonce=isset($_POST['woocommerce_meta_nonce'])?wp_unslash($_POST['woocommerce_meta_nonce']):''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalar validation and sanitization occur immediately below before WooCommerce's nonce is verified.
            if(!is_string($raw_nonce))return;
            $nonce=sanitize_text_field($raw_nonce);
            if(!wp_verify_nonce($nonce,'woocommerce_save_data')||!current_user_can('edit_post',$product->get_id()))return;
            $raw_ncm=is_string($_POST['_vilahub_ncm'])?sanitize_text_field(wp_unslash($_POST['_vilahub_ncm'])):'';
            $ncm=preg_replace('/\D/','',$raw_ncm);
            if($ncm!==''&&!preg_match('/^\d{8}$/',$ncm)){WC_Admin_Meta_Boxes::add_error('Informe um NCM válido com 8 dígitos.');return;}
            $unit=isset($_POST['_vilahub_fiscal_unit'])&&is_string($_POST['_vilahub_fiscal_unit'])?sanitize_text_field(wp_unslash($_POST['_vilahub_fiscal_unit'])):'';$product->update_meta_data('_vilahub_ncm',$ncm);$product->update_meta_data('_vilahub_fiscal_unit',$unit);
        });
        add_action('admin_enqueue_scripts',function(){if(in_array(sanitize_key(wp_unslash($_GET['page']??'')),array('vilahub-orders','vilahub-order-review','vilahub-order-settings','vilahub-fiscal','vilahub-billing'),true))wp_enqueue_style('vilahub-orders',plugins_url('assets/orders.css',VILAHUB_WC_FILE),array(),VILAHUB_WC_VERSION);if((sanitize_key(wp_unslash($_GET['page']??'')))==='vilahub-order-review'){wp_enqueue_script('wc-enhanced-select');wp_enqueue_style('woocommerce_admin_styles');}});
        add_action('admin_post_vilahub_order_action',array(__CLASS__,'handle'));
    }
    public static function status_label(string $status): string {
        $labels=array('PAID'=>'Pago','APPROVED'=>'Pagamento aprovado','PROCESSING'=>'Em processamento','ON-HOLD'=>'Aguardando conferência','PENDING'=>'Pendente','PENDING_PAYMENT'=>'Aguardando pagamento','UNPAID'=>'Aguardando pagamento','AWAITING_PAYMENT'=>'Aguardando pagamento','READY_TO_SHIP'=>'Pronto para envio','READY_TO_PRINT'=>'Etiqueta liberada','SHIPPED'=>'Enviado','IN_TRANSIT'=>'Em transporte','DELIVERED'=>'Entregue','COMPLETED'=>'Concluído','CANCELLED'=>'Cancelado','CANCELED'=>'Cancelado','REFUNDED'=>'Reembolsado','FAILED'=>'Falhou','NOT_ISSUED'=>'Não emitida','NOT_SENT'=>'Não enviada','AUTHORIZED'=>'Autorizada','REJECTED'=>'Recusada','SENT'=>'Enviada','ACCEPTED'=>'Aceita pelo canal','PRODUCTION'=>'Produção','HOMOLOGATION'=>'Homologação');
        return $labels[strtoupper($status)]??($status?ucfirst(strtolower(str_replace('_',' ',$status))):'Não informado');
    }
    private static function channel_name(string $channel): string {return array('MERCADOLIVRE'=>'Mercado Livre','MAGALU'=>'Magalu','SHOPEE'=>'Shopee','MEDUSA'=>'VilaBrasil','TIKTOK'=>'TikTok Shop','MADEIRAMADEIRA'=>'MadeiraMadeira')[$channel]??$channel;}
    private static function panel(string $title,string $id=''): void {echo '<section class="vh-card"'.($id?' id="'.esc_attr($id).'"':'').'><h2>'.esc_html($title).'</h2>';}
    private static function stock_label(VilaHub_Order_Record $order,array $data): string {
        if(($data['policy']['stockAuthority']??'')==='ERP')return 'Controlado pelo ERP';
        if($order->get_meta('_vilahub_stock_restored'))return 'Estoque reposto';
        return $order->get_meta('_vilahub_stock_applied')?'Baixa registrada':'Nenhuma baixa realizada';
    }
    private static function needs_baseline(VilaHub_Order_Record $order,array $data): bool {
        $created=strtotime((string)($data['sourceCreatedAt']??''));$activated=strtotime((string)($data['policy']['activatedAt']??''));
        return !$order->get_meta('_vilahub_stock_applied')&&!$order->get_meta('_vilahub_stock_baseline_confirmed')&&($data['inventory']??'')==='CONSUME'&&($data['policy']['stockAuthority']??'')==='WOOCOMMERCE'&&!($created&&$activated&&$created>=$activated);
    }
    private static function account_options(): array {
        $options=array();
        try {
            foreach(VilaHub_Orders::request('GET','/accounts') as $account){
                $id=(string)($account['id']??'');
                if(!$id)continue;
                $options[$id]=self::channel_name((string)($account['channel']??'')).' — '.(string)($account['name']??$id);
            }
        } catch(Throwable $ignored) {}
        foreach((array)get_option('vilahub_order_accounts',array()) as $id=>$config){
            if(!isset($options[$id]))$options[$id]=(string)($config['name']??$id);
        }
        natcasesort($options);
        return $options;
    }
    public static function listing(): void {
        if(!current_user_can('manage_woocommerce'))return;
        global $wpdb;$tenant=VilaHub_Store::settings()['tenant_id'];$account=sanitize_text_field(wp_unslash($_GET['account']??''));$status=sanitize_key(wp_unslash($_GET['status']??''));$search=sanitize_text_field(wp_unslash($_GET['search']??''));$page=max(1,absint(wp_unslash($_GET['paged']??1)));
        $like='%'.$wpdb->esc_like($search).'%';$table=VilaHub_Order_Record::table();
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i r WHERE r.tenant_id=%s AND (%s='' OR r.connection_id=%s) AND (%s='' OR r.status=%s) AND (%s='' OR r.external_id LIKE %s OR r.payload LIKE %s)",$table,$tenant,$account,$account,$status,$status,$search,$like,$like));
        $page=min($page,max(1,(int)ceil($count/25)));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT r.id FROM %i r WHERE r.tenant_id=%s AND (%s='' OR r.connection_id=%s) AND (%s='' OR r.status=%s) AND (%s='' OR r.external_id LIKE %s OR r.payload LIKE %s) ORDER BY r.updated_at DESC,r.id DESC LIMIT 25 OFFSET %d",$table,$tenant,$account,$account,$status,$status,$search,$like,$like,($page-1)*25),ARRAY_A);
        echo '<div class="wrap vilahub-orders vh-orders-listing">';VilaHub_Marketplace::navigation('vilahub-orders');
        echo '<header class="vh-orders-title"><div><p class="vh-kicker">OPERAÇÃO</p><h1>Pedidos</h1><p>Acompanhe vendas, estoque e notas fiscais dos canais conectados.</p></div><div class="vh-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=vilahub-order-settings')).'">Configurar recebimento</a>';
        self::form('sync-list',$account?array('connection_id'=>$account):array());echo '<button class="button button-primary">Atualizar pedidos</button></form></div></header>';
        $notice=get_transient('vilahub_notice_'.get_current_user_id());delete_transient('vilahub_notice_'.get_current_user_id());if($notice)echo '<div class="notice '.(!empty($notice['error'])?'notice-error':'notice-success').' inline"><p>'.esc_html($notice['message']).'</p></div>';
        if($error=get_option('vilahub_order_migration_error'))echo '<div class="notice notice-error inline"><p>'.esc_html($error).'</p><p>A importação permanece pausada enquanto a separação não for concluída.</p></div>';
        echo '<form method="get" class="vh-order-filters"><input type="hidden" name="page" value="vilahub-orders"><label>Integração<select name="account"><option value="">Todas as integrações</option>';
        foreach(self::account_options() as $id=>$name)echo '<option value="'.esc_attr($id).'" '.selected($account,$id,false).'>'.esc_html($name).'</option>';
        echo '</select></label><label>Situação<select name="status"><option value="">Todas</option>';foreach(wc_get_order_statuses() as $key=>$label){$key=substr($key,3);echo '<option value="'.esc_attr($key).'" '.selected($status,$key,false).'>'.esc_html($label).'</option>';}
        echo '</select></label><label>Buscar pedido ou produto<input name="search" value="'.esc_attr($search).'" placeholder="Número, nome ou SKU"></label><button class="button button-primary">Filtrar</button></form><p class="vh-order-count">'.esc_html($count).' pedido(s)</p><div class="vh-order-table"><table><thead><tr><th>Pedido</th><th>Integração</th><th>Data</th><th>Produto</th><th>Situação</th><th>NF-e</th><th>Estoque</th><th>Total</th><th>Ações</th></tr></thead><tbody>';
        foreach($rows as $row){$order=VilaHub_Orders::get((int)$row['id']);if(!$order)continue;$data=(array)$order->get_meta('_vilahub_snapshot');$warnings=(array)$order->get_meta('_vilahub_warnings');$error=$wpdb->get_var($wpdb->prepare('SELECT error_message FROM %i WHERE tenant_id=%s AND remote_id=%s',VilaHub_Orders::table(),$tenant,$data['id']??''));
            $items=(array)($data['items']??array());$first=$items?reset($items):array();$created=strtotime((string)($data['sourceCreatedAt']??''));$issues=array_unique(array_filter(array_merge($warnings,array($error))));
            echo '<tr><td><span class="vh-order-number">'.esc_html($data['externalId']??'').'</span></td><td><strong>'.esc_html(self::channel_name((string)($data['channel']??''))).'</strong><small>'.esc_html($data['accountName']??'').'</small></td><td>'.esc_html($created?wp_date('d/m/Y, H:i',$created):'Não informada').'</td><td class="vh-order-product"><strong>'.esc_html($first['title']??'Produto não informado').'</strong><small>SKU: '.esc_html($first['sku']??'não informado').' · Qtd.: '.esc_html($first['quantity']??0).'</small>'.(count($items)>1?'<small>+'.esc_html(count($items)-1).' item(ns)</small>':'').'</td><td><span class="vh-status">'.esc_html(self::status_label((string)($data['status']??''))).'</span></td><td><strong>'.esc_html(self::status_label((string)($data['fiscal']['status']??'NOT_ISSUED'))).'</strong></td><td>'.esc_html(self::stock_label($order,$data));
            if($issues)echo '<small class="vh-order-warning">'.esc_html(count($issues)).' pendência(s)</small>';
            echo '</td><td><strong>'.wp_kses_post(wc_price(($data['totalInCents']??0)/100)).'</strong></td><td><div class="vh-row-actions"><a class="button" href="'.esc_url($order->get_edit_order_url()).'">Ver pedido</a>';
            if(!empty($data['capabilities']['invoice'])||!empty($data['capabilities']['externalInvoice']))echo '<a class="button button-primary" href="'.esc_url(add_query_arg('invoice_preview','1',$order->get_edit_order_url()).'#nota-fiscal').'">Emitir NF-e</a>';
            echo '</div></td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="9" class="vh-empty">Nenhum pedido encontrado para estes filtros.</td></tr>';
        echo '</tbody></table></div><p>';for($n=max(1,$page-2);$n<=min((int)ceil($count/25),$page+2);$n++)echo '<a class="button '.($n===$page?'button-primary':'').'" href="'.esc_url(add_query_arg(array('page'=>'vilahub-orders','account'=>$account,'status'=>$status,'search'=>$search,'paged'=>$n),admin_url('admin.php'))).'">'.esc_html($n).'</a> ';echo '</p>';
        $pending=$wpdb->get_results($wpdb->prepare('SELECT remote_id,error_message FROM %i WHERE tenant_id=%s AND order_id=0 AND error_message IS NOT NULL ORDER BY updated_at DESC LIMIT 20',VilaHub_Orders::table(),$tenant),ARRAY_A);
        if($pending){echo '<details class="vh-card"><summary>Recebimentos pendentes ('.esc_html(count($pending)).')</summary>';foreach($pending as $entry)echo '<p>'.esc_html($entry['remote_id'].' — '.$entry['error_message']).'</p>';self::form('retry-pending');echo '<p><button class="button">Tentar novamente</button></p></form></details>';}
        echo '</div>';
    }
    private static function form(string $action,array $hidden=array()): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('vilahub_order_action');echo '<input type="hidden" name="action" value="vilahub_order_action"><input type="hidden" name="operation" value="'.esc_attr($action).'">';foreach($hidden as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
    }
    private static function money($cents): string {return wp_kses_post(wc_price(((int)$cents)/100));}
    private static function billing_status(string $status): string {
        return array('PAID'=>'Pago','CALCULATING'=>'Em apuração','DUE'=>'Aguardando pagamento','OVERDUE'=>'Vencido','REFUNDED'=>'Estornado','PARTIALLY_REFUNDED'=>'Estorno parcial','CONFIRMED'=>'Confirmada','PENDING'=>'Aguardando pagamento','REVERSED'=>'Estornada','INELIGIBLE'=>'Não elegível')[$status]??self::status_label($status);
    }
    public static function billing(): void {
        if(!current_user_can('manage_woocommerce'))return;
        echo '<div class="wrap vilahub-orders vh-billing-page">';VilaHub_Marketplace::navigation('vilahub-billing');
        echo '<header class="vh-orders-title"><div><p class="vh-kicker">MENSALIDADES E PAGAMENTOS</p><h1>Financeiro</h1><p>Acompanhe mensalidades, uso, créditos e comissões da sua empresa.</p></div><a class="button button-primary" href="https://vilahub.com.br/entrar?view=billing" target="_blank" rel="noopener noreferrer">Abrir pagamentos no VilaHub</a></header>';
        try{$data=VilaHub_Orders::billing();}catch(Throwable $e){echo '<div class="vh-alert"><p>'.esc_html($e->getMessage()).'</p></div></div>';return;}
        $usage=(array)($data['monthlyUsage']??array());$subscription=(array)($data['subscription']??array());$credits=is_array($data['supplierCredits']??null)?$data['supplierCredits']:null;
        if($credits){$rate=(int)($credits['ratePercent']??0);echo '<section class="vh-card"><div class="vh-heading"><div><p class="vh-kicker">PARCERIA DROPSHIP · '.esc_html($rate).'%</p><h2>Comissões e créditos</h2></div><p class="vh-muted">A comissão entra no saldo após o pagamento confirmado do vendedor.</p></div><div class="vh-billing-cards">';
            foreach(array(array('Comissões confirmadas acumuladas','confirmedInCents','Já descontados os estornos'),array('Total abatido em mensalidades','appliedInCents','Créditos utilizados pela sua empresa'),array('Crédito disponível','balanceInCents','Para abater próximas mensalidades · sem saque')) as $card)echo '<article><span>'.esc_html($card[0]).'</span><strong>'.wp_kses_post(self::money($credits[$card[1]]??0)).'</strong><small>'.esc_html($card[2]).'</small></article>';
            echo '</div><p>Previsão ainda não liberada: <strong>'.wp_kses_post(self::money($credits['forecastInCents']??0)).'</strong>. Esse valor ainda não compõe o saldo disponível.</p>';
            $sellers=(array)($credits['sellers']??array());if($sellers){echo '<div class="vh-billing-table"><table><thead><tr><th>Vendedor / competência</th><th>Mensalidade recebida</th><th>Situação</th><th>Comissão</th></tr></thead><tbody>';foreach($sellers as $seller)echo '<tr><td><strong>'.esc_html($seller['sellerName']??'—').'</strong><small>'.esc_html($seller['period']??'—').'</small></td><td>'.wp_kses_post(self::money($seller['paidInCents']??0)).'</td><td>'.esc_html(self::billing_status((string)($seller['status']??''))).'</td><td><strong>'.wp_kses_post(self::money($seller['commissionInCents']??0)).'</strong>'.(!empty($seller['forecastInCents'])?'<small>Previsão: '.wp_kses_post(self::money($seller['forecastInCents'])).'</small>':'').'</td></tr>';echo '</tbody></table></div>';}
            echo '</section>';
        }
        echo '<section class="vh-card"><div class="vh-heading"><div><p class="vh-kicker">COMPETÊNCIA ATUAL</p><h2>Mensalidade em apuração</h2></div><strong class="vh-billing-total">'.wp_kses_post(self::money($usage['totalInCents']??0)).'</strong></div><div class="vh-billing-cards vh-usage-cards">';
        foreach(array(array('Produtos','productCount','productsInCents'),array('Integrações ativas','integrationCount','integrationsInCents'),array('Pedidos no período','orderCount','ordersInCents')) as $item)echo '<article><span>'.esc_html($item[0]).'</span><strong>'.esc_html($usage[$item[1]]??0).'</strong><small>'.wp_kses_post(self::money($usage['breakdown'][$item[2]]??0)).'</small></article>';
        echo '</div><p class="vh-muted">Plano: '.esc_html($subscription['status']??'Ativo').'. Os valores são consolidados no fechamento da competência.</p></section>';
        $statement=(array)($data['statement']??array());echo '<section class="vh-card"><div class="vh-heading"><div><p class="vh-kicker">EXTRATO</p><h2>Mensalidades</h2></div></div><div class="vh-billing-table"><table><thead><tr><th>Competência</th><th>Vencimento</th><th>Situação</th><th>Valor</th><th>Créditos</th><th>A pagar</th><th></th></tr></thead><tbody>';
        foreach($statement as $invoice){$due=!empty($invoice['dueDate'])?wp_date('d/m/Y',strtotime($invoice['dueDate'])):'—';echo '<tr><td><strong>'.esc_html($invoice['period']??'—').'</strong></td><td>'.esc_html($due).'</td><td><span class="vh-status">'.esc_html(self::billing_status((string)($invoice['displayStatus']??$invoice['status']??''))).'</span></td><td>'.wp_kses_post(self::money($invoice['amountInCents']??0)).'</td><td>'.wp_kses_post(self::money($invoice['creditAppliedInCents']??0)).'</td><td><strong>'.wp_kses_post(self::money($invoice['remainingInCents']??0)).'</strong></td><td>'.(!empty($invoice['paymentAllowed'])?'<a class="button button-primary" href="https://vilahub.com.br/entrar?view=billing" target="_blank" rel="noopener noreferrer">Pagar no VilaHub</a>':'').'</td></tr>';}
        if(!$statement)echo '<tr><td colspan="7" class="vh-empty">Nenhuma mensalidade gerada até agora.</td></tr>';echo '</tbody></table></div></section></div>';
    }
    public static function fiscal(): void {
        if(!current_user_can('manage_woocommerce'))return;
        echo '<div class="wrap vilahub-orders vh-fiscal-page">';VilaHub_Marketplace::navigation('vilahub-fiscal');echo '<header class="vh-orders-title"><div><p class="vh-kicker">GESTÃO FISCAL</p><h1>Notas fiscais</h1></div></header>';
        $notice=get_transient('vilahub_notice_'.get_current_user_id());delete_transient('vilahub_notice_'.get_current_user_id());if($notice)echo '<div class="notice '.(!empty($notice['error'])?'notice-error':'notice-success').' inline"><p>'.esc_html($notice['message']).'</p></div>';
        try{$accounts=VilaHub_Orders::request('GET','/accounts');}catch(Throwable $e){echo '<div class="vh-alert"><p>'.esc_html($e->getMessage()).'</p></div></div>';return;}
        if(!$accounts){echo '<section class="vh-card"><h2>Nenhuma integração encontrada</h2><p>Conecte uma integração no VilaHub antes de configurar a emissão fiscal.</p></section></div>';return;}
        $selected=sanitize_text_field(wp_unslash($_GET['connection_id']??''));if(!$selected)$selected=(string)$accounts[0]['id'];$account=null;foreach($accounts as $item)if((string)$item['id']===$selected){$account=$item;break;}if(!$account)$account=$accounts[0];$selected=(string)$account['id'];
        echo '<section class="vh-card vh-fiscal-selector"><h2>Perfil fiscal por integração</h2><p>Selecione a loja responsável. CNPJ, certificado, série e notas ficam separados para cada integração.</p><form method="get"><input type="hidden" name="page" value="vilahub-fiscal"><label>Integração<select name="connection_id">';foreach($accounts as $item)echo '<option value="'.esc_attr($item['id']).'" '.selected($selected,$item['id'],false).'>'.esc_html(self::channel_name((string)$item['channel']).' — '.$item['name']).'</option>';echo '</select></label><button class="button">Carregar perfil</button></form></section>';
        try{$fiscal=VilaHub_Orders::request('GET','/accounts/'.rawurlencode($selected).'/fiscal')??array();$series=VilaHub_Orders::request('GET','/accounts/'.rawurlencode($selected).'/fiscal/series');$documents=VilaHub_Orders::request('GET','/accounts/'.rawurlencode($selected).'/fiscal/documents');}catch(Throwable $e){echo '<div class="vh-alert"><p>'.esc_html($e->getMessage()).'</p></div></div>';return;}
        if(!empty($fiscal['requiresAccountantAction']))echo '<section class="vh-alert"><p class="vh-kicker">AÇÃO NECESSÁRIA DO CONTADOR</p><h2>A empresa ainda precisa ser habilitada na Receita/SEFAZ do seu estado.</h2><p>Peça ao contador para credenciar a empresa para emissão de NF-e. Até a conclusão, use somente o ambiente de homologação.</p></section>';
        echo '<section class="vh-card"><h2>Certificado A1 para emissão fiscal</h2><p>'.(!empty($fiscal['hasCertificate'])?'Certificado cadastrado: '.esc_html($fiscal['certificateFileName']??'arquivo protegido'):'Cadastre o arquivo .pfx ou .p12 e sua senha. O conteúdo será armazenado criptografado.').'</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('vilahub_order_action');echo '<input type="hidden" name="action" value="vilahub_order_action"><input type="hidden" name="operation" value="certificate"><input type="hidden" name="return_page" value="fiscal"><input type="hidden" name="connection_id" value="'.esc_attr($selected).'"><div class="vh-fields"><p><label>Arquivo do certificado<input type="file" name="pfx" accept=".pfx,.p12" required></label></p><p><label>Senha do certificado<input type="password" name="certificate_password" autocomplete="new-password" required></label></p></div><button class="button button-primary">Cadastrar certificado</button></form></section>';
        echo '<section class="vh-card"><h2>Séries fiscais da empresa</h2>';if($series){echo '<table class="widefat striped"><thead><tr><th>Série</th><th>Próximo número</th><th>Situação</th></tr></thead><tbody>';foreach($series as $item)echo '<tr><td>'.esc_html($item['series']).'</td><td>'.esc_html($item['nextNumber']).'</td><td>'.esc_html(!empty($item['active'])?'Ativa':'Inativa').'</td></tr>';echo '</tbody></table>';}self::form('fiscal-series',array('connection_id'=>$selected,'return_page'=>'fiscal'));echo '<div class="vh-fields"><p><label>Nova série<input type="number" min="1" max="999" name="series" required></label></p><p><label>Próximo número<input type="number" min="1" max="999999999" name="next_number" required></label></p></div><button class="button button-primary">Salvar série</button></form></section>';
        echo '<section class="vh-card"><h2>Cadastro fiscal completo</h2>';self::fiscal_configuration($account,false);echo '</section>';
        echo '<section class="vh-card"><h2>Notas emitidas nesta integração</h2>';if(!$documents)echo '<p class="vh-muted">Nenhuma nota emitida.</p>';else{echo '<div class="vh-products"><table class="widefat striped"><thead><tr><th>Referência</th><th>Número / série</th><th>Situação</th><th>Emissão</th><th>Documentos</th></tr></thead><tbody>';foreach($documents as $document){echo '<tr><td>'.esc_html($document['reference']).'</td><td>'.esc_html(($document['number']??'—').' / '.($document['series']??'—')).'</td><td>'.esc_html(self::status_label((string)$document['status'])).'</td><td>'.esc_html(!empty($document['createdAt'])?wp_date('d/m/Y H:i',strtotime($document['createdAt'])):'—').'</td><td>';foreach(array('xmlPath'=>'XML','pdfPath'=>'DANFE') as $key=>$label)if(!empty($document[$key]))self::link((string)$document[$key],$label);echo '</td></tr>';}echo '</tbody></table></div>';}echo '</section></div>';
    }
    public static function configure(): void {
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap vilahub-orders"><h1>Configurar recebimento de pedidos</h1><p><a class="button" href="'.esc_url(self::list_url()).'">Voltar aos pedidos</a></p>';VilaHub_Marketplace::navigation('vilahub-orders');
        echo '<div class="vh-settings-intro"><strong>Escolha de quais contas você quer receber pedidos.</strong><ol><li>Encontre abaixo o marketplace e a conta.</li><li>Ative o recebimento e escolha quem controla o estoque.</li><li>Salve e clique em Buscar pedidos agora.</li></ol><p>Os pedidos aparecem somente em VilaHub → Pedidos. Vendas anteriores à ativação não descontam estoque automaticamente.</p></div>';
        try{$accounts=VilaHub_Orders::request('GET','/accounts');}catch(Throwable $e){echo '<div class="vh-alert"><h2>Não foi possível carregar as contas</h2><p>'.esc_html($e->getMessage()).'</p><a class="button" href="'.esc_url(admin_url('admin.php?page=vilahub')).'">Conferir autorização da loja</a></div></div>';return;}
        $local=(array)get_option('vilahub_order_accounts',array());$selected=sanitize_text_field(wp_unslash($_GET['fiscal_account']??''));
        $logos=array('MERCADOLIVRE'=>'mercadolivre.svg','SHOPEE'=>'shopee.svg','MAGALU'=>'magalu.png','TIKTOK'=>'tiktok-shop.svg','MEDUSA'=>'vila-brasil.svg','MADEIRAMADEIRA'=>'madeiramadeira.svg');
        if(!$accounts)echo '<section class="vh-card"><h2>Nenhuma conta conectada</h2><p>Conecte uma conta de marketplace para receber suas vendas.</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=vilahub-channels')).'">Conectar Canal de Venda</a></section>';
        foreach($accounts as $account){$id=(string)$account['id'];$active=!empty($account['active']);$channel=self::channel_name((string)$account['channel']);$anchor='conta-'.$id;
            echo '<section class="vh-card" id="'.esc_attr($anchor).'"><header class="vh-heading"><div class="vh-account-title">';if(isset($logos[$account['channel']]))echo '<img src="'.esc_url(plugins_url('assets/marketplaces/'.$logos[$account['channel']],VILAHUB_WC_FILE)).'" alt="'.esc_attr($channel).'">';echo '<div><h2>'.esc_html($channel).'</h2><p>Conta: <strong>'.esc_html($account['name']).'</strong></p></div></div><span class="vh-badge'.(!$active?' is-off':'').'">'.($active?'Recebendo pedidos':'Recebimento desativado').'</span></header>';
            if(empty($account['supported'])||(!empty($account['ownedElsewhere'])&&($account['siteId']??'')!==get_option('vilahub_site_id'))){echo '<p>'.esc_html(!empty($account['ownedElsewhere'])?'Esta conta está vinculada a outra loja. Desative o recebimento na loja anterior antes de transferir.':'O recebimento de pedidos deste canal ainda não está disponível.').'</p></section>';continue;}
            echo '<div class="vh-account-options">';self::form('configure',array('connection_id'=>$id,'name'=>$account['name']));echo '<label class="vh-check"><input type="checkbox" name="active" value="1" '.checked($active,true,false).'><span><strong>Receber pedidos desta conta</strong><br>As próximas vendas serão consultadas automaticamente.</span></label><div class="vh-fields"><p><label>Quem controla o estoque?<select name="stock_authority" required><option value="" disabled '.selected(empty($account['stockAuthority']),true,false).'>Escolha uma opção</option><option value="WOOCOMMERCE" '.selected($account['stockAuthority']??'','WOOCOMMERCE',false).'>WooCommerce — baixar o estoque desta loja</option><option value="ERP" '.selected($account['stockAuthority']??'','ERP',false).'>ERP externo — não baixar no WooCommerce</option></select></label></p></div><p class="vh-muted">WooCommerce: o VilaHub desconta o produto vinculado uma única vez e sincroniza o saldo. ERP: o sistema externo continua responsável pelo saldo.</p><label class="vh-check"><input type="checkbox" name="confirm" value="1" required><span>Confirmo a conta e quem controla o estoque destas vendas.</span></label><button class="button button-primary">Salvar recebimento de pedidos</button></form>';
            if($active){echo '<div class="vh-actions">';self::form('sync',array('connection_id'=>$id));echo '<button class="button">Buscar pedidos agora</button></form><a class="button" href="'.esc_url(add_query_arg('account',$id,self::list_url())).'">Ver pedidos desta conta</a></div>';}
            if(!empty($local[$id]['error']))echo '<div class="vh-alert"><strong>Recebimento com pendência</strong><p>'.esc_html($local[$id]['error']).'</p></div>';
            echo '</div>';
            if($active&&!empty($account['capabilities']['invoice'])){
                echo '<hr><h3>Emissão de nota fiscal</h3><p>Configure os dados da empresa e o certificado para emitir notas dos pedidos desta conta. A emissão exige confirmação em cada pedido.</p>';
                if($selected===$id)self::fiscal_configuration($account);
                else echo '<a class="button" href="'.esc_url(add_query_arg('fiscal_account',$id,admin_url('admin.php?page=vilahub-order-settings')).'#'.$anchor).'">Configurar nota fiscal desta conta</a>';
            }elseif($active)echo '<p class="vh-muted">'.(!empty($account['erp'])?'O faturamento desta conta permanece no ERP.':'Não é necessário cadastrar certificado fiscal para receber pedidos desta conta.').'</p>';
            echo '</section>';
        }
        global $wpdb;$pending=$wpdb->get_results($wpdb->prepare('SELECT order_id,error_message FROM %i WHERE tenant_id=%s AND error_message IS NOT NULL ORDER BY updated_at DESC LIMIT 20',VilaHub_Orders::table(),VilaHub_Store::settings()['tenant_id']),ARRAY_A);
        if($pending){echo '<details class="vh-card"><summary>Pedidos com pendências ('.esc_html(count($pending)).')</summary>';foreach($pending as $item){echo '<p>'.esc_html($item['error_message']);if($item['order_id']&&($order=VilaHub_Orders::get((int)$item['order_id'])))echo ' <a href="'.esc_url($order->get_edit_order_url()).'">Resolver no pedido</a>';echo '</p>';}self::form('retry-pending');echo '<p><button class="button">Tentar receber novamente</button></p></form></details>';}
        echo '</div>';
    }
    private static function fiscal_fields(array $values,array $fields): void {
        echo '<div class="vh-fields">';foreach($fields as $key=>$label)echo '<p><label>'.esc_html($label).'<input type="text" name="fiscal['.esc_attr($key).']" value="'.esc_attr($values[$key]??'').'"></label></p>';echo '</div>';
    }
    private static function fiscal_configuration(array $account,bool $show_certificate=true): void {
        try{$id=(string)$account['id'];$fiscal=VilaHub_Orders::request('GET','/accounts/'.rawurlencode($id).'/fiscal')??array();
            self::form('fiscal-config',array('connection_id'=>$id)+(sanitize_key(wp_unslash($_GET['page']??''))==='vilahub-fiscal'?array('return_page'=>'fiscal'):array()));echo '<p class="vh-stock-note">Preencha estes dados com seu contador. Salvar esta configuração não emite nenhuma nota fiscal.</p><fieldset><legend>1. Empresa emitente</legend>';
            self::fiscal_fields($fiscal,array('cnpj'=>'CNPJ','legalName'=>'Razão social','tradeName'=>'Nome fantasia','stateRegistration'=>'Inscrição estadual','municipalRegistration'=>'Inscrição municipal','taxRegime'=>'Regime tributário'));
            echo '</fieldset><fieldset><legend>2. Endereço da empresa</legend>';
            self::fiscal_fields($fiscal,array('street'=>'Logradouro','addressNumber'=>'Número','addressComplement'=>'Complemento','district'=>'Bairro','city'=>'Município','stateCode'=>'UF','cityCode'=>'Código IBGE','postalCode'=>'CEP','phone'=>'Telefone'));
            echo '</fieldset><details><summary>3. Regras tributárias e dados do contador</summary><fieldset><legend>Regras fiscais</legend>';
            self::fiscal_fields($fiscal,array('operationNature'=>'Natureza da operação','cfopInState'=>'CFOP dentro do estado','cfopOutState'=>'CFOP fora do estado','productOrigin'=>'Origem do produto','icmsCode'=>'CST/CSOSN ICMS','pisCode'=>'CST PIS','cofinsCode'=>'CST COFINS','freightMode'=>'Modalidade frete (AUTO ou código)','paymentMethod'=>'Pagamento fiscal (AUTO ou código)','accountantName'=>'Contador','accountantEmail'=>'E-mail do contador','accountantPhone'=>'Telefone do contador','sefazNotes'=>'Observações SEFAZ'));
            echo '</fieldset></details><fieldset><legend>4. Ativação da emissão</legend><div class="vh-fields"><p><label>Ambiente<select name="fiscal[environment]"><option value="HOMOLOGATION" '.selected($fiscal['environment']??'','HOMOLOGATION',false).'>Homologação — testes</option><option value="PRODUCTION" '.selected($fiscal['environment']??'','PRODUCTION',false).'>Produção — notas reais</option></select></label></p><p><label>Habilitação na SEFAZ<select name="fiscal[sefazStatus]">';foreach(array('PENDING_ACCOUNTANT'=>'Pendente com contador','IN_PROGRESS'=>'Em andamento','VERIFIED'=>'Habilitação conferida pelo responsável') as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($fiscal['sefazStatus']??'',$key,false).'>'.esc_html($label).'</option>';echo '</select></label></p></div><label class="vh-check"><input type="checkbox" name="fiscal[active]" value="1" '.checked($fiscal['active']??false,true,false).'><span>Ativar emissão de notas nesta conta</span></label></fieldset><button class="button button-primary">Salvar configuração fiscal</button></form>';
            if($show_certificate)echo '<details class="vh-card"><summary>Certificado digital A1</summary><p>Envie o certificado da empresa emitente. A senha não será exibida depois de salvar.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('vilahub_order_action');echo '<input type="hidden" name="action" value="vilahub_order_action"><input type="hidden" name="operation" value="certificate"><input type="hidden" name="connection_id" value="'.esc_attr($id).'"><div class="vh-fields"><p><label>Arquivo do certificado (.pfx ou .p12)<input type="file" name="pfx" accept=".pfx,.p12" required></label></p><p><label>Senha do certificado<input type="password" name="certificate_password" autocomplete="new-password" required></label></p></div><p><button class="button">Salvar certificado</button></p></form></details>';
        }catch(Throwable $e){echo '<div class="vh-alert"><p>'.esc_html($e->getMessage()).'</p></div>';}
    }
    public static function box($value): void {
        $order=$value instanceof VilaHub_Order_Record?$value:VilaHub_Orders::get((int)$value);if(!$order){echo '<p>Pedido do marketplace não encontrado.</p>';return;}
        $data=(array)$order->get_meta('_vilahub_snapshot');$warnings=array_values(array_filter((array)$order->get_meta('_vilahub_warnings')));
        echo '<section class="vh-hero"><div class="vh-heading"><div><p class="vh-kicker">'.esc_html(self::channel_name($data['channel']??'').' · '.($data['accountName']??'')).'</p><h2>Pedido '.esc_html($data['externalId']??'').'</h2><span class="vh-badge">'.esc_html(self::status_label((string)($data['status']??''))).'</span></div><div><p class="vh-kicker">Total da venda</p><div class="vh-total">'.wp_kses_post(wc_price(($data['totalInCents']??0)/100)).'</div></div></div><dl class="vh-summary"><div><dt>Situação no marketplace</dt><dd>'.esc_html(self::status_label((string)($data['status']??''))).'</dd></div><div><dt>Estoque desta venda</dt><dd>'.esc_html(self::stock_label($order,$data)).'</dd></div><div><dt>Nota fiscal</dt><dd>'.esc_html(self::status_label((string)($data['fiscal']['status']??'NOT_ISSUED'))).'</dd></div></dl>';
        $url=wp_nonce_url(admin_url('admin-post.php?action=vilahub_order_action&operation=refresh&order_id='.$order->get_id()),'vilahub_order_action');echo '<div class="vh-actions"><a class="button" href="'.esc_url($url).'">Atualizar dados do canal</a></div></section>';
        if($warnings){echo '<section class="vh-alert"><h2>O que precisa da sua atenção</h2><ul>';foreach($warnings as $warning)echo '<li>'.esc_html($warning).'</li>';echo '</ul><div class="vh-actions">';
            if(!$order->get_meta('_vilahub_stock_applied'))echo '<a class="button" href="#vincular-produtos">Conferir vínculo dos produtos</a>';
            if(self::needs_baseline($order,$data))echo '<a class="button" href="#conferir-estoque">Conferir baixa de estoque</a>';echo '</div></section>';
        }
        foreach((array)($data['notices']??array()) as $notice)if($notice)echo '<p class="vh-muted">'.esc_html($notice).'</p>';
    }
    private static function documents(VilaHub_Order_Record $order,array $data): void {
        self::panel('Entrega e documentos','entrega-documentos');$tracking=trim((string)($data['tracking']['code']??''));echo '<p><strong>Rastreamento:</strong> '.esc_html($tracking?:'Ainda não disponibilizado pelo canal').'</p><div class="vh-document-actions">';
        if(!empty($data['capabilities']['labelDownload'])){$url=wp_nonce_url(admin_url('admin-post.php?action=vilahub_order_action&operation=download&document=marketplace-label&order_id='.$order->get_id()),'vilahub_order_action');echo '<p><a class="button button-primary" href="'.esc_url($url).'">Obter etiqueta Mercado Livre</a></p>';}
        if(!empty($data['label']['url']))self::link($data['label']['url'],'Imprimir etiqueta');
        if(!empty($data['fiscal']['accessKey'])&&in_array($data['fiscal']['status']??'',array('AUTHORIZED','CANCELED'),true))foreach(array('xml'=>'Baixar XML','danfe'=>'Imprimir DANFE') as $kind=>$label){$url=wp_nonce_url(admin_url('admin-post.php?action=vilahub_order_action&operation=download&document='.$kind.'&order_id='.$order->get_id()),'vilahub_order_action');echo '<p><a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a></p>';}
        foreach(array('xmlPath'=>'XML','pdfPath'=>'DANFE') as $key=>$label)if(!empty($data['fiscal']['document'][$key]))self::link($data['fiscal']['document'][$key],$label);
        echo '</div><p class="vh-muted">Os documentos ficam disponíveis conforme a liberação do marketplace.</p></section>';
    }
    private static function link(string $url,string $label): void {if(wp_parse_url($url,PHP_URL_SCHEME)==='https')echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="'.esc_url($url).'">'.esc_html($label).'</a></p>';}
    public static function review(): void {
        if(!current_user_can('manage_woocommerce'))return;$order=VilaHub_Orders::get(absint($_GET['order_id']??0));if(!VilaHub_Orders::imported($order))return;
        echo '<div class="wrap vilahub-orders"><h1>Pedido do marketplace</h1><p><a class="button" href="'.esc_url(self::list_url()).'">Voltar aos pedidos</a></p>';VilaHub_Marketplace::navigation('vilahub-orders');self::box($order);$data=(array)$order->get_meta('_vilahub_snapshot');
        echo '<div class="vh-grid">';self::panel('Comprador');$customer=array_values(array_filter(array_map('trim',array_map('strval',array_intersect_key((array)($data['customer']??array()),array_flip(array('name','email','phone')))))));if($customer){foreach($customer as $part)echo '<p>'.esc_html($part).'</p>';}else echo '<p class="vh-muted">O marketplace não disponibilizou os dados do comprador para este pedido.</p>';echo '</section>';
        self::panel('Endereço de entrega');$address=array_filter((array)($data['address']??array()),function($value){return is_scalar($value)&&trim((string)$value)!=='';});$meaningful=array_diff_key($address,array_flip(array('country','country_code')));echo '<p'.(!$meaningful?' class="vh-muted"':'').'>'.esc_html($meaningful?implode(', ',$address):'Endereço ainda não disponibilizado pelo canal.').'</p></section></div>';
        self::panel('Itens do pedido');echo '<div class="vh-products"><table class="widefat striped"><thead><tr><th>Produto / SKU</th><th>Quantidade</th><th>Total</th></tr></thead><tbody>';foreach((array)($data['items']??array()) as $line){$cents=$line['lineTotalInCents']??(($line['unitInCents']??0)*($line['quantity']??0));echo '<tr><td>'.esc_html($line['title']??'').'<br><small class="vh-muted">SKU: '.esc_html($line['sku']??'Não informado').'</small></td><td>'.esc_html($line['quantity']??0).'</td><td>'.wp_kses_post(wc_price($cents/100)).'</td></tr>';}echo '</tbody></table></div></section>';
        if(!$order->get_meta('_vilahub_stock_applied')){
            self::panel('1. Vincular produtos desta loja','vincular-produtos');echo '<p>'.(($data['policy']['stockAuthority']??'')==='ERP'?'Vincule os produtos para identificar os itens desta loja. O estoque continua controlado pelo ERP.':'Selecione o produto ou variação desta loja para cada SKU recebido. A confirmação pode iniciar a baixa de estoque de uma venda paga.').'</p>';self::form('link-items',array('order_id'=>$order->get_id()));
            foreach((array)($data['items']??array()) as $index=>$line){$links=(array)$order->get_meta('_vilahub_item_links');$product_id=absint($links[$line['sku']]??wc_get_product_id_by_sku($line['sku']));$product=$product_id?wc_get_product($product_id):false;echo '<div class="vh-product-link"><label for="vh-item-'.esc_attr($index).'">'.esc_html($line['title']).'<br><small>SKU: '.esc_html($line['sku']).'</small></label><select id="vh-item-'.esc_attr($index).'" class="wc-product-search" name="item_links['.esc_attr($index).']" data-action="woocommerce_json_search_products_and_variations" data-placeholder="Pesquisar produto ou SKU">';if($product)echo '<option value="'.esc_attr($product_id).'" selected>'.esc_html($product->get_name().' · '.$product->get_sku()).'</option>';echo '</select></div>';}
            echo '<p><label class="vh-check"><input type="checkbox" name="confirm" value="1" required> Conferi os vínculos e autorizo aplicar a política de estoque desta conta.</label></p><button class="button">Salvar vínculos e atualizar pedido</button></form></section>';
        }
        if(self::needs_baseline($order,$data)){
            self::panel('2. Conferir a baixa de estoque','conferir-estoque');self::form('confirm-stock-baseline',array('order_id'=>$order->get_id()));echo '<p>A baixa automática de vendas anteriores à ativação ou sem data de origem fica bloqueada. Use esta confirmação somente se a venda ainda NÃO foi descontada do estoque atual.</p><label class="vh-check"><input type="checkbox" name="confirm" value="1" required> Conferi que esta venda ainda não foi descontada e autorizo a baixa uma única vez.</label><p><button class="button">Confirmar baixa pendente</button></p></form></section>';
        }
        $returnable=false;foreach($order->get_items() as $return_item)if((int)$return_item->get_meta('_reduced_stock')>0){$returnable=true;break;}
        if($returnable&&($data['policy']['stockAuthority']??'')==='WOOCOMMERCE'&&(in_array($data['inventory']??'',array('REVIEW_RETURN','CANCELLED'),true)||!empty($data['returnAvailable']))){
            self::panel('Devolução de mercadoria');echo '<p>Reponha somente a quantidade fisicamente recebida e em condições de venda. Não realiza reembolso financeiro.</p>';self::form('return-stock',array('order_id'=>$order->get_id(),'request_key'=>wp_generate_uuid4()));
            foreach($order->get_items() as $item_id=>$item){$remaining=(int)$item->get_meta('_reduced_stock');if($remaining>0)echo '<p><label>'.esc_html($item->get_name()).' · máximo '.esc_html($remaining).' <input type="number" min="0" max="'.esc_attr($remaining).'" value="0" name="return_qty['.esc_attr($item_id).']"></label></p>';}
            echo '<p><label><input type="checkbox" name="confirm" value="1" required> Confirmo o recebimento físico das quantidades informadas.</label></p><button class="button">Confirmar reposição</button></form></section>';
        }
        self::documents($order,$data);
        self::panel('Resumo financeiro');echo '<p class="vh-muted">Pagamento administrado pelo canal. Os valores abaixo são os informados pelo marketplace.</p><dl class="vh-financial">';$known=false;foreach(array('commissionInCents'=>'Comissão','shippingInCents'=>'Frete','discountInCents'=>'Desconto','refundedInCents'=>'Reembolso') as $key=>$label)if(isset($data['financial'][$key])){$known=true;echo '<div><dt>'.esc_html($label).'</dt><dd>'.wp_kses_post(wc_price($data['financial'][$key]/100)).'</dd></div>';}echo '</dl>';if(!$known)echo '<p class="vh-muted">O canal ainda não disponibilizou o detalhamento de frete, comissão ou descontos.</p>';echo '</section>';
        self::panel('Nota fiscal','nota-fiscal');echo '<p>Situação: <strong>'.esc_html(self::status_label((string)($data['fiscal']['status']??'NOT_ISSUED'))).'</strong> · Envio ao canal: '.esc_html(self::status_label((string)($data['fiscal']['marketplaceStatus']??'NOT_SENT'))).'</p>';if(!empty($data['fiscal']['error']))echo '<p class="vh-error">'.esc_html($data['fiscal']['error']).'</p>';
        if(!empty($data['capabilities']['externalInvoice'])){
            echo '<details><summary>Enviar nota já emitida (XML)</summary><h3>Nota emitida em outro sistema</h3><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('vilahub_order_action');echo '<input type="hidden" name="action" value="vilahub_order_action"><input type="hidden" name="operation" value="external-invoice"><input type="hidden" name="order_id" value="'.esc_attr($order->get_id()).'"><p><input type="file" name="invoice_xml" accept=".xml" required></p>'.(($data['channel']??'')==='TIKTOK'?'<p><label>Pacote TikTok <input name="package_id"></label></p>':'').'<p><label><input type="checkbox" name="confirm" value="1" required> Confirmo o XML autorizado, o pedido e os dados fiscais.</label></p><button class="button">Vincular e enviar XML ao canal</button></form></details>';
        }
        if(!empty($data['capabilities']['fulfillment'])){
            try{$fulfillment=VilaHub_Orders::request('GET','/'.rawurlencode($data['id']).'/fulfillment');echo '<h2>Pacotes TikTok</h2><p>'.esc_html($fulfillment['message']??'').'</p>';foreach((array)($fulfillment['packages']??array()) as $package)echo '<p>Pacote: '.esc_html(is_string($package)?$package:($package['id']??$package['package_id']??'')).'</p>';self::form('tiktok-label',array('order_id'=>$order->get_id()));echo '<p><label>ID do pacote <input name="package_id" required></label></p><button class="button">Obter etiqueta do pacote</button></form>';}catch(Throwable $e){echo '<p>'.esc_html($e->getMessage()).'</p>';}
        }
        if(!empty($data['capabilities']['invoice'])){echo '<details><summary>Conferir dados fiscais dos produtos</summary>';self::form('sync-fiscal-products',array('order_id'=>$order->get_id()));echo '<p>Cadastre NCM e unidade fiscal em Produtos → Editar produto e sincronize o cadastro antes da emissão.</p><button class="button">Sincronizar cadastro dos produtos deste pedido</button></form></details>';}
        if(!empty($data['capabilities']['invoice'])){
            if(sanitize_key(wp_unslash($_GET['invoice_preview']??''))!=='1')echo '<p><a class="button button-primary" href="'.esc_url(add_query_arg('invoice_preview','1',$order->get_edit_order_url()).'#nota-fiscal').'">Emitir NF-e</a></p>';
            else try{$preview=VilaHub_Orders::request('GET','/'.rawurlencode($data['id']).'/invoice-preview');echo '<h2>Conferência fiscal</h2><p>'.esc_html($preview['warning']??'').'</p>';foreach((array)($preview['blockers']??array()) as $blocker)echo '<p style="color:#b32d2e">'.esc_html($blocker).'</p>';if(!empty($preview['issuer']))echo '<p>Emitente: '.esc_html($preview['issuer']['legalName'].' · '.$preview['issuer']['cnpj'].' · '.$preview['issuer']['environment']).'</p>';
                if(!empty($preview['payload'])){$payload=$preview['payload'];echo '<h3>Destinatário</h3><p>'.esc_html(($payload['recipient']['name']??'').' · '.($payload['recipient']['document']??'')).'</p><p>'.esc_html(implode(', ',array_filter((array)($payload['address']??array()),'is_scalar'))).'</p><table class="widefat striped"><thead><tr><th>SKU</th><th>Produto</th><th>Qtd.</th><th>NCM</th><th>Total</th></tr></thead><tbody>';foreach((array)($payload['items']??array()) as $item)echo '<tr><td>'.esc_html($item['sku']).'</td><td>'.esc_html($item['description']).'</td><td>'.esc_html($item['quantity']).'</td><td>'.esc_html($item['ncm']).'</td><td>'.wp_kses_post(wc_price($item['totalValue'])).'</td></tr>';echo '</tbody></table><p>Total NF-e: '.wp_kses_post(wc_price($payload['totals']['invoice']??0)).' · Frete: '.wp_kses_post(wc_price($payload['totals']['freight']??0)).' · Desconto: '.wp_kses_post(wc_price($payload['totals']['discount']??0)).'</p>';}
                if(!empty($preview['ready'])&&($data['fiscal']['status']??'')!=='AUTHORIZED'&&($data['fiscal']['status']??'')!=='PROCESSING'&&($data['fiscal']['status']??'')!=='CANCELED'){self::form('invoice',array('order_id'=>$order->get_id()));echo '<p><label><input type="checkbox" name="confirm" value="1" required> Conferi os dados e autorizo a emissão da NF-e no ambiente indicado.</label></p><button class="button button-primary">Emitir NF-e</button></form>';}
            }catch(Throwable $e){echo '<p>'.esc_html($e->getMessage()).'</p>';}
            echo '<details><summary>Consultar nota ou reenviar ao canal</summary>';foreach(array('refresh-invoice'=>'Consultar NF-e','resend-invoice'=>'Reenviar NF-e ao canal','label'=>'Solicitar etiqueta') as $action=>$label){self::form($action,array('order_id'=>$order->get_id()));echo '<p><button class="button">'.esc_html($label).'</button></p></form>';}echo '</details>';
        }elseif(empty($data['capabilities']['externalInvoice']))echo '<p>A emissão de NF-e por este canal não está disponível no plugin. Documentos disponibilizados pelo canal podem ser consultados acima.</p>';
        echo '</section>';if($order->get_notes()){echo '<details class="vh-card"><summary>Histórico do pedido</summary>';foreach($order->get_notes() as $note)echo '<p class="vh-note"><small>'.esc_html($note['at']).'</small>'.esc_html($note['text']).'</p>';echo '</details>';}echo '</div>';
    }
    private static function clean_text($value,int $max=200,bool $textarea=false): string {
        if(!is_scalar($value)||is_bool($value))return '';
        $text=$textarea?sanitize_textarea_field((string)$value):sanitize_text_field((string)$value);
        return VilaHub_Product::truncate($text,$max);
    }
    private static function fiscal_input(array $input): array {
        $source=isset($input['fiscal'])&&is_array($input['fiscal'])?$input['fiscal']:array();
        $fields=array(
            'cnpj'=>32,'legalName'=>200,'tradeName'=>200,'stateRegistration'=>64,'municipalRegistration'=>64,'taxRegime'=>64,
            'street'=>200,'addressNumber'=>32,'addressComplement'=>160,'district'=>120,'city'=>120,'stateCode'=>2,'cityCode'=>16,'postalCode'=>16,'phone'=>40,
            'operationNature'=>160,'cfopInState'=>8,'cfopOutState'=>8,'productOrigin'=>8,'icmsCode'=>16,'pisCode'=>16,'cofinsCode'=>16,
            'freightMode'=>32,'paymentMethod'=>32,'accountantName'=>200,'accountantPhone'=>40,'sefazNotes'=>1000
        );
        $clean=array();
        foreach($fields as $key=>$max)$clean[$key]=self::clean_text($source[$key]??'', $max, $key==='sefazNotes');
        $accountant_email=isset($source['accountantEmail'])&&is_scalar($source['accountantEmail'])?trim((string)$source['accountantEmail']):'';
        $clean['accountantEmail']=(is_email($accountant_email)&&sanitize_email($accountant_email)===$accountant_email)?$accountant_email:'';
        $environment=self::clean_text($source['environment']??'',16);
        $clean['environment']=in_array($environment,array('HOMOLOGATION','PRODUCTION'),true)?$environment:'HOMOLOGATION';
        $sefaz_status=self::clean_text($source['sefazStatus']??'',32);
        $clean['sefazStatus']=in_array($sefaz_status,array('PENDING_ACCOUNTANT','IN_PROGRESS','VERIFIED'),true)?$sefaz_status:'PENDING_ACCOUNTANT';
        $clean['active']=!empty($source['active']);
        return $clean;
    }
    private static function positive_integer_map($value): array {
        $clean=array();
        if(!is_array($value))return $clean;
        foreach($value as $key=>$item){
            if(!is_scalar($item)||is_bool($item))continue;
            $id_text=(string)$key;$quantity_text=trim((string)$item);
            if(!preg_match('/^\d+$/',$id_text)||!preg_match('/^\d+$/',$quantity_text))continue;
            $id=(int)$id_text;$quantity=(int)$quantity_text;
            if($id&&$quantity)$clean[(string)$id]=$quantity;
        }
        return $clean;
    }
    private static function product_map($value): array {
        $clean=array();
        if(!is_array($value))return $clean;
        foreach($value as $index=>$product_id){
            if(!is_scalar($product_id)||is_bool($product_id))continue;
            $line_text=(string)$index;$product_text=trim((string)$product_id);
            if(!preg_match('/^\d+$/',$line_text)||!preg_match('/^\d+$/',$product_text))continue;
            $line=(int)$line_text;$id=(int)$product_text;
            if($id)$clean[$line]=$id;
        }
        return $clean;
    }
    private static function certificate_password(array $input): string {
        $password=$input['certificate_password']??'';
        if(!is_string($password)||$password===''||strlen($password)>1024)throw new RuntimeException('Informe uma senha válida para o certificado.');
        return $password;
    }
    private static function uploaded_file(string $field,array $extensions): array {
        // PHP provides this upload record; validate it before reading any path or binary data.
        $file=$_FILES[$field]??null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified by handle(); the upload is validated below by source, extension and byte limits.
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_string($file['name']??null))throw new RuntimeException('Selecione um arquivo válido de até 150 KB.');
        $name=sanitize_file_name($file['name']);
        if(!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),$extensions,true)||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('O arquivo enviado não é válido para esta operação.');
        $size=filesize($file['tmp_name']);if($size===false||$size<1||$size>150000)throw new RuntimeException('O arquivo deve ter entre 1 byte e 150 KB.');
        $bytes=file_get_contents($file['tmp_name'],false,null,0,150001);if($bytes===false||strlen($bytes)!==$size||strlen($bytes)>150000)throw new RuntimeException('Não foi possível conferir o arquivo enviado.');
        return array('name'=>$name,'bytes'=>$bytes);
    }
    private static function download_document(array $document,string $kind): void {
        $bytes=$kind!=='xml'?base64_decode($document['pdfBase64']??'',true):($document['xml']??'');if(!$bytes)throw new RuntimeException('Documento ainda indisponível.');
        nocache_headers();header('Content-Type: '.($kind!=='xml'?'application/pdf':'application/xml').'; charset=utf-8');header('Content-Disposition: attachment; filename="'.sanitize_file_name($document['fileName']??'documento'). '"');header('X-Content-Type-Options: nosniff');
        // This is an authenticated attachment response, never an HTML fragment. Escaping corrupts XML/PDF bytes.
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary/XML download with attachment disposition and nosniff.
        exit;
    }
    public static function handle(): void {
        if(!current_user_can('manage_woocommerce'))wp_die('Sem permissão.');check_admin_referer('vilahub_order_action');
        $method=strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']??'GET')));$input=$method==='POST'?wp_unslash($_POST):wp_unslash($_GET);$operation=sanitize_key(self::clean_text($input['operation']??'',80));if($method!=='POST'&&!in_array($operation,array('download','refresh'),true))wp_die('Use o formulário para esta operação.','',array('response'=>405));$order_id=absint($input['order_id']??0);$return_page=sanitize_key(self::clean_text($input['return_page']??'',32));$connection_id=sanitize_text_field(self::clean_text($input['connection_id']??'',160));$target=$return_page==='fiscal'?add_query_arg('connection_id',$connection_id,self::fiscal_url()):admin_url('admin.php?page=vilahub-order-settings');
        try{
            if($operation==='retry-pending'){VilaHub_Orders::retry_pending();}
            elseif($operation==='configure'){
                if(!current_user_can('manage_options'))throw new RuntimeException('Somente administradores podem configurar.');
                $id=$connection_id;$target.='#conta-'.rawurlencode($id);$stock_authority=self::clean_text($input['stock_authority']??'',16);if(!in_array($stock_authority,array('WOOCOMMERCE','ERP'),true))throw new RuntimeException('Escolha quem controla o estoque.');$policy=VilaHub_Orders::request('POST','/accounts/'.rawurlencode($id),array('active'=>!empty($input['active']),'stockAuthority'=>$stock_authority,'siteId'=>get_option('vilahub_site_id'),'confirm'=>!empty($input['confirm'])));
                $accounts=(array)get_option('vilahub_order_accounts',array());if(!empty($policy['active']))update_option('vilahub_order_stock_authority',$policy['stockAuthority'],false);$accounts[$id]=array_merge($policy,array('name'=>self::clean_text($input['name']??'',200),'since'=>$policy['activatedAt'],'cursor'=>null));update_option('vilahub_order_accounts',$accounts,false);
            }elseif(in_array($operation,array('fiscal-config','certificate','fiscal-series'),true)){
                if(!current_user_can('manage_options'))throw new RuntimeException('Somente administradores podem configurar dados fiscais.');
                $id=$connection_id;if($return_page!=='fiscal')$target=add_query_arg('fiscal_account',$id,$target).'#conta-'.rawurlencode($id);
                if($operation==='fiscal-config'){VilaHub_Orders::request('POST','/accounts/'.rawurlencode($id).'/fiscal',self::fiscal_input($input));}
                elseif($operation==='certificate'){$file=self::uploaded_file('pfx',array('pfx','p12'));VilaHub_Orders::request('POST','/accounts/'.rawurlencode($id).'/certificate',array('fileName'=>$file['name'],'pfxBase64'=>base64_encode($file['bytes']),'password'=>self::certificate_password($input)));}
                else VilaHub_Orders::request('POST','/accounts/'.rawurlencode($id).'/fiscal/series',array('series'=>absint($input['series']??0),'nextNumber'=>absint($input['next_number']??0),'active'=>true));
            }elseif(in_array($operation,array('sync','sync-list'),true)){
                $id=$connection_id;
                if($operation==='sync')$target.='#conta-'.rawurlencode($id);else $target=self::list_url();
                $accounts=(array)get_option('vilahub_order_accounts',array());$remote=array();foreach(VilaHub_Orders::request('GET','/accounts') as $item)$remote[(string)$item['id']]=$item;
                $ids=$id?array($id):array_values(array_unique(array_merge(array_keys(array_filter($accounts,static function($config){return !empty($config['active']);})),array_keys(array_filter($remote,static function($item){return !empty($item['active']);})))));
                if(!$ids)throw new RuntimeException('Ative o recebimento de pedidos em pelo menos uma conta.');
                foreach($ids as $account_id){
                    $account_id=(string)$account_id;$local=$accounts[$account_id]??array();$current=$remote[$account_id]??array();
                    if(empty($current['active'])&&!empty($local['active'])&&!empty($current['siteId'])&&hash_equals((string)$current['siteId'],(string)get_option('vilahub_site_id'))){
                        $policy=VilaHub_Orders::request('POST','/accounts/'.rawurlencode($account_id),array('active'=>true,'stockAuthority'=>(string)($local['stockAuthority']??'WOOCOMMERCE'),'siteId'=>get_option('vilahub_site_id'),'confirm'=>true));
                        $activated=strtotime((string)$policy['activatedAt']);$accounts[$account_id]=array_merge($local,$policy,array('cursor'=>null,'since'=>gmdate('c',($activated?:time())-2592000)));update_option('vilahub_order_accounts',$accounts,false);
                    }
                    VilaHub_Orders::request('POST','/accounts/'.rawurlencode($account_id).'/sync',array());
                }
                VilaHub_Orders::poll();
                $requested=time();foreach(array(10,30,60) as $delay)as_schedule_single_action($requested+$delay,'vilahub_orders_poll',array('manual-'.$requested.'-'.$delay),'vilahub',true);
            }
            else{$order=VilaHub_Orders::get($order_id);if(!VilaHub_Orders::imported($order)||$order->get_meta('_vilahub_tenant')!==VilaHub_Store::settings()['tenant_id'])throw new RuntimeException('Pedido inválido.');$target=$order->get_edit_order_url();$remote=rawurlencode($order->get_meta('_vilahub_remote_id'));
                if($operation==='download'){$kind=in_array($input['document']??'',array('danfe','marketplace-label'),true)?$input['document']:'xml';self::download_document(VilaHub_Orders::request('GET','/'.$remote.'/'.$kind),$kind);}
                if($operation==='confirm-stock-baseline'){if(empty($input['confirm']))throw new RuntimeException('Confirme somente uma baixa pendente.');VilaHub_Orders::update_pending($order_id,function($pending){$pending->update_meta_data('_vilahub_stock_baseline_confirmed',true);$pending->add_order_note('O responsável confirmou que esta venda ainda não estava descontada do estoque atual.');});}
                elseif($operation==='sync-fiscal-products'){foreach($order->get_items() as $item){$product=$item->get_product();if($product)VilaHub_Sync::enqueue($product->is_type('variation')?$product->get_parent_id():$product->get_id(),true);}}
                elseif($operation==='link-items'){if(empty($input['confirm']))throw new RuntimeException('Confirme os vínculos antes da baixa.');$item_links=self::product_map($input['item_links']??array());VilaHub_Orders::update_pending($order_id,function($pending)use($item_links){$snapshot=(array)$pending->get_meta('_vilahub_snapshot');$links=(array)$pending->get_meta('_vilahub_item_links');foreach($item_links as $index=>$product_id){$line=$snapshot['items'][(int)$index]??null;$product=wc_get_product($product_id);if(!$line||!$product||$product->is_type('variable'))throw new RuntimeException('Selecione produto ou variação válido.');$links[$line['sku']]=$product->get_id();}$pending->update_meta_data('_vilahub_item_links',$links);});}
                elseif($operation==='return-stock'){VilaHub_Orders::confirm_return($order_id,self::positive_integer_map($input['return_qty']??array()),!empty($input['confirm']),self::clean_text($input['request_key']??'',100));}
                elseif($operation==='external-invoice'){$file=self::uploaded_file('invoice_xml',array('xml'));$xml=$file['bytes'];VilaHub_Orders::request('POST','/'.$remote.'/external-invoice',array('xml'=>$xml,'confirm'=>!empty($input['confirm']),'packageId'=>self::clean_text($input['package_id']??'',200)));}
                elseif($operation!=='refresh')VilaHub_Orders::request('POST','/'.$remote.'/actions',array('action'=>$operation,'confirm'=>!empty($input['confirm']),'packageId'=>self::clean_text($input['package_id']??'',200)));VilaHub_Orders::import(VilaHub_Orders::request('GET','/'.$remote));}
            if(in_array($operation,array('sync','sync-list'),true))set_transient('vilahub_notice_'.get_current_user_id(),array('message'=>'Atualização iniciada. Os pedidos serão recebidos em segundo plano; recarregue esta tela em alguns instantes.','error'=>false),60);
            if(in_array($operation,array('configure','fiscal-config','certificate','fiscal-series'),true))set_transient('vilahub_notice_'.get_current_user_id(),array('message'=>'Configuração salva com sucesso.','error'=>false),60);
        }catch(Throwable $e){set_transient('vilahub_notice_'.get_current_user_id(),array('message'=>$e->getMessage(),'error'=>true),60);}
        wp_safe_redirect($target);exit;
    }
}
