<?php
defined('ABSPATH') || exit;

final class VilaHub_Welcome {
    public static function render(): void {
        $connected = (bool) get_option('vilahub_api_secret');
        echo '<div class="vilahub-welcome"><section class="vh-hero"><div class="vh-hero-copy"><p class="vh-eyebrow">VILAHUB PARA WOOCOMMERCE</p><h2>Sua loja.<br>Mais canais.<br>Novas oportunidades.</h2><p class="vh-lead">Leve seus produtos aos marketplaces e cuide dos envios sem sair do WordPress. Você escolhe a conta, define o preço e acompanha cada publicação.</p><div class="vh-hero-actions">';
        self::start_button($connected);
        echo '<a class="vh-button vh-button-light" href="#vilahub-planos">Conhecer planos e preços</a></div><p class="vh-fine">Seu catálogo continua no WooCommerce. A conexão e os envios são autorizados por você.</p></div><div class="vh-flow" aria-label="Como o VilaHub conecta sua loja"><div class="vh-store"><span class="dashicons dashicons-store" aria-hidden="true"></span><div><small>SEU PONTO DE PARTIDA</small><strong>Minha loja WooCommerce</strong><span>Produtos · preços · estoque</span></div></div><div class="vh-flow-line">↓</div><div class="vh-hub"><b>V</b><div><strong>VilaHub</strong><span>Uma operação, vários destinos</span></div></div><div class="vh-flow-line">↓</div><div class="vh-destinations"><span>Mercado Livre</span><span>Shopee</span><span>Magalu</span><span>TikTok Shop</span></div><p>Escolha seu marketplace e sua conta antes de enviar.</p></div></section>';
        echo '<section class="vh-benefits"><div class="vh-section-heading"><p class="vh-eyebrow">MENOS ETAPAS. MAIS CONTROLE.</p><h2>Do produto ao marketplace, no seu ritmo.</h2><p>Os recursos para começar e acompanhar sua operação ficam no próprio WooCommerce.</p></div><div class="vh-feature-grid">';
        foreach (array(
            array('admin-links','Suas contas conectadas','Autorize os marketplaces e identifique cada loja em Minhas Integrações.'),
            array('tag','Preços por conta','Defina markup e arredondamento para cada destino e confira os valores antes de enviar.'),
            array('products','Envio em massa','Selecione produtos ou revise o catálogo inteiro. A preparação acontece em segundo plano.'),
            array('visibility','Acompanhamento claro','Veja os lotes, o resultado dos envios e os produtos que precisam de correção.')
        ) as $feature) { echo '<article><span class="dashicons dashicons-' . esc_attr($feature[0]) . '" aria-hidden="true"></span><h3>' . esc_html($feature[1]) . '</h3><p>' . esc_html($feature[2]) . '</p></article>'; }
        echo '</div></section><section class="vh-steps"><div class="vh-section-heading"><p class="vh-eyebrow">COMEÇAR É SIMPLES</p><h2>Três passos para preparar sua primeira publicação.</h2></div><ol><li><b>01</b><h3>Conecte sua conta</h3><p>Autorize a integração e a conta do marketplace que receberá os produtos.</p></li><li><b>02</b><h3>Escolha e confira</h3><p>Selecione os produtos e revise os preços para aquela conta.</p></li><li><b>03</b><h3>Envie e acompanhe</h3><p>Confirme o envio e acompanhe o resultado em Publicações.</p></li></ol></section>';
        self::plans();
        if ($connected) { self::connection_controls(); }
        echo '<section class="vh-bottom"><div><h2>Seu próximo canal de vendas começa aqui.</h2><p>Conecte uma conta, confira os preços e teste o fluxo com um produto.</p></div>'; self::start_button($connected); echo '</section><p class="vh-disclosure">As operações disponíveis variam conforme o marketplace, a conta autorizada e o conector. Amazon, SHEIN e Leroy Merlin ainda não oferecem envio de novos produtos por este fluxo. O recebimento de pedidos e as operações fiscais e de expedição ficam disponíveis somente para canais, contas e permissões compatíveis; a emissão fiscal exige configuração e confirmação específicas. <a href="https://vilahub.com.br/privacidade" target="_blank" rel="noopener noreferrer">Privacidade</a> · <a href="https://vilahub.com.br/termos" target="_blank" rel="noopener noreferrer">Termos do serviço</a></p></div>';
    }
    private static function start_button(bool $connected): void {
        if ($connected) { echo '<a class="vh-button vh-button-primary" href="' . esc_url(VilaHub_Marketplace::url('integrations')) . '">Começar agora <span aria-hidden="true">→</span></a>'; return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_action"><input type="hidden" name="vilahub_do" value="connect">';
        wp_nonce_field('vilahub_admin_action'); echo '<button class="vh-button vh-button-primary" type="submit">Começar agora <span aria-hidden="true">→</span></button></form>';
    }
    private static function connection_controls(): void {
        $settings=VilaHub_Store::settings();
        echo '<section class="vh-bottom" id="vilahub-connection"><div><h3>Conexão desta loja</h3><p>Reconecte quando precisar renovar a autorização ou liberar novas permissões.</p></div><div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_action"><input type="hidden" name="vilahub_do" value="connect"><input type="hidden" name="category_id" value="' . esc_attr($settings['category_id']) . '">';
        if (!empty($settings['automatic'])) { echo '<input type="hidden" name="automatic" value="1">'; }
        wp_nonce_field('vilahub_admin_action'); echo '<button class="button" type="submit">Reconectar integração</button></form>';
        echo '<details><summary>Desconectar esta loja</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_action"><input type="hidden" name="vilahub_do" value="disconnect">'; wp_nonce_field('vilahub_admin_action');
        echo '<p><label><input type="checkbox" name="confirm_disconnect" value="1" required> Confirmo desconectar esta loja do serviço. Os produtos e contas dos marketplaces serão preservados.</label></p><button class="button" type="submit">Desconectar integração desta loja</button></form></details></div></section>';
    }
    private static function plans(): void {
        $connected = (bool) get_option('vilahub_api_secret'); $info = null;
        if ($connected) { try { $info = (new VilaHub_Client())->request('GET','/marketplace/plan')['data']; } catch (Throwable $error) { echo '<p class="vh-plan-note">Não foi possível conferir seu plano agora. Confira a conexão e tente novamente.</p>'; } }
        echo '<section class="vh-plans" id="vilahub-planos"><div class="vh-section-heading"><p class="vh-eyebrow">UM PLANO PARA CADA MOMENTO</p><h2>Comece grátis. Cresça quando precisar.</h2><p>Escolha o Grátis permanente ou experimente o Full por 30 dias. No Full, após o teste, aplicam-se os preços atuais do VilaHub.</p></div>';
        if ($info) { echo '<p class="vh-plan-note"><strong>Seu plano: ' . esc_html(array('FREE'=>'Grátis permanente','FULL'=>'Full','LEGACY'=>'Condições atuais da sua conta')[$info['selected']] ?? $info['selected']) . '</strong> · ' . absint($info['usage']['products']) . ' produtos · ' . absint($info['usage']['integrations']) . ' integrações ativas.</p>'; if ($info['fullTrialActive']) { echo '<p>Teste Full até <strong>' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s',strtotime($info['trialEndsAt'])),get_option('date_format'))) . '</strong>.</p>'; } }
        echo '<div class="vh-plan-grid"><article class="vh-plan"><p class="vh-eyebrow">GRÁTIS PERMANENTE</p><h3>Para começar</h3><p class="vh-plan-price">R$ 0 <small>/mês</small></p><ul><li>Até <strong>10 produtos</strong> no cadastro da empresa.</li><li><strong>1 conta de marketplace ativa.</strong></li><li>Markup, revisão e acompanhamento dos envios.</li><li>Sem prazo de término.</li></ul><p class="vh-plan-note">O limite considera todo o cadastro da empresa. Para mudar ao Grátis, ajuste o uso aos limites; não apagamos seus produtos automaticamente.</p>';
        self::plan_button('FREE',$connected,$info); echo '</article><article class="vh-plan vh-plan-accent"><p class="vh-eyebrow">FULL · TESTE GRÁTIS POR 30 DIAS</p><h3>Para ampliar sua operação</h3><p class="vh-plan-price">30 dias <small>grátis, uma única vez</small></p><ul><li>Recursos disponíveis no plugin, com os limites gerais do VilaHub.</li><li>Até 100.000 produtos e 20 integrações ativas por empresa.</li><li>Envios em massa e preço por conta.</li><li>Após o teste: preços atuais, conforme o uso.</li></ul><p class="vh-plan-note">Estimativa atual a partir de R$ 79/mês: 500 produtos, 1 integração e 10 pedidos/mês no serviço. Não é preço fixo do Full. <a href="https://vilahub.com.br/planos#precos" target="_blank" rel="noopener noreferrer">Ver tabela e simular valores</a>.</p>';
        self::plan_button('FULL',$connected,$info); echo '</article></div><p class="vh-plan-note">O teste não reinicia ao trocar de plano. Uma conta que já utilizou o teste pode ativar Full com cobrança por uso. Trocar para o Grátis não apaga cobranças anteriores. As contas conectadas por outros sistemas também entram nos limites da empresa.</p></section>';
    }
    private static function plan_button(string $plan, bool $connected, ?array $info): void {
        if (!$connected) { self::start_button(false); return; }
        if (!$info) { return; }
        if (!in_array('plans:manage',VilaHub_Store::settings()['scopes'],true)) { echo '<p><a href="' . esc_url(VilaHub_Marketplace::url() . '#vilahub-connection') . '">Reconecte a integração para autorizar a escolha do plano.</a></p>'; return; }
        if ($info['selected'] === $plan) { echo '<p><strong>Seu plano atual</strong></p>'; return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vilahub_marketplace"><input type="hidden" name="operation" value="plan"><input type="hidden" name="plan" value="' . esc_attr($plan) . '"><input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">'; wp_nonce_field('vilahub_marketplace');
        if ($plan === 'FULL') { echo '<p><label><input type="checkbox" name="accept_paid" value="1" required> Confirmo que, após meu teste disponível, o uso será cobrado pelos preços atuais do VilaHub. Se meu teste já terminou, a cobrança por uso começa ao ativar Full.</label></p>'; }
        echo '<button class="vh-button vh-button-primary" type="submit">' . ($plan==='FREE'?'Escolher Grátis':($info['trialAvailable']?'Iniciar Full grátis':'Ativar Full')) . '</button></form>';
    }
}
