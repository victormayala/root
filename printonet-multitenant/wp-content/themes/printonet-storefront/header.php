<?php
/**
 * Custom header layout for Printonet Storefront.
 *
 * @package printonet-storefront
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="http://gmpg.org/xfn/11">
<link rel="pingback" href="<?php bloginfo('pingback_url'); ?>">
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php do_action('storefront_before_site'); ?>

<div id="page" class="hfeed site">
  <?php do_action('storefront_before_header'); ?>

  <header id="masthead" class="site-header printonet-header" role="banner" style="<?php storefront_header_styles(); ?>">
    <div class="col-full printonet-header__top">
      <?php storefront_skip_links(); ?>
      <div class="printonet-header__brand">
        <?php storefront_site_branding(); ?>
      </div>
      <div class="printonet-header__search">
        <?php storefront_product_search(); ?>
      </div>
      <div class="printonet-header__meta">
        <?php if (function_exists('storefront_secondary_navigation')) : ?>
          <div class="printonet-header__secondary-nav">
            <?php storefront_secondary_navigation(); ?>
          </div>
        <?php endif; ?>

        <nav class="printonet-header__auth" aria-label="<?php echo esc_attr__('Account', 'printonet-storefront'); ?>">
          <?php
          $account_url = function_exists('wc_get_page_permalink')
              ? wc_get_page_permalink('myaccount')
              : home_url('/my-account/');
          if (is_user_logged_in()) :
              ?>
            <a href="<?php echo esc_url($account_url); ?>"><?php echo esc_html__('My account', 'printonet-storefront'); ?></a>
          <?php else : ?>
            <a href="<?php echo esc_url($account_url); ?>"><?php echo esc_html__('Login', 'printonet-storefront'); ?></a>
            <span>/</span>
            <a href="<?php echo esc_url($account_url); ?>"><?php echo esc_html__('Register', 'printonet-storefront'); ?></a>
          <?php endif; ?>
        </nav>
      </div>
    </div>

    <div class="storefront-primary-navigation printonet-header__nav">
      <div class="col-full">
        <div class="printonet-header__nav-main">
          <?php storefront_primary_navigation(); ?>
        </div>
        <div class="printonet-header__nav-actions">
          <?php storefront_header_cart(); ?>
        </div>
      </div>
    </div>
  </header>

  <?php do_action('storefront_before_content'); ?>
  <div id="content" class="site-content" tabindex="-1">
    <div class="col-full">
      <?php do_action('storefront_content_top'); ?>
