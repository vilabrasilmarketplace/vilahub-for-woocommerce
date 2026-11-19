=== VilaHub – Marketplaces for WooCommerce ===
Contributors: vilahub
Tags: integração, mercado livre, shopee, magalu, amazon
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to Mercado Livre, Shopee, Magalu, Amazon, SHEIN and other marketplaces. Manage products, orders, inventory and invoices.

== Description ==

VilaHub connects WooCommerce to its external integration service. From WordPress you can authorize accounts, configure prices, send products, review publications and manage supported marketplace orders.

* Explicit OAuth authorization with PKCE; no WordPress password sharing.
* Product selection, destination account, price review and explicit publication confirmation.
* Background transmission of products, variations, prices, stock, images and attributes.
* Publication status, channel validation and supported category/attribute corrections.
* Separate marketplace order screen; imported sales are not native WooCommerce orders.
* WooCommerce or external ERP inventory authority, with idempotent local deductions.
* Supported invoices and shipping documents, always behind a separate user action.
* Fiscal, financial and plugin-support screens connected to the authorized VilaHub company.

Requires WooCommerce 9.0+, WordPress 6.5+, PHP 8.1+, OpenSSL, HTTPS and a VilaHub account. The interface is in Brazilian Portuguese. HPOS and legacy storage are supported.

= External service and data transmission =

The plugin depends on VilaHub for the catalog, marketplace connections, publication queue, marketplace orders and supported fiscal/shipping services. It cannot perform those operations offline.

Service endpoints: https://vilahub.com.br/api/v1 and https://vilahub.com.br/api/oauth. Authorization is hosted at https://vilahub.com.br/conectar/woocommerce. API calls use HTTPS; official marketplace authorization pages open only when the seller chooses to connect an account.

Information is exchanged as follows:

* Connection sends the callback URL, installation/client identifiers and OAuth PKCE/state parameters. Tokens are encrypted locally with keys derived from WordPress security salts. Authorization can be revoked, and old grants do not silently gain scopes.
* Product restriction preview sends product IDs, titles, descriptions and brands in bounded batches. It does not publish products.
* An explicit send or enabled synchronization can transmit SKU, title, plain-text description, brand, GTIN, price, stock, dimensions, weight, tags, public image URLs, variations, attributes, NCM/unit when configured, and local installation/operation identifiers.
* Channel connection sends seller/store identifiers, company document when supplied and credentials entered for key-based providers. OAuth providers use their official flow. The plugin does not download executable code from VilaHub.
* After order permission and account activation, periodic requests can receive buyer/contact/address data, items, totals, payment/fulfillment/tracking status, fiscal status and document references. Fields depend on the marketplace and are kept in private local plugin tables.
* Order activation, inventory authority, acknowledgements, product links and explicit order/fiscal/shipping actions are sent to VilaHub. Native store orders are not uploaded by this feature.
* Fiscal configuration sends issuer, address, contact, accountant and tax data. An administrator can upload an A1 PFX/P12 certificate and password or an authorized invoice XML. Invoice issuance, consultation, marketplace submission and label requests use VilaHub and the authorized providers. No invoice is issued merely by opening or importing an order.
* Finance requests subscription, usage, invoices, credits and dropshipping commission summaries. Payments continue on the authenticated VilaHub page; the plugin does not collect card or bank credentials.
* Support chat sends the question, plugin page/version and store URL. Human transfer sends the transcript and account identity. Never send passwords, tokens, certificates or banking credentials in chat.

The plugin does not send WordPress passwords, card/bank credentials or native store order lists. It processes buyer and fiscal data as described; account for this in store privacy practices. Public product images can be fetched from supplied URLs (up to ten HTTPS URLs and ten MB per image).

Optional WhatsApp support opens only when clicked and receives no buyer/order data automatically.

* Service: https://vilahub.com.br
* Terms: https://vilahub.com.br/termos
* Privacy: https://vilahub.com.br/privacidade
* Pricing: https://vilahub.com.br/planos
* API documentation: https://vilahub.com.br/api/v1/docs

= Source code, assets and build =

The complete human-readable source code is included in this plugin package. PHP, JavaScript, CSS and vector assets are distributed as source files; no compiled, generated, obfuscated or minified code is required at runtime. The package has no dependency installation or build step: the files in this directory are the files that are shipped and maintained.

= Plans and integrations =

The external service has a permanent Free plan (up to ten products and one active integration per company), an eligible thirty-day Full trial and paid usage under current terms. Quotas apply to the company, not just this store.

VilaHub's wider ecosystem includes Mercado Livre, Magalu, Shopee, VilaBrasil/Medusa, Amazon, TikTok Shop, MadeiraMadeira, Leroy Merlin, Netshoes/Zattini, Americanas/B2W, SHEIN, Carrefour, AliExpress, Dafiti, Bling, ERP TINY/Olist, Omie, WooCommerce, Loja Integrada, Nuvemshop, Shopify and Tray. Capabilities, approval and availability differ by provider and account; this list does not promise identical operations or a direct connector in this plugin for every service.

= Supported operations =

Connection screens exist for Mercado Livre, Shopee, Magalu, Amazon, TikTok Shop, VilaBrasil, SHEIN, Netshoes, MadeiraMadeira, Americanas/SkyHub and Leroy Merlin. Marketplace order reception is currently offered for Mercado Livre, Magalu, Shopee, TikTok Shop, VilaBrasil and MadeiraMadeira after per-account activation.

* Magalu: supported fiscal profile/A1 setup, preview, direct issuance for CRT 1, consultation, authorized external XML, submission, XML/DANFE and available labels. Other regimes use authorized external XML where supported.
* Mercado Livre: official label PDF subject to shipment/printable-status checks. Invoice submission is not part of this workflow.
* TikTok Shop: package consultation, validated authorized XML and shipping documents when released by the channel. Unsupported grouping/dispatch stays in Seller Center.
* Shopee, VilaBrasil and MadeiraMadeira: order information and documents supplied by the service; no new generic fiscal/label action is added by this release.

Mercado Livre and Shopee expose supported category/attribute preparation. Connecting Amazon, SHEIN or Leroy Merlin does not create a generic batch publisher. Provider credentials, account approval and channel rules always apply.

== Installation ==

1. Install WooCommerce and activate VilaHub.
2. Open VilaHub > Início, choose Começar, sign in or create the service account, confirm the company and authorize the store.
3. Use VilaHub > Conectar Canal de Venda to authorize an account; manage it in Minhas Integrações.
4. In Products > All products, select products, choose Enviar para marketplace, choose the destination, apply, review prices and confirm.
5. Review results in VilaHub > Produtos publicados.
6. In VilaHub > Pedidos, configure order reception, activate the account and choose WooCommerce or ERP inventory authority. Reauthorize if the old grant lacks the necessary scopes.
7. Manage marketplace orders, item links, inventory pending items and available documents in the separate order screen.

No WooCommerce REST key or WordPress password is required. Legacy manual VilaHub keys remain supported. Never activate a second copy under a different plugin folder on the same store.

== Important behavior ==

Prices use BRL and inventory uses whole quantities. No currency conversion, invented tax data or fractional allocation is performed. Products/variations need distinct SKUs and their own stock; negative quantity is sent as zero. The API accepts up to 100 variations, two attributes per variation and replaces the variation list as a whole. Existing SKU links require explicit approval before their first transmitted update. Successful catalog synchronization does not mean a marketplace listing is published.

Queues use bounded batches, persistent operation identifiers and retries. WordPress cron and WooCommerce Action Scheduler must run; low-traffic stores may need a server scheduler.

Marketplace orders remain separate from native WooCommerce orders. With WooCommerce as inventory authority, linked stock is changed through its product API; observers can see the change. With ERP authority, the plugin makes no local deduction. Paid-order deductions and pre-dispatch cancellations are idempotent. Old or undated sales require explicit confirmation. Refunds or dispatched goods never return stock automatically; confirm only physically received quantities. The plugin does not issue marketplace financial refunds.

Fiscal actions require supported channel capability, configuration and user review. An uncertain result is consulted instead of retransmitted automatically. Real provider/SEFAZ homologation is separate from synthetic tests.

Each installation is bound to the authorized company, credentials and store URL. A cloned origin blocks synchronization until reviewed. Multisite network activation is unsupported; activate per store. Changing WordPress security salts requires reconnection.

== Removal ==

Deactivation stops scheduled tasks and preserves data. Disconnect first to revoke remote authorization; uninstall does not make a remote deletion request. By default uninstall retains mappings, private marketplace orders, inventory ledgers and migration backups for recovery, while removing local secrets and pausing synchronization. Products and normal native orders are not deleted.

Defining `VILAHUB_DELETE_DATA_ON_UNINSTALL` as `true` in `wp-config.php` permanently deletes local plugin tables/options during uninstall, including private orders and migration backups. Do not use it while operations are unresolved.

== Changelog ==

= 1.0.1 =
* Validate and sanitize administrative fiscal fields, item links, return quantities, identifiers and certificate input.
* Limit the activation notice to the Plugins and VilaHub administration screens.
* Ship the support interface as readable source and document that the plugin has no build or minification step.

= 1.0.0 =
Initial public release.
