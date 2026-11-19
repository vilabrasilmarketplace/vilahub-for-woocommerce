# Verificação da versão 1.0.3

Data: 19/11/2026  
Ambiente: WordPress isolado em Docker, sem dados de clientes.

## Versões do ambiente

- WordPress: 7.1.4.
- WooCommerce: 11.3.0.
- PHP: 8.3.36.
- Plugin anterior: 1.0.1 ativo.
- Pacote usado: `https://downloads.wordpress.org/plugin/vilahub-for-woocommerce.1.0.3.zip`.

## Instalação

O pacote oficial foi instalado com substituição da versão anterior e ativação:

```text
Downloading installation package...
Unpacking the package...
Installing the plugin...
Removing the old version of the plugin...
Plugin updated successfully.
Activating 'vilahub-for-woocommerce'...
Success: Installed 1 of 1 plugins.
```

## Resultado

```json
{
  "name": "vilahub-for-woocommerce",
  "version": "1.0.3",
  "status": "active",
  "requires_php": "8.1",
  "requires_plugins": "woocommerce"
}
```

O teste de carregamento retornou:

```text
1.0.3|woocommerce-ok|plugin-ok
```

## Ciclo de ativação

A desativação e a reativação foram concluídas:

```text
Plugin 'vilahub-for-woocommerce' deactivated.
Success: Deactivated 1 of 1 plugins.
Plugin 'vilahub-for-woocommerce' activated.
Success: Activated 1 of 1 plugins.
```

## Checksums

O WordPress.org confirmou os arquivos oficiais:

```text
Success: Verified 1 of 1 plugins.
```

## Conclusão

- O ZIP oficial instala e substitui a versão anterior.
- A versão carregada é `1.0.3`.
- O plugin permanece ativo com WooCommerce 11.3.0.
- As classes principais e a constante de versão foram carregadas.
- Desativação e reativação não geraram erro.
- Nenhum `debug.log` foi criado durante o teste.

