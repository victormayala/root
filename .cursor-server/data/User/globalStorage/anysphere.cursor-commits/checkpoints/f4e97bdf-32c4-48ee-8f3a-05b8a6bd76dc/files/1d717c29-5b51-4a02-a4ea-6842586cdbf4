<?php

if (!defined('ABSPATH')) {
    exit;
}

class Printonet_Customizer_Order_Meta
{
    public static function init(): void
    {
        add_filter('woocommerce_add_cart_item_data', [self::class, 'capture_customizer_payload'], 10, 3);
        add_filter('woocommerce_get_item_data', [self::class, 'render_cart_meta'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'persist_order_meta'], 10, 4);
    }

    public static function capture_customizer_payload(array $cart_item_data, int $product_id, int $variation_id): array
    {
        unset($product_id, $variation_id);

        $design_json = isset($_POST['printonet_design_json']) ? wp_unslash((string) $_POST['printonet_design_json']) : '';
        $preview_url = isset($_POST['printonet_design_preview_url']) ? wp_unslash((string) $_POST['printonet_design_preview_url']) : '';
        $print_file_url = isset($_POST['printonet_print_file_url']) ? wp_unslash((string) $_POST['printonet_print_file_url']) : '';

        if ($design_json !== '') {
            $decoded = json_decode($design_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $cart_item_data['printonet_design_json'] = wp_json_encode($decoded);
            }
        }
        if ($preview_url !== '') {
            $cart_item_data['printonet_design_preview_url'] = esc_url_raw($preview_url);
        }
        if ($print_file_url !== '') {
            $cart_item_data['printonet_print_file_url'] = esc_url_raw($print_file_url);
        }

        if (!empty($cart_item_data['printonet_design_json'])) {
            // Unique key prevents Woo from merging separate customized items.
            $cart_item_data['printonet_customizer_key'] = md5($cart_item_data['printonet_design_json'] . microtime(true));
        }

        return $cart_item_data;
    }

    public static function render_cart_meta(array $item_data, array $cart_item): array
    {
        if (!empty($cart_item['printonet_design_preview_url'])) {
            $item_data[] = [
                'name' => __('Design Preview', 'printonet-storefront'),
                'value' => esc_url($cart_item['printonet_design_preview_url']),
                'display' => sprintf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($cart_item['printonet_design_preview_url']),
                    esc_html__('View design', 'printonet-storefront')
                ),
            ];
        }

        if (!empty($cart_item['printonet_print_file_url'])) {
            $item_data[] = [
                'name' => __('Print File', 'printonet-storefront'),
                'value' => esc_url($cart_item['printonet_print_file_url']),
            ];
        }

        return $item_data;
    }

    public static function persist_order_meta(WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order): void
    {
        unset($cart_item_key, $order);

        if (!empty($values['printonet_design_json'])) {
            $item->add_meta_data('_printonet_design_json', (string) $values['printonet_design_json']);
        }
        if (!empty($values['printonet_design_preview_url'])) {
            $item->add_meta_data('_printonet_design_preview_url', esc_url_raw((string) $values['printonet_design_preview_url']));
        }
        if (!empty($values['printonet_print_file_url'])) {
            $item->add_meta_data('_printonet_print_file_url', esc_url_raw((string) $values['printonet_print_file_url']));
        }
    }
}
