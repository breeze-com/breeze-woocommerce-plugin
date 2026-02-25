=== Breeze Payment Gateway ===
Contributors: breeze
Tags: breeze, payments, payment gateway, woocommerce, checkout
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through Breeze payment gateway for WooCommerce.

== Description ==

Breeze Payment Gateway adds a secure Breeze checkout option to WooCommerce. It creates Breeze customers and products for each order, redirects customers to a Breeze payment page, and updates orders via webhooks.

**Don't have a Breeze merchant account yet?** [Contact our sales team](https://breeze.com/sales) to get started.

Features:

* Full Breeze API integration
* Automatic customer creation in Breeze
* Dynamic product creation for orders
* Secure payment page redirects
* Webhook support for payment notifications
* Test mode and live mode support
* HPOS (High-Performance Order Storage) compatible
* Internationalization ready
* WooCommerce Blocks compatible
* Debug logging

== Installation ==

1. Upload the `breeze-payment-gateway` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "Breeze Payment Gateway"
5. Click "Manage" to configure settings

== Configuration ==

Navigate to WooCommerce > Settings > Payments > Breeze Payment Gateway.

= General Settings =
* Enable/Disable: Enable or disable the payment gateway
* Title: The title customers see during checkout (default: "Breeze Payment")
* Description: Payment method description shown at checkout

= API Credentials =
* Test Mode: Enable to use test API credentials
* Test API Key: Your Breeze test environment API key
* Live API Key: Your Breeze production API key

= Debug Options =
* Debug Log: Enable logging for troubleshooting (logs saved to WooCommerce logs)

= Getting Your API Key =
1. Log in to your Breeze Dashboard
2. Navigate to Developer > API Keys
3. Generate a new API key
4. Paste the key into the appropriate field (Test or Live)

== How It Works ==

= Payment Flow =
1. Customer adds items to cart and proceeds to checkout
2. Plugin creates or retrieves the customer in Breeze
3. Plugin creates products in Breeze for each order item
4. Plugin generates a Breeze payment page with order details
5. Customer is redirected to Breeze payment page
6. Customer completes payment on Breeze
7. Customer is redirected back to your store
8. Order is marked as complete

= Webhooks =
Configure your Breeze account to send webhooks to:
`https://yoursite.com/?wc-api=breeze_payment_gateway`

Supported events:
* payment.succeeded
* payment.failed

= Return URLs =
* Success: `https://yoursite.com/?wc-api=breeze_return&order_id={id}&status=success`
* Failed: `https://yoursite.com/?wc-api=breeze_return&order_id={id}&status=failed`

== Order Processing ==

For each order, the plugin creates:

1. Customer (if not already existing)
2. Products (one per order item, plus shipping and taxes if applicable)
3. Payment Page

Order metadata stored:
* _breeze_customer_id
* _breeze_payment_page_id

== Security ==

* Input sanitization using WordPress helpers
* Output escaping using WordPress helpers
* Direct file access prevention
* Secure API communication over HTTPS
* Base64 encoded API authentication
* WooCommerce nonce verification

== Requirements ==

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* SSL certificate (required for live payments)
* Active Breeze account

== Currency Support ==

The plugin supports all currencies supported by Breeze. The currency is taken from WooCommerce store settings.

== Refunds ==

Refunds must be processed manually through your Breeze dashboard. If you attempt a WooCommerce refund, the plugin will instruct you to process it in Breeze.

== Testing ==

1. Enable Test Mode in plugin settings
2. Add your Test API Key
3. Enable Debug Log
4. Place test orders
5. Check WooCommerce logs for errors
6. Verify orders in your Breeze dashboard

== Frequently Asked Questions ==

= Does this support HPOS? =
Yes. The plugin declares HPOS compatibility.

= Does this support WooCommerce Blocks? =
Yes. The plugin registers a WooCommerce Blocks payment method.

= Where are the logs? =
Enable Debug Log, then go to WooCommerce > Status > Logs.

= Can I refund from WooCommerce? =
Refunds must be processed in your Breeze dashboard.

== Screenshots ==

1. Plugin page screenshot 1
2. Plugin page screenshot 2
3. Plugin page screenshot 3

== Changelog ==

= 1.0.0 =
* Initial release
* Breeze API integration
* Customer creation and management
* Product creation for order items
* Payment page generation
* Webhook support
* Return URL handling
* HPOS compatibility
* WooCommerce Checkout Blocks support
* Debug logging

== Upgrade Notice ==

= 1.0.0 =
Initial release.
