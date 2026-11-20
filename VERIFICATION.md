# Verificação da versão 1.0.7

Data: 20/11/2026

## Escopo

A versão 1.0.7 reposiciona o plugin para buscas com intenção de integração:

- adota o nome `Integração WooCommerce: Mercado Livre, Shopee e Magalu – VilaHub`;
- remove `woocommerce` das tags, pois o termo já integra o título e a descrição;
- mantém Mercado Livre, Shopee, Magalu e Amazon;
- adiciona TikTok Shop como quinta tag;
- preserva o comportamento funcional da versão 1.0.6.

## Validação técnica

- Os arquivos PHP passaram em `php -l` usando PHP 8.1.
- O teste `tests/support-chat-test.php` passou.
- `assets/support-chat.js` passou em `node --check`.
- O cabeçalho declara `Version: 1.0.7`.
- `VILAHUB_WC_VERSION` está em `1.0.7`.
- O `readme.txt` declara `Stable tag: 1.0.7`.
- A API do WordPress.org responde com a versão 1.0.7.
- O diretório retorna as tags `amazon`, `magalu`, `mercado-livre`, `shopee` e `tiktok-shop`.

## Pacote oficial

- URL: `https://downloads.wordpress.org/plugin/vilahub-for-woocommerce.1.0.7.zip`
- Tamanho: 465.699 bytes.
- Arquivos internos: 38.
- SHA-256: `2e881ee8438288a93beb27036b09995e00c396fa50b0aabd6fcd9a240400604c`.

Os 38 arquivos do pacote oficial possuem conteúdo idêntico à tag `1.0.7` do SVN.

## Publicação

- Revisão do trunk: `3813033`.
- Tag `1.0.7`: revisão `3813034`.

## Conclusão

A correção foi publicada no diretório oficial do WordPress.org. Nenhuma instalação de cliente foi alterada e cada loja decide quando atualizar.
