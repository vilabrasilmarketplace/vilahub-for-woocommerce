<?php
defined('ABSPATH') || exit;

// Repository for the plugin-owned order tables. Values are prepared and reads
// intentionally bypass object caching so order and inventory state stays current.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** A private marketplace record. Never a WC_Order or a WordPress post. */
final class VilaHub_Order_Record {
    private int $id;
    private array $data;
    public function __construct(int $id=0, array $data=array()) { $this->id=$id;$this->data=$data; }
    public static function table(): string {global $wpdb;return $wpdb->prefix.'vilahub_order_records';}
    public static function install(): void {
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$table=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tenant_id varchar(100) NOT NULL,
            remote_id varchar(100) NOT NULL,
            connection_id varchar(100) NOT NULL,
            channel varchar(40) NOT NULL DEFAULT '',
            external_id varchar(150) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            legacy_backup longtext NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY identity (tenant_id,remote_id),
            KEY account (tenant_id,connection_id),
            KEY recent (tenant_id,updated_at)
        ) ENGINE=InnoDB $charset;");
    }
    public static function get(int $id): ?self {global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT id,payload FROM %i WHERE id=%d',self::table(),$id),ARRAY_A);return $row?new self((int)$row['id'],json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR)):null;}
    public static function find(string $tenant,string $remote): ?self {global $wpdb;$id=$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE tenant_id=%s AND remote_id=%s',self::table(),$tenant,$remote));return $id?self::get((int)$id):null;}
    public function get_id(): int {return $this->id;}
    public function get_meta(string $key) {return $this->data['meta'][$key]??'';}
    public function update_meta_data(string $key,$value): void {$this->data['meta'][$key]=$value;}
    public function delete_meta_data(string $key): void {unset($this->data['meta'][$key]);}
    public function get_status(): string {return $this->data['status']??'on-hold';}
    public function set_status(string $value): void {$this->data['status']=$value;}
    public function get_items(): array {$result=array();foreach($this->data['items']??array() as $id=>$item)$result[$id]=new VilaHub_Order_Line($this,(int)$id,$item);return $result;}
    public function replace_items(array $items): void {$this->data['items']=$items;}
    public function update_item(int $id,array $data): void {$this->data['items'][$id]=$data;}
    public function add_order_note(string $note): void {$this->data['notes'][]=array('at'=>gmdate('c'),'text'=>$note);$this->data['notes']=array_slice($this->data['notes'],-500);$this->save();}
    public function get_notes(): array {$legacy=$this->get_meta('_vilahub_legacy_notes');return array_values(array_filter(array_merge(is_array($legacy)?$legacy:array(),$this->data['notes']??array()),'is_array'));}
    public function get_edit_order_url(): string {return admin_url('admin.php?page=vilahub-order-review&order_id='.$this->id);}
    public function save(): void {
        global $wpdb;$row=array('tenant_id'=>$this->get_meta('_vilahub_tenant'),'remote_id'=>$this->get_meta('_vilahub_remote_id'),'connection_id'=>$this->get_meta('_vilahub_connection'),'channel'=>$this->get_meta('_vilahub_channel'),'external_id'=>$this->get_meta('_vilahub_external_id'),'status'=>$this->get_status(),'payload'=>wp_json_encode($this->data,JSON_THROW_ON_ERROR),'updated_at'=>gmdate('Y-m-d H:i:s'));
        $ok=$this->id?$wpdb->update(self::table(),$row,array('id'=>$this->id)):$wpdb->insert(self::table(),$row);
        if($ok===false)throw new RuntimeException('Não foi possível salvar o pedido do marketplace.');if(!$this->id)$this->id=(int)$wpdb->insert_id;
    }
}

/** Stock ledger lines live inside the private record, never WC order items. */
final class VilaHub_Order_Line {
    private VilaHub_Order_Record $order;private int $id;private array $data;
    public function __construct(VilaHub_Order_Record $order,int $id,array $data){$this->order=$order;$this->id=$id;$this->data=$data;}
    public function get_product(){return !empty($this->data['product_id'])?wc_get_product($this->data['product_id']):false;}
    public function get_name(): string {return $this->data['name']??'';}
    public function get_quantity(): int {return (int)($this->data['quantity']??0);}
    public function get_meta(string $key){return $this->data['meta'][$key]??'';}
    public function update_meta_data(string $key,$value): void {$this->data['meta'][$key]=$value;}
    public function save(): void {$this->order->update_item($this->id,$this->data);}
}
