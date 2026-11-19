<?php
defined('ABSPATH') || exit;

final class VilaHub_Product {
    public static function payload(int $id): array {
        $product = wc_get_product($id);
        if (!$product || $product->get_status() !== 'publish') {
            throw new RuntimeException('Somente produtos publicados podem ser enviados. Nenhum produto remoto será excluído.');
        }
        if (!in_array($product->get_type(), array('simple', 'variable'), true)) {
            throw new RuntimeException('Esta versão aceita produtos simples e variáveis.');
        }
        if (get_woocommerce_currency() !== 'BRL') {
            throw new RuntimeException('A API do VilaHub usa BRL. Não há conversão automática de moedas.');
        }
        $sku = trim((string) $product->get_sku());
        if (!$sku || strlen($sku) > 100) {
            throw new RuntimeException('Cadastre um SKU de até 100 caracteres no produto.');
        }
        $title = trim(wp_strip_all_tags($product->get_name()));
        if (!$title || self::length($title) > 300) { throw new RuntimeException('O nome precisa ter de 1 a 300 caracteres.'); }
        $description = trim(wp_strip_all_tags($product->get_description() ?: $product->get_short_description()));
        if (self::length($description) > 20000) { throw new RuntimeException('A descrição excede 20.000 caracteres.'); }
        $payload = array(
            'sku' => $sku, 'title' => $title, 'description' => $description ?: null,
            'brand' => self::brand($product), 'gtin' => self::gtin($product), 'condition' => 'NEW',
            'priceInCents' => self::cents($product->get_price('edit')),
            'stock' => 0, 'variations' => array(), 'images' => self::images($product),
            'tags' => self::tags($product),
            'attributes' => array('wordpressOrigin' => array('siteId' => get_option('vilahub_site_id'), 'productId' => $id))
        );
        $ncm=(string)$product->get_meta('_vilahub_ncm');if(preg_match('/^\d{8}$/',$ncm))$payload['attributes']['ncm']=$ncm;
        $unit=(string)$product->get_meta('_vilahub_fiscal_unit');if($unit)$payload['attributes']['unit']=self::truncate($unit,6);
        $payload = array_merge($payload, self::dimensions($product));
        if ($product->is_type('variable')) {
            $children = $product->get_children();
            foreach ($children as $child_id) {
                $child = wc_get_product($child_id);
                if (!$child || $child->get_status() !== 'publish') { continue; }
                $child_sku = trim((string) $child->get_sku());
                if (!$child_sku || strlen($child_sku) > 100) { throw new RuntimeException('Todas as variações precisam de SKU próprio de até 100 caracteres.'); }
                $options = array();
                foreach ($child->get_attributes() as $attribute => $value) {
                    if ($value === '') { throw new RuntimeException('Defina um valor específico em cada atributo da variação; opções “qualquer” não são enviadas.'); }
                    $term = taxonomy_exists($attribute) ? get_term_by('slug', $value, $attribute) : false;
                    $options[] = array('name' => wc_attribute_label($attribute, $product), 'value' => $term ? $term->name : $value);
                }
                if (count($options) > 2) { throw new RuntimeException('A API aceita até dois atributos por variação.'); }
                $variation = array_merge(array(
                    'sku' => $child_sku, 'name' => self::truncate(wp_strip_all_tags($child->get_name()), 300),
                    'gtin' => self::gtin($child), 'priceInCents' => self::cents($child->get_price('edit')),
                    'stock' => self::stock($child), 'options' => $options
                ), self::dimensions($child));
                $payload['variations'][] = $variation;
                $payload['stock'] += $variation['stock'];
            }
            if (!$payload['variations']) { throw new RuntimeException('O produto variável não possui variações publicadas.'); }
            if (count($payload['variations']) > 100) { throw new RuntimeException('A API aceita até 100 variações por produto.'); }
            $skus = array_column($payload['variations'], 'sku');
            if (count(array_unique($skus)) !== count($skus)) { throw new RuntimeException('Os SKUs das variações devem ser distintos.'); }
        } else {
            $payload['stock'] = self::stock($product);
        }
        if ($payload['stock'] > 2147483647) { throw new RuntimeException('O estoque excede o limite da API.'); }
        $category = VilaHub_Store::settings()['category_id'];
        if ($category) { $payload['categoryId'] = $category; }
        return $payload;
    }

    public static function cents($value): int {
        $decimal = wc_format_decimal($value, 2);
        if ($value === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $decimal)) { throw new RuntimeException('Cadastre um preço válido e não negativo.'); }
        $parts = explode('.', $decimal);
        $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($cents > 2147483647) { throw new RuntimeException('O preço excede o limite da API.'); }
        return $cents;
    }

    private static function stock($product): int {
        if ($product->get_manage_stock('edit') !== true) {
            throw new RuntimeException('Ative o controle de estoque com quantidade própria no produto e em cada variação. Estoque compartilhado com o pai não é distribuído automaticamente.');
        }
        $quantity = $product->get_stock_quantity('edit');
        if (!is_numeric($quantity) || (float) $quantity !== floor((float) $quantity) || (float) $quantity > 2147483647) {
            throw new RuntimeException('O estoque deve ser uma quantidade inteira válida.');
        }
        return $product->get_stock_status('edit') === 'outofstock' ? 0 : max(0, (int) $quantity);
    }

    private static function dimensions($product): array {
        $result = array('weightGrams' => null, 'widthCm' => null, 'heightCm' => null, 'lengthCm' => null);
        $weight = $product->get_weight();
        if ($weight !== '' && (float) $weight > 0) { $result['weightGrams'] = max(1, (int) round(wc_get_weight($weight, 'g'))); }
        foreach (array('width', 'height', 'length') as $field) {
            $value = $product->{'get_' . $field}();
            if ($value !== '' && (float) $value > 0) { $result[$field . 'Cm'] = round((float) wc_get_dimension($value, 'cm'), 2); }
        }
        return $result;
    }

    private static function gtin($product): ?string {
        $gtin = method_exists($product, 'get_global_unique_id') ? trim((string) $product->get_global_unique_id()) : '';
        if ($gtin !== '' && !preg_match('/^\d{8,14}$/', $gtin)) { throw new RuntimeException('O GTIN deve conter de 8 a 14 dígitos.'); }
        return $gtin ?: null;
    }

    public static function restriction_snapshot(int $id): array {
        $product = wc_get_product($id);
        if (!$product || get_post_type($id)!=='product') { throw new RuntimeException('Produto inválido.'); }
        $snapshot = array('id'=>(string)$id, 'title'=>wp_strip_all_tags($product->get_name()), 'description'=>wp_strip_all_tags($product->get_description() ?: $product->get_short_description()), 'brand'=>self::brand($product) ?? '');
        // Never truncate text: a prohibited term may be at the end.
        if (mb_strlen($snapshot['title'])>300 || mb_strlen($snapshot['description'])>20000 || strlen(wp_json_encode($snapshot))>180000) { throw new RuntimeException('Texto excede o limite da consulta.'); }
        return $snapshot;
    }

    private static function brand($product): ?string {
        $terms = taxonomy_exists('product_brand') ? wp_get_post_terms($product->get_id(), 'product_brand', array('fields' => 'names')) : array();
        $brand = !is_wp_error($terms) && $terms ? $terms[0] : $product->get_attribute('pa_marca');
        return $brand ? self::truncate(wp_strip_all_tags($brand), 200) : null;
    }

    private static function images($product): array {
        $ids = array_values(array_unique(array_filter(array_merge(array($product->get_image_id()), $product->get_gallery_image_ids()))));
        if (count($ids) > 10) { throw new RuntimeException('Selecione no máximo 10 imagens no produto.'); }
        $images = array();
        foreach ($ids as $id) {
            $url = wp_get_attachment_url($id);
            if (!$url || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || strlen($url) > 2048) { throw new RuntimeException('As imagens precisam ter URLs HTTPS públicas válidas.'); }
            $images[] = $url;
        }
        return $images;
    }

    private static function tags($product): array {
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        if (is_wp_error($tags)) { return array(); }
        return array_slice(array_map(function ($value) { return self::truncate(sanitize_text_field($value), 100); }, $tags), 0, 30);
    }

    public static function truncate(string $value, int $limit): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
    private static function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }

    public static function delta(array $current, array $previous): array {
        $result = array();
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $previous) || self::hash(array('value' => $value)) !== self::hash(array('value' => $previous[$key]))) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    public static function hash(array $payload): string { return hash('sha256', VilaHub_Store::json($payload)); }
}
