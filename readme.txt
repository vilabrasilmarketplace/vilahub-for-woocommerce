=== VilaHub – Marketplaces for WooCommerce ===
Contributors: vilahub
Tags: woocommerce, mercado livre, shopee, magalu, amazon
Requires at least: 6.5
Tested up to: 7.1.5
Requires PHP: 8.1
Stable tag: 1.0.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to Mercado Livre, Shopee, Magalu, Amazon, SHEIN and other marketplaces. Manage products, orders, inventory and fiscal documents.

== Description ==

VilaHub connects WooCommerce to its external integration service. From WordPress, authorized stores can connect marketplace accounts, configure prices, send products, review publication results and manage supported marketplace orders.

* Explicit OAuth authorization with PKCE, without sharing the WordPress password.
* Product selection, destination-account confirmation, price review and explicit publication confirmation.
* Background transmission of products, variations, prices, inventory, images and supported attributes.
* Publication status, marketplace validation and supported category or attribute correction.
* A separate marketplace-order area; imported marketplace sales are not native WooCommerce orders.
* Inventory authority can be set to WooCommerce or a connected ERP, with idempotent local deductions when applicable.
* Fiscal documents and shipping labels remain behind a separate user action.
* Fiscal, billing and support screens remain linked to the authorized VilaHub company.

VilaHub for WooCommerce requires WooCommerce 9.0+, WordPress 6.5+, PHP 8.1+, OpenSSL, HTTPS and a VilaHub account. The plugin interface is available in Brazilian Portuguese. HPOS and legacy WooCommerce storage are supported.

Documentation and installation:

* https://vilahub.com.br/woocommerce
* https://vilahub.com.br/woocommerce-mercado-livre
* https://vilahub.com.br/woocommerce-shopee
* https://vilahub.com.br/woocommerce-amazon

== External service and data transmission ==

The plugin depends on VilaHub for catalog management, marketplace connections, the publication queue, marketplace orders and supported fiscal or shipping services. These operations do not work offline.

Service endpoints: https://vilahub.com.br/api/v1 and https://vilahub.com.br/api/oauth. Connection authorization is hosted at https://vilahub.com.br/conectar/woocommerce. API calls use HTTPS. Official marketplace authorization pages open only after the merchant chooses to connect an account.

Data is exchanged as follows:

* Connection requests send the callback URL, installation or client identifiers, and OAuth PKCE/state parameters. Tokens are encrypted locally with keys derived from WordPress security salts. Authorization can be revoked, and older authorizations do not receive new scopes silently.
* A product-restriction preview sends IDs, titles, descriptions and brands in limited batches. It does not publish products.
* An explicit send or enabled synchronization may transmit SKU, title, plain-text description, brand, GTIN, price, inventory, dimensions, weight, tags, public image URLs, variations, attributes, NCM/unit when configured, and local installation or operation identifiers.
* Channel connection requests send seller or store identifiers, company tax identifiers when provided, and credentials entered for providers based on keys. OAuth providers use their official flow. The plugin does not download executable code from VilaHub.
* After order permission and account activation, periodic requests may receive buyer, contact and address data, items, totals, payment status, fulfillment and tracking, fiscal status and document references. The available fields depend on the marketplace and are stored in private plugin tables.
* Enabling order import, choosing inventory authority, confirming links, and explicit order, fiscal or shipping actions are sent to VilaHub. Native WooCommerce orders are not sent through this marketplace-order feature.
* Fiscal configuration sends issuer, address, contact, accountant and tax data. An administrator may send an A1 PFX/P12 certificate with its password or an authorized XML document. Document emission, lookup, marketplace submission and label requests use VilaHub and the authorized providers. No fiscal document is emitted merely by opening or importing an order.
* Billing screens request subscription, usage, invoice, credit and dropshipping-commission summaries. Payments remain on the authenticated VilaHub site; the plugin does not collect card or banking credentials.
* Support chat sends the question, page, plugin version and store URL. A transfer to human support sends the conversation history and account identity. Never send passwords, tokens, certificates or banking credentials through chat.

The plugin does not send WordPress passwords, card or banking credentials, or lists of native WooCommerce orders. It processes buyer and fiscal data as described above; include this flow in the store privacy policy. Public product images may be fetched from supplied HTTPS URLs, limited to ten URLs and ten MB per image.

Optional WhatsApp support opens only after a click and does not automatically receive buyer or order data.

* Service: https://vilahub.com.br
* Terms: https://vilahub.com.br/termos
* Privacy: https://vilahub.com.br/privacidade
* Plans: https://vilahub.com.br/planos
* API documentation: https://vilahub.com.br/api/v1/docs

== Source code and assets ==

The complete readable source code is included in the distributed package. PHP, JavaScript, CSS and vector assets are distributed as source files. No compiled, generated, obfuscated or minified code is required at runtime. The package has no dependency-installation or build step: the files in the package are the maintained files.

== Plans and integrations ==

The external service includes a permanent Free plan with up to ten products and one active integration per company, a 30-day Full trial when eligible, and paid use under current terms. Limits apply to the VilaHub company, not only to this store.

The wider VilaHub ecosystem includes Mercado Livre, Magalu, Shopee, VilaBrasil/Medusa, Amazon, TikTok Shop, MadeiraMadeira, Leroy Merlin, Netshoes/Zattini, Americanas/B2W, SHEIN, Carrefour, AliExpress, Dafiti, Bling, ERP TINY/Olist, Omie, WooCommerce, Loja Integrada, Nuvemshop, Shopify, Yampi and Tray. Features, approval and availability vary by provider and account. This list does not promise identical operations or a direct plugin connector for every service.

== Supported operations ==

Connection screens are available for Mercado Livre, Shopee, Magalu, Amazon, TikTok Shop, VilaBrasil, SHEIN, Netshoes, MadeiraMadeira, Americanas/SkyHub and Leroy Merlin. Marketplace order receiving is currently offered for Mercado Livre, Magalu, Shopee, TikTok Shop, VilaBrasil and MadeiraMadeira after each account is activated.

* Magalu: fiscal profile and A1 configuration, preview, direct emission for CRT 1, lookup, authorized external XML, submission, XML/DANFE and available labels. Other tax regimes use authorized external XML when supported.
* Mercado Livre: official shipping-label PDF subject to fulfillment and printing-availability checks. Fiscal document submission is not part of this flow.
* TikTok Shop: package lookup, validated authorized XML and shipping documents when released by the channel. Unsupported grouping or dispatch remains in Seller Center.
* Shopee, VilaBrasil and MadeiraMadeira: order information and documents supplied by the service; this version does not add a generic fiscal or shipping-label action.

Mercado Livre and Shopee offer category and attribute preparation when supported. Connecting Amazon, SHEIN or Leroy Merlin does not create a generic bulk publisher. Provider credentials, account approval and channel rules always apply.

== Installation ==

1. Install WooCommerce and activate VilaHub.
2. Open VilaHub > Home, choose Start, sign in to or create the service account, confirm the company and authorize the store.
3. Use VilaHub > Connect Sales Channel to authorize an account; manage connected accounts in My Integrations.
4. In Products > All Products, select items, choose Send to Marketplace, select the destination, apply, review prices and confirm.
5. Review results in VilaHub > Published Products.
6. In VilaHub > Orders, configure receiving, activate the account and choose WooCommerce or an ERP as inventory authority. Reauthorize if an older connection does not include the required scopes.
7. Manage marketplace orders, item links, inventory exceptions and available documents in the separate marketplace-order screen.

You do not need to enter a WooCommerce REST key or the WordPress password. Older VilaHub manual keys remain compatible. Never activate a second copy from another plugin folder on the same store.

== Frequently Asked Questions ==

= Does VilaHub replace WooCommerce? =

No. WooCommerce remains the store and can remain the catalog source. VilaHub adds a connected marketplace workflow for authorized accounts.

= Can I connect WooCommerce to Mercado Livre? =

Yes. Connect the store through the official plugin and authorize the Mercado Livre account through the marketplace flow. Product selection, price review and publication confirmation remain explicit.

= Can I connect WooCommerce to Shopee? =

Yes. WooCommerce products can be prepared for Shopee categories, attributes and variations. Start with a controlled product sample and confirm prices, inventory and publication results.

= Which marketplaces are supported? =

The directory listing presents Mercado Livre, Shopee, Magalu and Amazon as primary search tags. VilaHub also exposes connection screens for TikTok Shop, VilaBrasil, SHEIN, Netshoes, MadeiraMadeira, Americanas/SkyHub and Leroy Merlin. Availability depends on the account, provider and plan.

= Do I need a VilaHub account? =

Yes for marketplace operations. The WordPress plugin is free and GPL, but it is a client of the external VilaHub service and requires an authorized company account.

= Does the plugin work offline? =

No. Marketplace authorization, publication, order receiving and supported fiscal or shipping operations require VilaHub and the relevant provider.

= Are fiscal documents emitted automatically? =

No. Fiscal and shipping actions depend on channel capability, configuration and a separate user confirmation.

= Where can I find installation help? =

Visit https://vilahub.com.br/ajuda/plugin-woocommerce-instalacao for the installation and first-test guide.

== Screenshots ==

1. The VilaHub home screen explains the connection between the WooCommerce store, marketplace accounts and explicit publication confirmation.
2. WooCommerce product list with the VilaHub destination selector and product-restriction status.
3. Marketplace connection screen hosted by the authorized VilaHub service.
4. Separate marketplace orders screen with integration, status, fiscal, inventory and total fields.

== Changelog ==

= 1.0.5 =
* Prevents repeated "start a new conversation" errors when the support chat token is missing.
* Shows a single connection-required message and disables chat submission when the store is not connected to VilaHub.
* Starts a new support conversation automatically when an existing token is unavailable.
* Keeps marketplace, product, order, fiscal and billing behavior unchanged.

= 1.0.4 =
* Updates the official plugin homepage to the WooCommerce integration landing page.
* Adds an English directory README and preserves Portuguese through the WordPress.org translation system.
* Adds frequently asked questions and contextual links to WooCommerce marketplace guides.
* Replaces the generic integration tag with WooCommerce for directory discovery.
* Keeps the functional behavior of version 1.0.3.

= 1.0.3 =
* Publishes the Portuguese presentation of the plugin in the WordPress.org directory.
* Keeps the functional behavior of version 1.0.2.

= 1.0.2 =
* Adds the official banner, high-resolution banner and real administration screenshots.
* Documents screenshots available in the WordPress.org gallery.

= 1.0.1 =
* Validates and sanitizes administrative fiscal fields, item links, returned quantities, identifiers and certificate input.
* Limits activation notices to Plugins and VilaHub administration screens.
* Distributes the support interface as readable code and documents that the plugin has no compilation or minification step.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.5 =
Fixes the support assistant state when the store connection is missing or a conversation token has expired.

= 1.0.4 =
Directory metadata and discovery update. No functional migration or configuration change is required.
