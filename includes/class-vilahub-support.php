<?php
defined('ABSPATH') || exit;

// GET only selects VilaHub admin pages and whether the support panel opens.
// Chat requests are separate AJAX POST actions protected by check_ajax_referer().
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class VilaHub_Support {
    private static function token_key(): string {return '_vilahub_support_chat_token';}
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
        $client=new VilaHub_Client();
        try{
            if($operation==='start'){
                if($token){try{$response=$client->request('GET','/plugin-support/conversation',null,array('X-Support-Chat-Token'=>$token));wp_send_json_success($response['data']);}catch(Throwable $ignored){delete_user_meta($user,self::token_key());$token='';}}
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $page=isset($_POST['page'])?wp_unslash($_POST['page']):'';
                $page=is_string($page)?sanitize_key($page):'';
                $response=$client->request('POST','/plugin-support/start',array('page'=>$page,'version'=>VILAHUB_WC_VERSION,'site'=>home_url()),array('Idempotency-Key'=>wp_generate_uuid4()));
                $token=(string)($response['data']['token']??'');
                if(!$token)throw new RuntimeException('O atendimento não iniciou.');
                update_user_meta($user,self::token_key(),$token);
                wp_send_json_success($response['data']['conversation']??array());
            }
            if(!$token)throw new RuntimeException('Inicie uma nova conversa.');
            if($operation==='message'){
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $raw_message=isset($_POST['message'])?wp_unslash($_POST['message']):'';
                if(!is_string($raw_message))throw new RuntimeException('Mensagem inválida.');
                $message=sanitize_textarea_field($raw_message);
                $message_length=function_exists('mb_strlen')?mb_strlen($message):strlen($message);
                if($message===''||$message_length>2000)throw new RuntimeException('A mensagem deve ter até 2.000 caracteres.');
                wp_send_json_success($client->request('POST','/plugin-support/message',array('token'=>$token,'message'=>$message),array('Idempotency-Key'=>wp_generate_uuid4()))['data']);
            }
            if($operation==='transfer'){wp_send_json_success($client->request('POST','/plugin-support/transfer',array('token'=>$token),array('Idempotency-Key'=>wp_generate_uuid4()))['data']);}
            throw new RuntimeException('Operação inválida.');
        }catch(Throwable $e){wp_send_json_error(array('message'=>VilaHub_Product::truncate($e->getMessage(),500)),400);}
    }
}
