<?php
defined('ABSPATH') || exit;

// GET values are sanitized read-only publication filters and pagination cursors.
// Publication mutations remain protected by the marketplace action nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

final class VilaHub_Published_Products {
    public static function render(): void {
        global $wpdb;
        $accounts = VilaHub_Marketplace::accounts();
        $connection = sanitize_text_field(wp_unslash($_GET['connection_id'] ?? ''));
        $search = sanitize_text_field(wp_unslash($_GET['search'] ?? ''));
        $status = sanitize_text_field(wp_unslash($_GET['status'] ?? 'ALL'));
        $after = sanitize_text_field(wp_unslash($_GET['after'] ?? ''));
        $destination = null;
        foreach ($accounts as $account) { if ($account['id'] === $connection) { $destination = $account; break; } }
        if ($connection && !$destination) { throw new RuntimeException('Escolha uma conta disponível para esta empresa.'); }
        echo '<section class="vilahub-card"><h2>Produtos publicados</h2><p>Escolha o canal e a conta para consultar anúncios, tentativas de envio e pendências. Um envio na fila ainda não é um anúncio publicado.</p><form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="vilahub-published-filters"><input type="hidden" name="page" value="vilahub-publications"><label>Canal e conta<select name="connection_id" required><option value="">Selecione o canal e a conta</option>';
        foreach ($accounts as $account) { echo '<option value="' . esc_attr($account['id']) . '" ' . selected($connection,$account['id'],false) . '>' . esc_html(VilaHub_Marketplace::destination_name($account)) . '</option>'; }
        echo '</select></label><label>Pesquisar produto ou SKU<input name="search" maxlength="150" value="' . esc_attr($search) . '"></label><label>Situação<select name="status">';
        foreach (array('ALL'=>'Todas','PUBLISHED'=>'Publicado','ERROR'=>'Com problemas','PENDING'=>'Em processamento','INACTIVE'=>'Não ativo') as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($status,$value,false) . '>' . esc_html($label) . '</option>'; }
        echo '</select></label><button class="button button-primary">Consultar produtos</button></form></section>';
        if (!$destination) { echo '<section class="vilahub-card"><p>Selecione uma conta acima para ver os produtos desse destino.</p></section>'; return; }
        $query=array('connectionId'=>$connection,'status'=>$status);
        if ($search) { $query['search']=$search; } if ($after) { $query['after']=$after; }
        $page=(new VilaHub_Client())->request('GET','/marketplace/published-products?' . http_build_query($query,'','&',PHP_QUERY_RFC3986))['data'];
        echo '<section class="vilahub-card"><h2>' . esc_html(VilaHub_Marketplace::destination_name($destination)) . '</h2><p>Até 25 produtos por página. Anúncios ativos seguem o mesmo critério do painel VilaHub. Avisos de atualização aparecem sem ocultar o anúncio publicado.</p><div class="vilahub-published-table"><table class="widefat"><thead><tr><th>Produto / SKU</th><th>Situação / motivo</th><th>Destino</th><th>Estoque</th><th>Preço</th><th>Ações</th></tr></thead><tbody>';
        foreach ($page['items'] as $item) {
            // Resolve the current plugin-owned product mapping for this remote row.
            $local=(int)$wpdb->get_var($wpdb->prepare('SELECT product_id FROM %i WHERE tenant_id=%s AND remote_id=%s',VilaHub_Store::table(),VilaHub_Store::settings()['tenant_id'],$item['id'])); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $product=$local ? wc_get_product($local) : false;
            $labels=array('PUBLISHED'=>'Publicado','ERROR'=>'Com problemas','PENDING'=>'Em processamento','INACTIVE'=>'Não ativo');
            $row_status=isset($labels[$item['status']]) ? $item['status'] : 'PENDING';
            echo '<tr class="vilahub-published-row-' . esc_attr(strtolower($row_status)) . '"><td><div class="vilahub-published-product">' . ($product ? wp_kses_post($product->get_image('thumbnail')) : '<span class="dashicons dashicons-products" aria-hidden="true"></span>') . '<div><strong>' . esc_html($item['title']) . '</strong><small>SKU: ' . esc_html($item['sku']) . '</small></div></div></td><td><span class="vilahub-status-pill vilahub-status-' . esc_attr(strtolower($row_status)) . '">' . esc_html($labels[$row_status]) . '</span>';
            if (!empty($item['reason'])) { echo '<p class="vilahub-published-error">' . ($row_status === 'PUBLISHED' ? '<strong>Aviso de atualização registrado:</strong> ' : '<strong>Mensagem registrada para este produto:</strong> ') . esc_html($item['reason']) . '</p>'; }
            if (!empty($item['externalId'])) { echo '<small>ID do anúncio: ' . esc_html($item['externalId']) . '</small>'; }
            echo '</td><td>' . esc_html(VilaHub_Marketplace::destination_name($destination)) . '</td><td>' . esc_html($item['stock']) . '</td><td>' . wp_kses_post(wc_price($item['priceInCents']/100)) . '<small>' . (!empty($item['priceConfirmed']) ? 'Último preço sincronizado' : 'Preço previsto') . '</small></td><td><div class="vilahub-published-actions">';
            if ($local && current_user_can('edit_post',$local)) { echo '<a class="button" href="' . esc_url(get_edit_post_link($local)) . '">Corrigir produto</a>'; }
            else { echo '<a class="button" href="' . esc_url(VilaHub_Marketplace::url('publications') . '&remote_product=' . rawurlencode($item['id']) . '&connection_id=' . rawurlencode($connection)) . '">Corrigir cadastro</a>'; }
            echo '<a class="button" href="' . esc_url(VilaHub_Marketplace::url('publications') . ($local ? '&product=' . $local : '&remote_product=' . rawurlencode($item['id'])) . '&connection_id=' . rawurlencode($connection)) . '">Categoria e atributos</a>';
            if (($row_status === 'ERROR' || ($row_status === 'PUBLISHED' && !empty($item['reason']))) && VilaHub_Marketplace::can_send($destination)) {
                self::form('published-retry');
                echo '<input type="hidden" name="remote_id" value="' . esc_attr($item['id']) . '"><input type="hidden" name="connection_id" value="' . esc_attr($connection) . '"><input type="hidden" name="confirm_retry" value="1"><button class="button button-primary">Reenviar após correção</button></form>';
            }
            echo '</div></td></tr>';
        }
        if (!$page['items']) { echo '<tr><td colspan="6">Nenhum produto encontrado para esta conta e estes filtros.</td></tr>'; }
        echo '</tbody></table></div>';
        if (!empty($page['next'])) { echo '<p><a class="button" href="' . esc_url(add_query_arg(array('page'=>'vilahub-publications','connection_id'=>$connection,'status'=>$status,'search'=>$search,'after'=>$page['next']),admin_url('admin.php'))) . '">Próxima página</a></p>'; }
        if ($after) { echo '<p><a href="' . esc_url(add_query_arg(array('page'=>'vilahub-publications','connection_id'=>$connection,'status'=>$status,'search'=>$search),admin_url('admin.php'))) . '">Voltar ao início da lista</a></p>'; }
        echo '</section>';
    }
    public static function form(string $operation): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_marketplace"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">'; wp_nonce_field('vilahub_marketplace');
    }
    public static function correction(string $remote): void {
        $response=(new VilaHub_Client())->request('GET','/products/' . rawurlencode($remote)); $product=$response['data'];
        echo '<section class="vilahub-card"><h2>Corrigir cadastro</h2><p>Produto: ' . esc_html($product['sku']) . '. Este cadastro não está vinculado a um produto local desta loja. As alterações serão salvas na empresa conectada.</p>';
        self::form('published-correct');
        echo '<input type="hidden" name="connection_id" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET['connection_id']??''))) . '">';
        echo '<input type="hidden" name="remote_id" value="' . esc_attr($remote) . '"><input type="hidden" name="etag" value="' . esc_attr($response['etag']) . '">';
        foreach(array('title'=>'Nome do produto','brand'=>'Marca','gtin'=>'GTIN / EAN') as $field=>$label) { echo '<p><label>' . esc_html($label) . '<input class="regular-text" name="' . esc_attr($field) . '" value="' . esc_attr($product[$field]??'') . '" maxlength="' . ($field==='title'?'300':($field==='brand'?'200':'14')) . '" ' . ($field==='title'?'required':'') . '></label></p>'; }
        echo '<p><label>Descrição<textarea class="large-text" name="description" rows="5" maxlength="20000">' . esc_textarea($product['description']??'') . '</textarea></label></p><button class="button button-primary">Salvar correções</button></form></section>';
    }
}
