# VilaHub for WooCommerce

Official open-source WooCommerce plugin for the VilaHub marketplace integration service.

[WordPress.org plugin page](https://wordpress.org/plugins/vilahub-for-woocommerce/) · [VilaHub](https://vilahub.com.br) · [Support](https://wordpress.org/support/plugin/vilahub-for-woocommerce/)

## What it does

VilaHub for WooCommerce connects a WooCommerce store to the external VilaHub service. It provides:

- store authorization with OAuth and PKCE;
- marketplace account selection;
- reviewed product and variation transmission;
- supported price and inventory synchronization;
- publication results and correction flows;
- separate marketplace order records and administration screens;
- WooCommerce or external ERP inventory authority;
- HPOS and legacy WooCommerce storage compatibility.

The store can remain the source of catalog data. Each installation is bound to the authorized company, credentials, and store URL.

## Requirements

- WordPress 6.5 or later
- WooCommerce 9.0 or later
- PHP 8.1 or later
- OpenSSL
- HTTPS
- VilaHub account

## Installation

Install from the official WordPress plugin directory:

https://wordpress.org/plugins/vilahub-for-woocommerce/

After activation:

1. Open `VilaHub > Início`.
2. Authorize the store in VilaHub.
3. Connect a supported marketplace account.
4. Review one controlled product before enabling catalog synchronization.

Do not activate a second copy under a different plugin directory on the same store.

## Important boundaries

- VilaHub capabilities depend on the provider and account.
- The marketplace makes the final decision about a publication.
- Fiscal and shipping operations require channel support, configuration, and user confirmation.
- Native WooCommerce orders are not uploaded by the marketplace-order integration.

## Source and releases

- WordPress.org SVN: https://plugins.svn.wordpress.org/vilahub-for-woocommerce/
- GitHub releases: https://github.com/vilabrasilmarketplace/vilahub-for-woocommerce/releases
- Translation project: https://translate.wordpress.org/projects/wp-plugins/vilahub-for-woocommerce/

## License

GPL-2.0-or-later.

