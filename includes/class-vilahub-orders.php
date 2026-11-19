<?php
defined('ABSPATH') || exit;

// Order synchronization uses plugin-owned tables and atomic prepared writes.
// Caching these queue reads could import or apply stock more than once.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Marketplace orders use private storage; WooCommerce supplies products and stock only. */
final class VilaHub_Orders {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'vilahub_orders'; }
    public static function install(): void {
        VilaHub_Order_Record::install();
        global $wpdb; $table=self::table(); $charset=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            remote_id varchar(100) NOT NULL,
            tenant_id varchar(100) NOT NULL,
            connection_id varchar(100) NOT NULL,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            revision varchar(40) NOT NULL DEFAULT '',
            error_message text NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (tenant_id,remote_id),
            UNIQUE KEY local_order (order_id,remote_id),
            KEY account (connection_id)
        ) $charset;");
    }
    public static function imported($order): bool {return $order instanceof VilaHub_Order_Record;}
    public static function get(int $id): ?VilaHub_Order_Record {
        $order=VilaHub_Order_Record::get($id);
        return $order && $order->get_meta('_vilahub_tenant')===VilaHub_Store::settings()['tenant_id']?$order:null;
    }
    public static function boot(): void {
        add_action('vilahub_orders_poll',array(__CLASS__,'poll'));
        add_action('vilahub_order_pull',array(__CLASS__,'pull'),10,4);
        // Native store sales also update marketplace stock, without enabling whole-catalog import.
        foreach(array('woocommerce_product_set_stock','woocommerce_variation_set_stock') as $hook)add_action($hook,function($product){
            if(!VilaHub_Store::available()||VilaHub_Store::settings()['automatic']||get_option('vilahub_order_stock_authority')!=='WOOCOMMERCE')return;
            $active=false;foreach((array)get_option('vilahub_order_accounts',array()) as $account)if(!empty($account['active'])){$active=true;break;}if(!$active)return;
            $id=$product->is_type('variation')?$product->get_parent_id():$product->get_id();$row=VilaHub_Store::row($id);if(empty($row['remote_id']))return;
            try{VilaHub_Sync::enqueue($id);}catch(Throwable $error){/* A falha fica visível na fila do VilaHub sem expor dados no log do servidor. */}
        },30);
        add_action('init',function(){ if(function_exists('as_has_scheduled_action')&&!as_has_scheduled_action('vilahub_orders_poll',array(),'vilahub'))as_schedule_recurring_action(time()+60,120,'vilahub_orders_poll',array(),'vilahub',true); });
        add_action('init',array('VilaHub_Order_Migration','run'),5);
        VilaHub_Order_Admin::boot();
    }
    public static function request(string $method,string $path,?array $body=null): array {
        if(!VilaHub_Store::available())throw new RuntimeException('Conecte uma autorização ativa desta loja antes de gerenciar pedidos.');
        $response=(new VilaHub_Client())->request($method,'/marketplace/orders'.$path,$body,$method==='GET'?array():array('Idempotency-Key'=>wp_generate_uuid4()));
        return is_array($response['data']??null)?$response['data']:array();
    }
    public static function billing(): array {
        if(!VilaHub_Store::available())throw new RuntimeException('Conecte uma autorização ativa desta loja antes de consultar o financeiro.');
        $response=(new VilaHub_Client())->request('GET','/billing');
        return is_array($response['data']??null)?$response['data']:array();
    }
    private static function lock(string $key): string {
        global $wpdb; $name='vh-order-'.substr(hash('sha256',$key),0,48);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))!==1)throw new RuntimeException('Pedido em processamento.');return $name;
    }
    private static function unlock(string $name): void {global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
    private static function persist(VilaHub_Order_Record $order): void {$order->save();}
    private static function flush_stock_push(VilaHub_Order_Record $order): void {
        if(!$order->get_meta('_vilahub_stock_push_pending'))return;
        // Exceptions are internal data and are escaped by admin notices, not HTML output here.
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        foreach($order->get_items() as $item){$product=$item->get_product();if(!$product)continue;$parent=$product->is_type('variation')?$product->get_parent_id():$product->get_id();$binding=VilaHub_Store::row($parent);if(empty($binding['remote_id']))throw new RuntimeException('O estoque local foi atualizado. Confirme o vínculo do SKU '.$product->get_sku().' com o catálogo antes de propagar o saldo aos canais.');VilaHub_Sync::enqueue($product->get_id());}
        $order->delete_meta_data('_vilahub_stock_push_pending');self::persist($order);
    }
    public static function import(array $data): int {
        VilaHub_Order_Migration::ensure_complete();
        global $wpdb; $settings=VilaHub_Store::settings(); $tenant=$settings['tenant_id'];
        if(untrailingslashit(home_url())!==get_option('vilahub_site_origin'))throw new RuntimeException('A origem da loja mudou. Revise a autorização antes de importar.');
        if(!$tenant || empty($data['id']) || empty($data['connectionId']) || ($data['currency']??'')!=='BRL' || !isset($data['policy']['siteId']) || $data['policy']['siteId']!==get_option('vilahub_site_id') || ($data['policy']['credentialId']??'')!==$settings['credential_id'])throw new RuntimeException('Pedido fora da autorização desta loja.');
        $enabled=(array)get_option('vilahub_order_accounts',array());
        if(empty($enabled[$data['connectionId']]['active']))throw new RuntimeException('Ative os pedidos desta conta antes de importar.');
        if(isset($enabled[$data['connectionId']]['stockAuthority'])&&($enabled[$data['connectionId']]['stockAuthority']!==$data['policy']['stockAuthority']||$enabled[$data['connectionId']]['activatedAt']!==$data['policy']['activatedAt']))throw new RuntimeException('A política de estoque mudou. Consulte o pedido novamente.');
        $revision=(string)($data['updatedAt']??'');$at=$revision?(float)(new DateTimeImmutable($revision))->format('U.u'):0;
        if(!$at)throw new RuntimeException('Pedido sem revisão válida.');
        $lock=self::lock($tenant.':'.$data['id']);$order=null;$product_ids=array();$stock_changed=false;
        try {
            wc_transaction_query('start');
            $order=VilaHub_Order_Record::find($tenant,$data['id']);$stored=$order?(array)$order->get_meta('_vilahub_snapshot'):array();
            if($order&&!empty($stored['updatedAt'])&&(float)(new DateTimeImmutable($stored['updatedAt']))->format('U.u')>$at){wc_transaction_query('commit');return $order->get_id();}
            if(!$order){$order=new VilaHub_Order_Record();$order->set_status('on-hold');$order->update_meta_data('_vilahub_tenant',$tenant);$order->update_meta_data('_vilahub_remote_id',$data['id']);}
            $warnings=(array)($data['warnings']??array());$lines=(array)($data['items']??array());$resolved=array();$links=(array)$order->get_meta('_vilahub_item_links');
            if(!$lines)$warnings[]='Pedido sem itens válidos para conferir estoque.';
            foreach($lines as $line){if(!is_numeric($line['quantity']??null)||(float)$line['quantity']<1||(float)$line['quantity']!==floor((float)$line['quantity']))$warnings[]='Quantidade inválida em um item do pedido.';$linked=$links[(string)($line['sku']??'')]??0;$id=$linked?absint($linked):(!empty($line['sku'])?wc_get_product_id_by_sku((string)$line['sku']):0);$product=$id?wc_get_product($id):false;
                if(!$product||$product->is_type('variable')||get_post_status($id)==='trash'){$warnings[]='Sem vínculo exato para SKU '.($line['sku']??'não informado').'.';$resolved[]=null;continue;}
                $resolved[]=$product;$product_ids[]=$product->get_id();
                if(($data['policy']['stockAuthority']??'')==='WOOCOMMERCE' && (!$product->managing_stock()||$product->get_stock_managed_by_id()!==$product->get_id()))$warnings[]='Configure estoque próprio no SKU '.$line['sku'].'.';
            }
            $signature=hash('sha256',wp_json_encode($lines));$previous=$order->get_meta('_vilahub_items_signature');
            if($previous&&$previous!==$signature&&$order->get_meta('_vilahub_stock_applied'))$warnings[]='Itens alterados após baixa de estoque. Confira quantidades antes de ajustar manualmente.';
            elseif(!$order->get_meta('_vilahub_stock_applied')) {
                $items=array();
                foreach($lines as $i=>$line)$items[$i+1]=array('product_id'=>$resolved[$i]?$resolved[$i]->get_id():0,'name'=>(string)($line['title']??$line['sku']),'quantity'=>max(1,(int)($line['quantity']??0)),'meta'=>array('_vilahub_sku'=>(string)($line['sku']??'')));
                $order->replace_items($items);
                $order->update_meta_data('_vilahub_items_signature',$signature);
            }
            $order->update_meta_data('_vilahub_channel',(string)$data['channel']);$order->update_meta_data('_vilahub_connection',(string)$data['connectionId']);$order->update_meta_data('_vilahub_account',(string)$data['accountName']);$order->update_meta_data('_vilahub_external_id',(string)$data['externalId']);
            $created=strtotime((string)($data['sourceCreatedAt']??''));$activated=strtotime((string)$data['policy']['activatedAt']);
            $eligible=($created && $created >= $activated)||$order->get_meta('_vilahub_stock_baseline_confirmed');
            if(!$eligible && !$order->get_meta('_vilahub_stock_applied'))$warnings[]='Venda anterior à ativação ou sem data de origem: estoque não alterado.';
            $old_status=$order->get_status();$incoming=(string)($data['wooStatus']??'on-hold');
            if(in_array($old_status,array('completed','cancelled','refunded'),true)&&in_array($incoming,array('on-hold','processing'),true)){$incoming=$old_status;$warnings[]='O canal retornou uma situação anterior. Confira o status original.';}
            if(!empty($data['fulfillment']['shipped']))$order->update_meta_data('_vilahub_ever_shipped',true);
            $order->update_meta_data('_vilahub_snapshot',$data);$order->update_meta_data('_vilahub_warnings',array_values(array_unique($warnings)));
            $order->set_status($warnings&&!in_array($old_status,array('completed','cancelled','refunded'),true)?'on-hold':$incoming);self::persist($order);
            if(!$warnings && $eligible && ($data['policy']['stockAuthority']??'')==='WOOCOMMERCE') {
                if(($data['inventory']??'')==='CONSUME'&&!$order->get_meta('_vilahub_stock_applied')){self::change_stock($order,'decrease');$stock_changed=true;$order->update_meta_data('_vilahub_stock_applied',true);self::persist($order);}
                elseif(($data['inventory']??'')==='CANCELLED'&&$order->get_meta('_vilahub_stock_applied')&&!$order->get_meta('_vilahub_stock_restored')){
                    if($order->get_meta('_vilahub_ever_shipped')||in_array($old_status,array('completed','refunded'),true)){$warnings[]='Cancelamento após entrega: confirme a devolução antes de repor estoque.';$order->update_meta_data('_vilahub_warnings',$warnings);}
                    else{self::change_stock($order,'increase');$stock_changed=true;$order->update_meta_data('_vilahub_stock_restored',true);}self::persist($order);
                }
            }
            if(($data['policy']['stockAuthority']??'')==='WOOCOMMERCE'){foreach(array_unique($product_ids) as $changed_id){$current_product=wc_get_product($changed_id);if($current_product&&$current_product->get_stock_quantity()<0)$warnings[]='Estoque negativo no SKU '.$current_product->get_sku().'. Confira o saldo e os canais de venda.';}$warnings=array_values(array_unique($warnings));$order->update_meta_data('_vilahub_warnings',$warnings);self::persist($order);}
            if($stock_changed){$order->update_meta_data('_vilahub_stock_push_pending',true);self::persist($order);}
            $saved=$wpdb->replace(self::table(),array('tenant_id'=>$tenant,'remote_id'=>$data['id'],'connection_id'=>$data['connectionId'],'order_id'=>$order->get_id(),'revision'=>$revision,'updated_at'=>gmdate('Y-m-d H:i:s'),'error_message'=>$warnings?implode(' ',$warnings):null));
            if($saved===false)throw new RuntimeException('Não foi possível salvar o vínculo do pedido.');
            wc_transaction_query('commit');
            if(($data['policy']['stockAuthority']??'')==='WOOCOMMERCE')self::flush_stock_push($order);
            return $order->get_id();
        }catch(Throwable $e){wc_transaction_query('rollback');foreach(array_unique($product_ids) as $changed_id){clean_post_cache($changed_id);wc_delete_product_transients($changed_id);}throw $e;}finally{self::unlock($lock);}
    }
    private static function change_stock(VilaHub_Order_Record $order,string $operation): void {
        foreach($order->get_items() as $item){
            $product=$item->get_product();if(!$product||!$product->managing_stock()||$product->get_stock_managed_by_id()!==$product->get_id())throw new RuntimeException('Confira o vínculo e o estoque próprio dos itens.');
            $quantity=$operation==='decrease'?$item->get_quantity():(int)$item->get_meta('_reduced_stock');if(!$quantity)continue;
            $result=wc_update_product_stock($product,$quantity,$operation);if(is_wp_error($result)||!is_numeric($result))throw new RuntimeException('Não foi possível atualizar o estoque.');
            $item->update_meta_data('_reduced_stock',$operation==='decrease'?$quantity:0);$item->save();
        }
    }
    public static function update_pending(int $id,callable $update): void {
        $order=self::get($id);if(!$order)throw new RuntimeException('Pedido inválido.');$lock=self::lock($order->get_meta('_vilahub_tenant').':'.$order->get_meta('_vilahub_remote_id'));
        try{wc_transaction_query('start');$order=self::get($id);if(!$order||$order->get_meta('_vilahub_stock_applied'))throw new RuntimeException('A baixa deste pedido já foi registrada. Atualize a tela.');$update($order);self::persist($order);wc_transaction_query('commit');}
        catch(Throwable $e){wc_transaction_query('rollback');throw $e;}finally{self::unlock($lock);}
    }
    public static function confirm_return(int $id,array $quantities,bool $confirmed,string $request_key): void {
        global $wpdb;$order=self::get($id);$settings=VilaHub_Store::settings();
        if(!preg_match('/^[a-zA-Z0-9-]{20,64}$/',$request_key))throw new RuntimeException('Identificação da reposição inválida.');
        if(!$confirmed||!self::imported($order)||$order->get_meta('_vilahub_tenant')!==$settings['tenant_id'])throw new RuntimeException('Confirme a devolução física do pedido desta loja.');
        $lock=self::lock($settings['tenant_id'].':'.$order->get_meta('_vilahub_remote_id'));
        try{
            wc_transaction_query('start');$order=self::get($id);if(!$order)throw new RuntimeException('Pedido indisponível.');$operations=(array)$order->get_meta('_vilahub_return_operations');if(in_array($request_key,$operations,true)){wc_transaction_query('commit');self::flush_stock_push($order);return;}if(count($operations)>500)throw new RuntimeException('Confira o histórico de devoluções deste pedido.');$snapshot=(array)$order->get_meta('_vilahub_snapshot');
            if(get_option('vilahub_order_stock_authority','WOOCOMMERCE')!=='WOOCOMMERCE'||($snapshot['policy']['stockAuthority']??'')!=='WOOCOMMERCE'||(!in_array($snapshot['inventory']??'',array('REVIEW_RETURN','CANCELLED'),true)&&empty($snapshot['returnAvailable'])))throw new RuntimeException('Reposição disponível somente para devolução/cancelamento e estoque WooCommerce.');
            $changed=array();$count=0;
            foreach($order->get_items() as $item_id=>$item){$qty=$quantities[$item_id]??0;if(!is_numeric($qty)||(float)$qty!==floor((float)$qty)||(float)$qty<0)throw new RuntimeException('Quantidade de devolução inválida.');$qty=(int)$qty;if(!$qty)continue;
                $remaining=(int)$item->get_meta('_reduced_stock');$product=$item->get_product();if(!$product||$qty>$remaining)throw new RuntimeException('Quantidade maior que a baixa ainda não reposta.');
                $changed[]=$product->get_id();$stock=wc_update_product_stock($product,$qty,'increase');if(is_wp_error($stock)||!is_numeric($stock))throw new RuntimeException('Não foi possível repor o estoque.');
                $item->update_meta_data('_reduced_stock',$remaining-$qty);$item->update_meta_data('_vilahub_returned_qty',(int)$item->get_meta('_vilahub_returned_qty')+$qty);$item->save();$changed[]=$product->get_id();$count+=$qty;
            }
            if(!$count)throw new RuntimeException('Informe ao menos uma quantidade recebida.');
            $operations[]=$request_key;$order->update_meta_data('_vilahub_return_operations',$operations);$order->update_meta_data('_vilahub_stock_push_pending',true);self::persist($order);
            $order->add_order_note('Recebimento físico confirmado no plugin. Quantidade reposta: '.$count.'. Sem reembolso financeiro automático.');
            wc_transaction_query('commit');self::flush_stock_push($order);
        }catch(Throwable $e){wc_transaction_query('rollback');foreach($changed??array() as $changed_id){clean_post_cache($changed_id);wc_delete_product_transients($changed_id);}throw $e;}finally{self::unlock($lock);}
    }
    public static function pull(string $remote_id,string $connection_id,string $revision,int $attempt=0): void {
        global $wpdb;if(!VilaHub_Store::available())return;$accounts=(array)get_option('vilahub_order_accounts',array());if(empty($accounts[$connection_id]['active']))return;
        $tenant=VilaHub_Store::settings()['tenant_id'];
        try{
            $detail=self::request('GET','/'.rawurlencode($remote_id));if(($detail['connectionId']??'')!==$connection_id)throw new RuntimeException('A conta do pedido não corresponde à tarefa.');
            $id=self::import($detail);
            (new VilaHub_Client())->request('POST','/orders/'.rawurlencode($remote_id).'/acknowledgement',array('externalId'=>(string)(self::get($id)->get_meta('_vilahub_legacy_order_id')?:$id)),array('Idempotency-Key'=>wp_generate_uuid4()));
        }catch(Throwable $error){
            $message=VilaHub_Product::truncate($error->getMessage(),400);
            $wpdb->query($wpdb->prepare('INSERT INTO %i (remote_id,tenant_id,connection_id,order_id,revision,error_message,updated_at) VALUES (%s,%s,%s,0,%s,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE error_message=VALUES(error_message),updated_at=UTC_TIMESTAMP()',self::table(),$remote_id,$tenant,$connection_id,$revision,$message));
            if($attempt<5)as_schedule_single_action(time()+min(1800,60*(2**$attempt)),'vilahub_order_pull',array($remote_id,$connection_id,$revision,$attempt+1),'vilahub',true);
        }
    }
    public static function retry_pending(): void {
        global $wpdb;$tenant=VilaHub_Store::settings()['tenant_id'];$rows=$wpdb->get_results($wpdb->prepare('SELECT remote_id,connection_id,revision FROM %i WHERE tenant_id=%s AND error_message IS NOT NULL ORDER BY updated_at ASC LIMIT 50',self::table(),$tenant),ARRAY_A);
        foreach($rows as $row)as_schedule_single_action(time()+1,'vilahub_order_pull',array($row['remote_id'],$row['connection_id'],$row['revision'],0),'vilahub',true);
    }
    public static function poll(): void {
        if(!VilaHub_Store::available())return;
        $settings=VilaHub_Store::settings();if(!in_array('orders:read',$settings['scopes']??array(),true))return;
        $lock='';try{$lock=self::lock('poll');$accounts=(array)get_option('vilahub_order_accounts',array());
            foreach($accounts as $id=>$config){if(empty($config['active']))continue;
                try{
                    $query='?connectionId='.rawurlencode($id).'&limit=20';if(!empty($config['cursor']))$query.='&cursor='.rawurlencode($config['cursor']);elseif(!empty($config['since']))$query.='&updatedSince='.rawurlencode($config['since']);
                    $client=new VilaHub_Client();$response=$client->request('GET','/marketplace/orders'.$query);$latest=$config['since']??$config['activatedAt'];
                    foreach($response['data'] as $row){
                        $args=array((string)$row['id'],(string)$id,(string)$row['updatedAt'],0);
                        if(!as_has_scheduled_action('vilahub_order_pull',$args,'vilahub')&&!as_schedule_single_action(time()+1,'vilahub_order_pull',$args,'vilahub',true))throw new RuntimeException('Não foi possível agendar o recebimento de um pedido. A página será consultada novamente.');
                        $latest=max($latest,(string)$row['updatedAt']);
                    }
                    $config['cursor']=$response['pagination']['nextCursor']??null;if(!$config['cursor'])$config['since']=$latest;
                    $config['error']='';
                }catch(Throwable $e){$config['error']=VilaHub_Product::truncate($e->getMessage(),400);}
                $accounts[$id]=$config;
            }update_option('vilahub_order_accounts',$accounts,false);
        }catch(Throwable $ignored){}finally{if($lock)self::unlock($lock);}
    }
}
