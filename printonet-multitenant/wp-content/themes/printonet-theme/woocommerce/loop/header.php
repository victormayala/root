<?php
/**
 * Product archive header (Shop, categories, attributes): omit WooCommerce default
 * {@see woocommerce-products-header} wrapper and archive title — descriptions only.
 *
 * Loaded exclusively via {@see woocommerce_product_taxonomy_archive_header} on
 * {@see woocommerce_shop_loop_header}; must never delegate back to core (that would
 * resurrect the “Shop” block users delete in previews).
 *
 * @package WooCommerce\Templates
 * @version 8.6.0
 */

defined('ABSPATH') || exit;

/**
 * Hook: woocommerce_archive_description.
 *
 * @hooked woocommerce_taxonomy_archive_description - 10
 * @hooked woocommerce_product_archive_description - 10
 */
do_action('woocommerce_archive_description');
