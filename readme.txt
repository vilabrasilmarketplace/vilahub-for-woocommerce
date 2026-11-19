=== VilaHub – Marketplaces for WooCommerce ===
Contributors: vilahub
Tags: integração, mercado livre, shopee, magalu, amazon
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecte o WooCommerce ao Mercado Livre, Shopee, Magalu, Amazon, SHEIN e outros marketplaces. Gerencie produtos, pedidos, estoque e documentos fiscais.

== Description ==

O VilaHub conecta o WooCommerce ao seu serviço externo de integração. No WordPress, você autoriza contas, configura preços, envia produtos, acompanha publicações e gerencia os pedidos de marketplaces compatíveis.

* Autorização OAuth explícita com PKCE, sem compartilhar a senha do WordPress.
* Seleção de produtos, escolha da conta de destino, revisão de preço e confirmação explícita da publicação.
* Transmissão em segundo plano de produtos, variações, preços, estoque, imagens e atributos.
* Acompanhamento do status de publicação, validação do canal e correções de categoria ou atributos suportadas.
* Tela separada para pedidos de marketplaces; vendas importadas não são pedidos nativos do WooCommerce.
* Autoridade de estoque definida entre WooCommerce e ERP externo, com deduções locais idempotentes.
* Documentos fiscais e etiquetas suportados sempre por trás de uma ação separada do usuário.
* Telas fiscal, financeira e de suporte do plugin vinculadas à empresa autorizada no VilaHub.

Requer WooCommerce 9.0+, WordPress 6.5+, PHP 8.1+, OpenSSL, HTTPS e uma conta VilaHub. A interface está em português do Brasil. HPOS e o armazenamento legado são compatíveis.

= Serviço externo e transmissão de dados =

O plugin depende do VilaHub para catálogo, conexões com marketplaces, fila de publicação, pedidos de marketplaces e serviços fiscais e de expedição suportados. Essas operações não funcionam offline.

Endpoints do serviço: https://vilahub.com.br/api/v1 e https://vilahub.com.br/api/oauth. A autorização é hospedada em https://vilahub.com.br/conectar/woocommerce. As chamadas de API usam HTTPS; páginas oficiais de autorização de marketplace abrem somente quando o vendedor escolhe conectar uma conta.

As informações são trocadas desta forma:

* A conexão envia a URL de callback, identificadores da instalação ou do cliente e parâmetros OAuth PKCE/state. Os tokens são criptografados localmente com chaves derivadas dos salts de segurança do WordPress. A autorização pode ser revogada, e autorizações antigas não recebem novos escopos silenciosamente.
* A prévia de restrição de produto envia IDs, títulos, descrições e marcas em lotes limitados. Ela não publica produtos.
* Um envio explícito ou uma sincronização habilitada pode transmitir SKU, título, descrição em texto simples, marca, GTIN, preço, estoque, dimensões, peso, tags, URLs de imagens públicas, variações, atributos, NCM/unidade quando configurados e identificadores locais de instalação ou operação.
* A conexão de canal envia identificadores do vendedor ou da loja, documento da empresa quando informado e credenciais digitadas para provedores baseados em chave. Provedores OAuth usam o fluxo oficial. O plugin não baixa código executável do VilaHub.
* Depois da permissão de pedidos e da ativação da conta, solicitações periódicas podem receber dados de comprador, contato e endereço, itens, totais, status de pagamento, atendimento e rastreamento, situação fiscal e referências de documentos. Os campos dependem do marketplace e ficam em tabelas privadas do plugin.
* Ativação de pedidos, autoridade de estoque, confirmações, vínculos de produtos e ações explícitas de pedido, fiscal ou expedição são enviados ao VilaHub. Pedidos nativos da loja não são enviados por este recurso.
* A configuração fiscal envia dados do emissor, endereço, contato, contador e tributos. Um administrador pode enviar um certificado A1 PFX/P12 com senha ou um XML de nota autorizado. Emissão, consulta, envio ao marketplace e solicitação de etiqueta usam o VilaHub e os provedores autorizados. Nenhuma nota é emitida apenas por abrir ou importar um pedido.
* O financeiro solicita resumo de assinatura, uso, faturas, créditos e comissões de dropshipping. Pagamentos continuam na página autenticada do VilaHub; o plugin não coleta credenciais de cartão ou banco.
* O chat de suporte envia a pergunta, a página e a versão do plugin e a URL da loja. A transferência humana envia o histórico e a identidade da conta. Nunca envie senhas, tokens, certificados ou credenciais bancárias pelo chat.

O plugin não envia senhas do WordPress, credenciais de cartão ou banco nem listas de pedidos nativos da loja. Ele processa dados de compradores e dados fiscais conforme descrito; inclua isso nas práticas de privacidade da loja. Imagens públicas de produtos podem ser buscadas nas URLs fornecidas, com limite de dez URLs HTTPS e dez MB por imagem.

O suporte opcional pelo WhatsApp abre somente quando clicado e não recebe automaticamente dados de compradores ou pedidos.

* Serviço: https://vilahub.com.br
* Termos: https://vilahub.com.br/termos
* Privacidade: https://vilahub.com.br/privacidade
* Planos: https://vilahub.com.br/planos
* Documentação da API: https://vilahub.com.br/api/v1/docs

= Código-fonte, ativos e compilação =

O código-fonte legível completo está incluído neste pacote. PHP, JavaScript, CSS e ativos vetoriais são distribuídos como arquivos de origem. Nenhum código compilado, gerado, ofuscado ou minificado é necessário em execução. O pacote não exige instalação de dependências nem etapa de compilação: os arquivos deste diretório são os arquivos distribuídos e mantidos.

= Planos e integrações =

O serviço externo possui um plano Free permanente, com até dez produtos e uma integração ativa por empresa, um teste Full de trinta dias quando elegível e uso pago conforme os termos vigentes. As cotas pertencem à empresa, não apenas a esta loja.

O ecossistema mais amplo do VilaHub inclui Mercado Livre, Magalu, Shopee, VilaBrasil/Medusa, Amazon, TikTok Shop, MadeiraMadeira, Leroy Merlin, Netshoes/Zattini, Americanas/B2W, SHEIN, Carrefour, AliExpress, Dafiti, Bling, ERP TINY/Olist, Omie, WooCommerce, Loja Integrada, Nuvemshop, Shopify e Tray. Recursos, aprovação e disponibilidade variam por provedor e conta. Esta lista não promete operações idênticas nem um conector direto no plugin para todos os serviços.

= Operações suportadas =

Existem telas de conexão para Mercado Livre, Shopee, Magalu, Amazon, TikTok Shop, VilaBrasil, SHEIN, Netshoes, MadeiraMadeira, Americanas/SkyHub e Leroy Merlin. A recepção de pedidos de marketplace é oferecida atualmente para Mercado Livre, Magalu, Shopee, TikTok Shop, VilaBrasil e MadeiraMadeira depois da ativação de cada conta.

* Magalu: configuração de perfil fiscal e A1, prévia, emissão direta para CRT 1, consulta, XML externo autorizado, envio, XML/DANFE e etiquetas disponíveis. Outros regimes usam XML externo autorizado quando suportado.
* Mercado Livre: PDF de etiqueta oficial sujeito às verificações de expedição e disponibilidade de impressão. O envio de nota fiscal não faz parte deste fluxo.
* TikTok Shop: consulta de pacote, XML autorizado validado e documentos de expedição quando liberados pelo canal. Agrupamentos ou despachos não suportados permanecem no Seller Center.
* Shopee, VilaBrasil e MadeiraMadeira: informações e documentos do pedido fornecidos pelo serviço; esta versão não adiciona uma ação fiscal ou de etiqueta genérica.

Mercado Livre e Shopee oferecem preparação de categoria e atributos quando suportada. Conectar Amazon, SHEIN ou Leroy Merlin não cria um publicador genérico em lote. Credenciais do provedor, aprovação da conta e regras do canal sempre se aplicam.

== Installation ==

1. Instale o WooCommerce e ative o VilaHub.
2. Abra VilaHub > Início, escolha Começar, entre ou crie a conta do serviço, confirme a empresa e autorize a loja.
3. Use VilaHub > Conectar Canal de Venda para autorizar uma conta; gerencie-a em Minhas Integrações.
4. Em Produtos > Todos os produtos, selecione os itens, escolha Enviar para marketplace, defina o destino, aplique, revise os preços e confirme.
5. Confira o resultado em VilaHub > Produtos publicados.
6. Em VilaHub > Pedidos, configure a recepção, ative a conta e escolha WooCommerce ou ERP como autoridade de estoque. Reautorize se a conexão antiga não tiver os escopos necessários.
7. Gerencie pedidos de marketplaces, vínculos de itens, pendências de estoque e documentos disponíveis na tela separada de pedidos.

Não é necessário informar uma chave REST do WooCommerce nem a senha do WordPress. Chaves manuais antigas do VilaHub continuam compatíveis. Nunca ative uma segunda cópia em outra pasta de plugin na mesma loja.

== Important behavior ==

Preços usam BRL e o estoque usa quantidades inteiras. Não há conversão de moeda, invenção de dados fiscais nem rateio fracionado. Produtos e variações precisam de SKUs distintos e estoque próprio; quantidade negativa é enviada como zero. A API aceita até 100 variações e dois atributos por variação, substituindo a lista inteira de variações. Vínculos de SKU já existentes exigem aprovação explícita antes da primeira atualização transmitida. Uma sincronização bem-sucedida do catálogo não significa que o anúncio foi publicado no marketplace.

As filas usam lotes limitados, identificadores persistentes de operação e novas tentativas. O cron do WordPress e o Action Scheduler do WooCommerce precisam estar funcionando; lojas com pouco tráfego podem precisar de um agendador no servidor.

Pedidos de marketplaces permanecem separados dos pedidos nativos do WooCommerce. Com o WooCommerce como autoridade de estoque, o estoque vinculado é alterado pela API de produtos e observadores podem ver a mudança. Com um ERP como autoridade, o plugin não faz dedução local. Deduções de pedidos pagos e cancelamentos antes do despacho são idempotentes. Vendas antigas ou sem data exigem confirmação explícita. Estornos ou mercadorias já despachadas nunca devolvem estoque automaticamente; confirme somente quantidades recebidas fisicamente. O plugin não emite estornos financeiros do marketplace.

Ações fiscais exigem capacidade do canal, configuração e revisão do usuário. Um resultado incerto é consultado em vez de transmitido novamente de forma automática. A homologação real com provedor ou SEFAZ é separada dos testes sintéticos.

Cada instalação fica vinculada à empresa, às credenciais e à URL da loja autorizadas. Uma origem clonada bloqueia a sincronização até ser revisada. A ativação de rede no multisite não é suportada; ative por site. Alterar os salts de segurança do WordPress exige reconectar.

== Removal ==

A desativação interrompe tarefas agendadas e preserva os dados. Desconecte primeiro para revogar a autorização remota; a desinstalação não solicita exclusão remota. Por padrão, a desinstalação mantém vínculos, pedidos privados de marketplaces, registros de estoque e backups de migração para recuperação, além de remover segredos locais e pausar a sincronização. Produtos e pedidos nativos comuns não são excluídos.

Definir `VILAHUB_DELETE_DATA_ON_UNINSTALL` como `true` no `wp-config.php` exclui permanentemente tabelas e opções locais do plugin durante a desinstalação, incluindo pedidos privados e backups de migração. Não use essa opção enquanto houver operações pendentes.

== Screenshots ==

1. A tela inicial do VilaHub explica a conexão entre a loja WooCommerce, as contas de marketplace e a confirmação explícita da publicação.
2. Lista de produtos do WooCommerce com o seletor de destino VilaHub e a coluna de status de restrição.
3. Tela inicial de conexão de marketplace, hospedada pelo serviço VilaHub autorizado.
4. Tela separada de pedidos de marketplaces com colunas de integração, status, fiscal, estoque e total.

== Changelog ==

= 1.0.2 =
* Adiciona o banner oficial, o banner em alta resolução e capturas reais da administração.
* Documenta as telas disponíveis na galeria do WordPress.org.

= 1.0.1 =
* Valida e sanitiza campos fiscais administrativos, vínculos de itens, quantidades devolvidas, identificadores e entrada de certificado.
* Limita o aviso de ativação às telas de Plugins e administração do VilaHub.
* Distribui a interface de suporte como código legível e documenta que o plugin não possui etapa de compilação ou minificação.

= 1.0.0 =
Primeira versão pública.
