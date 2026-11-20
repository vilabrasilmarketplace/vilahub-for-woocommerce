<?php
defined('ABSPATH') || exit;

// GET only selects VilaHub admin pages and whether the support panel opens.
// Chat requests are separate AJAX POST actions protected by check_ajax_referer().
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class VilaHub_Support {
    private static function token_key(): string {return '_vilahub_support_chat_token';}
    private static function page_url(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $page=isset($_POST['page'])?wp_unslash($_POST['page']):'';
        $page=is_string($page)?sanitize_key($page):'';
        return admin_url('admin.php?page='.($page?:'vilahub'));
    }
    private static function public_request(string $method, string $path, ?array $body = null, array $headers = array()): array {
        $args=array(
            'method'=>$method,
            'timeout'=>25,
            'redirection'=>0,
            'sslverify'=>true,
            'limit_response_size'=>1048576,
            'headers'=>array_merge(array('Accept'=>'application/json','User-Agent'=>'VilaHub-WooCommerce/'.VILAHUB_WC_VERSION),$headers),
        );
        if($body!==null){
            $args['body']=wp_json_encode($body);
            $args['headers']['Content-Type']='application/json';
        }
        $response=wp_safe_remote_request('https://vilahub.com.br/api'.$path,$args);
        if(is_wp_error($response))throw new RuntimeException('Não foi possível conectar ao atendimento VilaHub.');
        $status=(int)wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300){
            $message=is_array($data)&&isset($data['message'])&&is_string($data['message'])?$data['message']:'O atendimento VilaHub recusou a solicitação.';
            throw new RuntimeException($message,$status);
        }
        if(!is_array($data))throw new RuntimeException('O atendimento VilaHub retornou uma resposta inválida.');
        return $data;
    }
    private static function start_conversation(int $user): array {
        $response=self::public_request('POST','/support-ai/start',array('pageUrl'=>self::page_url()));
        $token=(string)($response['token']??'');
        if(!$token)throw new RuntimeException('O atendimento não iniciou.');
        update_user_meta($user,self::token_key(),$token);
        return array('token'=>$token,'conversation'=>$response['conversation']??array());
    }
    private static function error_payload(Throwable $error): array {
        return array('code'=>'SUPPORT_CHAT_ERROR','message'=>VilaHub_Product::truncate($error->getMessage(),500));
    }
    public static function boot(): void {
        add_action('admin_init',function(){if(sanitize_key(wp_unslash($_GET['page']??''))==='vilahub-support'){wp_safe_redirect(add_query_arg('vilahub_chat','1',admin_url('admin.php?page=vilahub')));exit;}});
        add_action('wp_ajax_vilahub_support_chat',array(__CLASS__,'ajax'));
        add_action('admin_enqueue_scripts',function(){if(strpos(sanitize_key(wp_unslash($_GET['page']??'')),'vilahub')!==0)return;wp_enqueue_style('vilahub-support-chat',plugins_url('assets/support-chat.css',VILAHUB_WC_FILE),array(),VILAHUB_WC_VERSION);wp_enqueue_script('vilahub-support-chat',plugins_url('assets/support-chat.js',VILAHUB_WC_FILE),array(),VILAHUB_WC_VERSION,true);wp_localize_script('vilahub-support-chat','vilahubSupportChat',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('vilahub_support_chat'),'page'=>sanitize_key(wp_unslash($_GET['page']??'')),'open'=>!empty($_GET['vilahub_chat'])));});
    }
    public static function widget(): void {
        static $shown=false;if($shown||!current_user_can('manage_woocommerce'))return;$shown=true;
        echo '<div id="vilahub-ai-widget" class="vh-ai-widget"><div class="vh-ai-controls"><button id="vilahub-ai-launcher" class="vh-ai-launcher" type="button" aria-expanded="false" aria-controls="vilahub-ai-panel"><span>✦</span><b>Fale com a VilaHub</b></button><button id="vilahub-ai-dismiss" class="vh-ai-dismiss" type="button" aria-label="Ocultar atendimento">×</button></div><aside id="vilahub-ai-panel" class="vh-ai-panel" hidden aria-label="Assistente VilaHub"><header><div><strong>Assistente VilaHub</strong><small>Atendimento do plugin WooCommerce</small></div><button id="vilahub-ai-close" type="button" aria-label="Fechar atendimento">×</button></header><div id="vilahub-support-messages" class="vh-support-messages" aria-live="polite"><p class="vh-support-loading">Abra o chat para iniciar o atendimento.</p></div><form id="vilahub-support-form"><label class="screen-reader-text" for="vilahub-support-input">Sua mensagem</label><textarea id="vilahub-support-input" maxlength="2000" rows="3" placeholder="Escreva sua dúvida sobre o plugin…" required></textarea><div><small>Não envie senhas, tokens ou certificados.</small><button class="button button-primary" type="submit">Enviar</button></div></form><footer><button id="vilahub-support-transfer" class="button" type="button">Falar com uma pessoa</button><span id="vilahub-support-status"></span></footer></aside></div>';
    }
    public static function ajax(): void {
        if(!current_user_can('manage_woocommerce')){
            wp_send_json_error(array('message'=>'Sem permissão.'),403);
        }
        check_ajax_referer('vilahub_support_chat','nonce');
        // Unslash first, reject non-scalar values, then sanitize by type.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_operation=isset($_POST['operation'])?wp_unslash($_POST['operation']):'';
        if(!is_string($raw_operation)){
            wp_send_json_error(array('message'=>'Operação inválida.'),400);
        }
        $operation=sanitize_key($raw_operation);
        $user=get_current_user_id();
        $token=(string)get_user_meta($user,self::token_key(),true);
        try{
            if($operation==='start'){
                if($token){try{$response=self::public_request('GET','/support-ai/conversation',null,array('X-Support-Chat-Token'=>$token));wp_send_json_success($response);}catch(Throwable $ignored){delete_user_meta($user,self::token_key());$token='';}}
                $started=self::start_conversation($user);
                wp_send_json_success($started['conversation']);
            }
            if(!$token){
                $started=self::start_conversation($user);
                $token=$started['token'];
            }
            if($operation==='message'){
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $raw_message=isset($_POST['message'])?wp_unslash($_POST['message']):'';
                if(!is_string($raw_message))throw new RuntimeException('Mensagem inválida.');
                $message=sanitize_textarea_field($raw_message);
                $message_length=function_exists('mb_strlen')?mb_strlen($message):strlen($message);
                if($message===''||$message_length>2000)throw new RuntimeException('A mensagem deve ter até 2.000 caracteres.');
                wp_send_json_success(self::public_request('POST','/support-ai/message',array('message'=>$message),array('X-Support-Chat-Token'=>$token)));
            }
            if($operation==='transfer'){
                $current=wp_get_current_user();
                wp_send_json_success(self::public_request('POST','/support-ai/transfer',array('name'=>$current->display_name,'email'=>$current->user_email),array('X-Support-Chat-Token'=>$token)));
            }
            throw new RuntimeException('Operação inválida.');
        }catch(Throwable $e){wp_send_json_error(self::error_payload($e),400);}
    }
}
