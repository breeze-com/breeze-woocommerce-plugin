<?php
/**
 * Uninstall Breeze Payment Gateway
 *
 * @package Breeze_Payment_Gateway
 */

// Exit if accessed directly or not uninstalling
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 * 
 * Note: This file is only executed when the plugin is deleted via the WordPress admin,
 * not when it's simply deactivated.
 */

// Delete plugin options
delete_option( 'woocommerce_breeze_payment_gateway_settings' );

// Clean up user meta for Breeze customer IDs
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_breeze_customer_id'" );

// Clean up order meta for Breeze data
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_breeze_customer_id', '_breeze_payment_page_id', '_breeze_return_token')" );

// Clear any cached data that has been removed
wp_cache_flush();
