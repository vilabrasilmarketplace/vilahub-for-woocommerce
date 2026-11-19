<?php
defined('ABSPATH') || exit;

// This migration moves legacy rows between plugin-owned tables. Every dynamic
// value is prepared and current database state is required while the lock runs.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** One-way upgrade of ONLY records created by VilaHub 2.0.x. No order hooks. */
final class VilaHub_Order_Migration {
    private static function exists(string $table): bool {global $wpdb;return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table;}
    private static function rows(string $sql): array {
        global $wpdb;
        // Private helper: every caller passes prepared SQL, or fixed internal table names plus integer-only ID lists.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if($wpdb->last_error)throw new RuntimeException('Não foi possível ler os dados anteriores com segurança.');return $rows?:array();
    }
    private static function ids(int $limit=50): array {
        global $wpdb;$ids=array();
        foreach(array($wpdb->postmeta,$wpdb->prefix.'wc_orders_meta') as $table)if(self::exists($table)){$column=$table===$wpdb->postmeta?'post_id':'order_id';$rows=self::rows($wpdb->prepare('SELECT DISTINCT %i AS id FROM %i WHERE meta_key=%s LIMIT %d',$column,$table,'_vilahub_remote_id',$limit));$ids=array_merge($ids,array_column($rows,'id'));}
        return array_slice(array_unique(array_map('intval',$ids)),0,$limit);
    }
    public static function ensure_complete(): void {self::run();if(self::ids(1))throw new RuntimeException('A separação dos pedidos anteriores está pendente. Consulte VilaHub → Pedidos; nenhuma nova baixa será feita até concluir.');}
    public static function run(): void {
        global $wpdb;$lock='vh-orders-storage-upgrade';if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return;
        try {foreach(self::ids() as $id)self::move($id);delete_option('vilahub_order_migration_error');}
        catch(Throwable $e){update_option('vilahub_order_migration_error','Não foi possível separar um pedido anterior: '.$e->getMessage(),false);}
        finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function move(int $id): void {
        global $wpdb;$hpos=\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $source_meta=$hpos?$wpdb->prefix.'wc_orders_meta':$wpdb->postmeta;$source_key=$hpos?'order_id':'post_id';
        $values=array_column(self::rows($wpdb->prepare('SELECT meta_key,meta_value FROM %i WHERE %i=%d',$source_meta,$source_key,$id)),'meta_value','meta_key');
        $created_via=$hpos?($wpdb->get_var($wpdb->prepare('SELECT created_via FROM %i WHERE order_id=%d',$wpdb->prefix.'wc_order_operational_data',$id))):($values['_created_via']??'');
        if($created_via!=='vilahub-marketplace'||empty($values['_vilahub_tenant'])||empty($values['_vilahub_remote_id']))throw new RuntimeException('Vínculo antigo '.esc_html((string)$id).' exige conferência; os dados foram preservados.');
        $tenant=(string)$values['_vilahub_tenant'];$remote=(string)$values['_vilahub_remote_id'];
        $order=VilaHub_Order_Record::find($tenant,$remote);
        if($order && (int)$order->get_meta('_vilahub_legacy_order_id')!==$id)throw new RuntimeException('Já existe outro registro para a mesma venda.');
        $tables=array($wpdb->posts=>'ID',$wpdb->postmeta=>'post_id');
        foreach(array('wc_orders'=>'id','wc_order_addresses'=>'order_id','wc_order_operational_data'=>'order_id','wc_orders_meta'=>'order_id','woocommerce_order_items'=>'order_id','woocommerce_downloadable_product_permissions'=>'order_id','wc_order_stats'=>'order_id','wc_order_product_lookup'=>'order_id','wc_order_tax_lookup'=>'order_id','wc_order_coupon_lookup'=>'order_id') as $name=>$column)$tables[$wpdb->prefix.$name]=$column;
        foreach(array(VilaHub_Order_Record::table(),VilaHub_Orders::table(),$wpdb->prefix.'woocommerce_order_itemmeta',$wpdb->comments,$wpdb->commentmeta) as $transaction_table){$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$transaction_table));if(strtoupper((string)$engine)!=='INNODB')throw new RuntimeException('Não foi possível garantir a transação da migração. Os dados anteriores foram preservados.');}
        wc_transaction_query('start');
        try {
            $backup=array();foreach($tables as $table=>$column)if(self::exists($table)){
                $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
                if(strtoupper((string)$engine)!=='INNODB')throw new RuntimeException('A tabela '.$table.' precisa suportar transações para uma migração segura.');
                $backup[$table]=self::rows($wpdb->prepare('SELECT * FROM %i WHERE %i=%d FOR UPDATE',$table,$column,$id));
            }
            // A mirrored HPOS/CPT row must identify this same VilaHub order, never a store order.
            $post=$backup[$wpdb->posts][0]??null;
            if($post && $post['post_type']!=='shop_order_placehold'){
                $meta=array_column($backup[$wpdb->postmeta]??array(),'meta_value','meta_key');
                if($post['post_type']!=='shop_order'||($meta['_created_via']??'')!=='vilahub-marketplace'||($meta['_vilahub_remote_id']??'')!==$remote||($meta['_vilahub_tenant']??'')!==$tenant)throw new RuntimeException('A cópia nativa não corresponde à venda VilaHub.');
            }
            $hpos_row=$backup[$wpdb->prefix.'wc_orders'][0]??null;
            if($hpos_row){$meta=array_column($backup[$wpdb->prefix.'wc_orders_meta']??array(),'meta_value','meta_key');$op=$backup[$wpdb->prefix.'wc_order_operational_data'][0]??array();if(($op['created_via']??'')!=='vilahub-marketplace'||($meta['_vilahub_remote_id']??'')!==$remote||($meta['_vilahub_tenant']??'')!==$tenant)throw new RuntimeException('A cópia HPOS não corresponde à venda VilaHub.');}
            $children=self::rows($wpdb->prepare('SELECT ID FROM %i WHERE post_parent=%d AND post_type IN (%s,%s) FOR UPDATE',$wpdb->posts,$id,'shop_order','shop_order_refund'));
            if(self::exists($wpdb->prefix.'wc_orders'))$children=array_merge($children,self::rows($wpdb->prepare('SELECT id FROM %i WHERE parent_order_id=%d FOR UPDATE',$wpdb->prefix.'wc_orders',$id)));
            if($children)throw new RuntimeException('O pedido anterior possui registros financeiros vinculados e foi preservado para conferência.');
            $item_ids=array_map('intval',array_column($backup[$wpdb->prefix.'woocommerce_order_items']??array(),'order_item_id'));
            $item_table=$wpdb->prefix.'woocommerce_order_itemmeta';if($item_ids)$backup[$item_table]=self::rows('SELECT * FROM `'.$item_table.'` WHERE order_item_id IN ('.implode(',',$item_ids).') FOR UPDATE');
            $backup[$wpdb->comments]=self::rows($wpdb->prepare('SELECT * FROM %i WHERE comment_post_ID=%d AND comment_type=%s FOR UPDATE',$wpdb->comments,$id,'order_note'));
            $notes=array_map('intval',array_column($backup[$wpdb->comments],'comment_ID'));if($notes)$backup[$wpdb->commentmeta]=self::rows('SELECT * FROM `'.$wpdb->commentmeta.'` WHERE comment_id IN ('.implode(',',$notes).') FOR UPDATE');
            if(!$order){
                $source=array_column($backup[$source_meta]??array(),'meta_value','meta_key');$source=array_map('maybe_unserialize',$source);
                if(!is_array($source['_vilahub_snapshot']??null)||($source['_vilahub_snapshot']['id']??'')!==$remote)throw new RuntimeException('O pedido anterior não possui uma revisão de origem válida.');
                $order=new VilaHub_Order_Record();$status=$hpos?($hpos_row['status']??''):($post['post_status']??'');$order->set_status(str_starts_with($status,'wc-')?substr($status,3):$status);
                foreach($source as $key=>$value)if(str_starts_with($key,'_vilahub_'))$order->update_meta_data($key,$value);
                $order->update_meta_data('_vilahub_legacy_order_id',$id);$items=array();$line_meta=array();
                foreach($backup[$item_table]??array() as $entry)$line_meta[$entry['order_item_id']][$entry['meta_key']]=maybe_unserialize($entry['meta_value']);
                foreach($backup[$wpdb->prefix.'woocommerce_order_items']??array() as $line){if($line['order_item_type']!=='line_item')continue;$line_id=(int)$line['order_item_id'];$meta=$line_meta[$line_id]??array();
                    $items[$line_id]=array('product_id'=>(int)(!empty($meta['_variation_id'])?$meta['_variation_id']:($meta['_product_id']??0)),'name'=>$line['order_item_name'],'quantity'=>(int)($meta['_qty']??0),'meta'=>array('_vilahub_sku'=>$meta['_vilahub_sku']??'','_reduced_stock'=>(int)($meta['_reduced_stock']??0),'_vilahub_returned_qty'=>(int)($meta['_vilahub_returned_qty']??0)));
                }
                $order->replace_items($items);$order->update_meta_data('_vilahub_legacy_notes',array_map(function($note){return array('at'=>$note['comment_date_gmt'],'text'=>$note['comment_content']);},$backup[$wpdb->comments]));$order->save();
            }
            $encoded=wp_json_encode($backup,JSON_THROW_ON_ERROR);$existing=$wpdb->get_var($wpdb->prepare('SELECT legacy_backup FROM %i WHERE id=%d',VilaHub_Order_Record::table(),$order->get_id()));
            if(!$existing && $wpdb->update(VilaHub_Order_Record::table(),array('legacy_backup'=>$encoded),array('id'=>$order->get_id()))===false)throw new RuntimeException('Falha ao preservar a cópia anterior.');
            $stored=$wpdb->get_var($wpdb->prepare('SELECT legacy_backup FROM %i WHERE id=%d',VilaHub_Order_Record::table(),$order->get_id()));if(!$stored||$stored!==$encoded)throw new RuntimeException('Cópia anterior não confirmada.');
            // Only the imported order's rows are removed, after its private record and raw backup exist.
            foreach($tables as $table=>$column)if(isset($backup[$table])&&$wpdb->delete($table,array($column=>$id))===false)throw new RuntimeException('Falha ao separar registro anterior.');
            foreach($item_ids as $line_id)if($wpdb->delete($item_table,array('order_item_id'=>$line_id))===false)throw new RuntimeException('Falha ao separar itens anteriores.');
            foreach($notes as $note_id){if($wpdb->delete($wpdb->commentmeta,array('comment_id'=>$note_id))===false||$wpdb->delete($wpdb->comments,array('comment_ID'=>$note_id))===false)throw new RuntimeException('Falha ao separar anotações anteriores.');}
            if($wpdb->update(VilaHub_Orders::table(),array('order_id'=>$order->get_id()),array('tenant_id'=>$tenant,'remote_id'=>$remote))===false)throw new RuntimeException('Falha ao atualizar vínculo.');
            wc_transaction_query('commit');clean_post_cache($id);wp_cache_delete($id,'orders');WC_Cache_Helper::invalidate_cache_group('orders');
            if(class_exists('Automattic\WooCommerce\Caches\OrderCache'))wc_get_container()->get('Automattic\WooCommerce\Caches\OrderCache')->remove($id);
        }catch(Throwable $e){wc_transaction_query('rollback');throw $e;}
    }
}
