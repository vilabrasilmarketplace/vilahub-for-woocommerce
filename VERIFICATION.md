# Verificação da versão 1.0.4

Data: 20/11/2026

## Escopo

A versão 1.0.4 altera somente metadados de distribuição, documentação e descoberta:

- homepage oficial do plugin;
- README-base em inglês;
- tags do diretório;
- perguntas frequentes;
- links para os guias de marketplace;
- versão e changelog.

As classes PHP e o comportamento funcional permanecem iguais aos da versão 1.0.3.

## Pacote oficial

- URL: `https://downloads.wordpress.org/plugin/vilahub-for-woocommerce.1.0.4.zip`
- Arquivos: 38
- SHA-256 do ZIP oficial: `03609b01c2371dca6dc27776f545f9ad82833a184be450c6d6b259c5927ea0b9`
- SHA-256 do pacote gerado para o GitHub: `9a7513f609227767d131599e686cd77498739861bb9f232f1e4c9371f5fe4f12`

Os ZIPs possuem metadados de arquivo diferentes, mas os 38 arquivos internos possuem conteúdo idêntico.

## Validação técnica

- Os 17 arquivos PHP passaram em `php -l` usando PHP 8.3.
- O cabeçalho declara `Version: 1.0.4`.
- `VILAHUB_WC_VERSION` está em `1.0.4`.
- `Plugin URI` aponta para `https://vilahub.com.br/woocommerce`.
- O `readme.txt` declara `Stable tag: 1.0.4`.
- O diretório público retorna a versão 1.0.4, a homepage correta e a FAQ.
- A API oficial lista as tags `woocommerce`, `mercado-livre`, `shopee`, `magalu` e `amazon`.

## Publicação

- Revisão do trunk: `3812928`.
- Tag `1.0.4`: revisão `3812929`.
- Release GitHub: `v1.0.4`.

## Conclusão

A versão foi publicada com sucesso e o pacote oficial foi conferido contra o pacote da release. Não houve migração funcional de dados nem alteração nas integrações já instaladas.
