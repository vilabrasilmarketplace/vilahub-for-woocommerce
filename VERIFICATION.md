# Verificação da versão 1.0.5

Data: 20/11/2026

## Escopo

A versão 1.0.5 corrige o estado do assistente de suporte:

- recria automaticamente uma conversa quando o token local não está disponível;
- evita a resposta repetida “Inicie uma nova conversa”;
- exibe uma única orientação para conectar a loja quando a credencial VilaHub é inválida;
- desabilita o envio pelo chat enquanto a loja não estiver conectada;
- mantém inalterados os fluxos de marketplaces, produtos, pedidos, fiscal e financeiro.

## Validação técnica

- Os arquivos PHP passaram em `php -l` usando PHP 8.1.
- O teste `tests/support-chat-test.php` confirmou a persistência do token e o estado `CONNECTION_REQUIRED`.
- `assets/support-chat.js` passou em `node --check`.
- O cabeçalho declara `Version: 1.0.5`.
- `VILAHUB_WC_VERSION` está em `1.0.5`.
- O `readme.txt` declara `Stable tag: 1.0.5`.
- A API do WordPress.org responde com a versão 1.0.5.

## Pacote oficial

- URL: `https://downloads.wordpress.org/plugin/vilahub-for-woocommerce.1.0.5.zip`
- Tamanho: 465.336 bytes.
- Arquivos internos: 38.
- SHA-256: `bedd238a8e3f72ac0b08afafe32f5db171a6156fc8b2312a43399d7042187114`.

Os 38 arquivos do pacote oficial possuem conteúdo idêntico à tag `1.0.5` do SVN.

## Publicação

- Revisão do trunk: `3812982`.
- Tag `1.0.5`: revisão `3812983`.

## Conclusão

A correção foi publicada no diretório oficial do WordPress.org. Nenhuma instalação de cliente foi alterada e cada loja decide quando atualizar.
