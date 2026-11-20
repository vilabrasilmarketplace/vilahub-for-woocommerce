# Verificação da versão 1.0.6

Data: 20/11/2026

## Escopo

A versão 1.0.6 libera o assistente de suporte antes da conexão da loja:

- usa a API pública de atendimento, sem exigir credencial da loja;
- mantém a base específica do plugin disponível no chat;
- limita as chamadas por instalação e mantém a proteção por sessão administrativa e nonce;
- usa nome e e-mail do usuário do WordPress somente quando ele pede atendimento humano;
- preserva a correção de token e o tratamento de erros da versão 1.0.5;
- mantém inalterados os fluxos de marketplaces, produtos, pedidos, fiscal e financeiro.

## Validação técnica

- Os arquivos PHP passaram em `php -l` usando PHP 8.1.
- O teste `tests/support-chat-test.php` confirmou o uso da API pública, a persistência do token e a ausência de credencial da loja na requisição.
- `assets/support-chat.js` passou em `node --check`.
- O cabeçalho declara `Version: 1.0.6`.
- `VILAHUB_WC_VERSION` está em `1.0.6`.
- O `readme.txt` declara `Stable tag: 1.0.6`.
- A API do WordPress.org responde com a versão 1.0.6.

## Pacote oficial

- URL: `https://downloads.wordpress.org/plugin/vilahub-for-woocommerce.1.0.6.zip`
- Tamanho: 465.574 bytes.
- Arquivos internos: 38.
- SHA-256: `f35c9c1f1fcde8ef095359ca7fb684facf0afef44a5bc63db7a2ab6ca4d13d48`.

Os 38 arquivos do pacote oficial possuem conteúdo idêntico à tag `1.0.6` do SVN.

## Publicação

- Revisão do trunk: `3813001`.
- Tag `1.0.6`: revisão `3813002`.

## Conclusão

A correção foi publicada no diretório oficial do WordPress.org. Nenhuma instalação de cliente foi alterada e cada loja decide quando atualizar.
