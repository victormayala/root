<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function () {
    wp_enqueue_style(
        'printonet-storefront-style',
        get_stylesheet_uri(),
        ['storefront-style'],
        wp_get_theme()->get('Version')
    );

    // Reinforce dashboard accent on Storefront + WooCommerce blocks (Customizer mods cover most of the theme).
    $accent = get_option('printonet_brand_primary_color', '#111111');
    $accent = $accent !== '' ? $accent : '#111111';
    wp_add_inline_style(
        'printonet-storefront-style',
        '.woocommerce-breadcrumb a:hover, ul.products li.product .price, p.price { color: ' . esc_html($accent) . '; }
        .wc-block-components-button:not(.is-link):not(.wc-block-cart__submit-button) {
          background-color: ' . esc_html($accent) . ';
          color: #ffffff;
        }'
    );
});

add_action('wp_head', static function () {
    $primary = get_option('printonet_brand_primary_color', '#111111');
    $secondary = get_option('printonet_brand_secondary_color', '#ffffff');
    $font = get_option('printonet_brand_font_family', 'inherit');
    ?>
    <style>
      :root {
        --printonet-primary: <?php echo esc_html($primary); ?>;
        --printonet-secondary: <?php echo esc_html($secondary); ?>;
        --printonet-font-family: <?php echo esc_html($font); ?>;
      }
      body {
        font-family: var(--printonet-font-family);
      }
    </style>
    <?php
});

add_action('template_redirect', static function () {
    if (!function_exists('is_page') || !is_page('suspended')) {
        return;
    }

    if (!class_exists('Printonet_Tenant_Control') || !Printonet_Tenant_Control::is_suspended()) {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    status_header(503);
    nocache_headers();
});
