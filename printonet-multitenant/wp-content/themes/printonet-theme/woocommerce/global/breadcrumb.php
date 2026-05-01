<?php
/**
 * Hide breadcrumbs on product listing URLs only (single product etc. keep them).
 *
 * @package WooCommerce/Templates
 */

defined('ABSPATH') || exit;

$hide = false;
if (function_exists('is_shop') && (is_shop() || is_product_taxonomy())) {
    $hide = true;
} elseif (function_exists('is_post_type_archive') && is_post_type_archive('product')) {
    $hide = true;
}

if ($hide) {
    return;
}

$core = WC()->plugin_path() . '/templates/global/breadcrumb.php';
if (is_readable($core)) {
    include $core;
}
