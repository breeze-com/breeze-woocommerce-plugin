# Breeze Payment Gateway for WooCommerce
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-purple.svg)](https://woocommerce.com/)
[![Version](https://img.shields.io/badge/version-1.0.1-green.svg)](https://github.com/breeze-com/breeze-woocommerce-plugin)

![Breeze Payment Gateway](.github/images/banner.png)

## Features

- ✅ Full Breeze API integration
- ✅ Automatic customer creation in Breeze
- ✅ Dynamic product creation for orders
- ✅ Secure payment page redirects
- ✅ Webhook support for payment notifications
- ✅ Test mode and live mode support
- ✅ HPOS (High-Performance Order Storage) compatible
- ✅ Internationalization ready
- ✅ WooCommerce Blocks Compatible
- ✅ Debug logging
- ✅ Security best practices

## Installation

1. Upload the `breeze-payment-gateway` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "Breeze Payment Gateway"
5. Click "Manage" to configure settings

## Configuration

### Settings

Navigate to **WooCommerce > Settings > Payments > Breeze Payment Gateway**

> Don't have a Breeze merchant account yet? [Contact our sales team](https://breeze.com/sales) to get started.

#### General Settings
- **Enable/Disable**: Enable or disable the payment gateway
- **Title**: The title customers see during checkout (default: "Breeze Payment")
- **Description**: Payment method description shown at checkout

#### API Credentials
- **Test Mode**: Enable to use test API credentials
- **Test API Key**: Your Breeze test environment API key
- **Live API Key**: Your Breeze production API key

#### Debug Options
- **Debug Log**: Enable logging for troubleshooting (logs saved to WooCommerce logs)

### Getting Your API Key

1. Log in to your [Breeze Dashboard](https://dashboard.breeze.cash)
2. Navigate to Developer > API Keys
3. Generate a new API key
4. Paste the key into the appropriate field (Test or Live)

## How It Works

### Payment Flow

1. **Customer Checkout**: Customer adds items to cart and proceeds to checkout
2. **Customer Creation**: Plugin creates or retrieves customer in Breeze
3. **Product Creation**: Plugin creates products in Breeze for each order item
4. **Payment Page**: Plugin generates a Breeze payment page with all order details
5. **Redirect**: Customer is redirected to Breeze payment page
6. **Payment**: Customer completes payment on Breeze
7. **Return**: Customer is redirected back to your store
8. **Confirmation**: Order is marked as complete

### API Integration

The plugin integrates with the following Breeze API endpoints:

#### Create Customer (POST /v1/customers)
```json
{
  "referenceId": "user-123",
  "email": "customer@example.com",
  "signupAt": 1234567890000
}
```

#### Create Product (POST /v2/products)
```json
{
  "displayName": "Product Name",
  "currency": "USD",
  "amount": "1000",
  "description": "Product description",
  "image": "https://example.com/image.jpg",
  "type": "REGULAR"
}
```

#### Create Payment Page (POST /v1/payment_pages)
```json
{
  "lineItems": [
    {
      "product": "prod_xxx",
      "quantity": 1
    }
  ],
  "billingEmail": "customer@example.com",
  "clientReferenceId": "order-123",
  "successReturnUrl": "https://yoursite.com/success",
  "failReturnUrl": "https://yoursite.com/failed",
  "customer": {
    "referenceId": "user-123"
  }
}
```

## Webhooks

Configure your Breeze account to send webhooks to:
```
https://yoursite.com/?wc-api=breeze_payment_gateway
```

### Supported Webhook Events

- `payment.succeeded` - Payment completed successfully
- `payment.failed` - Payment failed or was canceled

## Return URLs

The plugin automatically generates return URLs for successful and failed payments:

- **Success**: `https://yoursite.com/?wc-api=breeze_return&order_id={id}&status=success`
- **Failed**: `https://yoursite.com/?wc-api=breeze_return&order_id={id}&status=failed`

## Order Processing

### What Gets Created in Breeze

For each order, the plugin creates:

1. **Customer** (if not already existing)
  - Stored with reference ID: `user-{user_id}` or `guest-{order_id}`
  - Customer ID saved to WordPress / WooCommerce user meta in db for reuse

2. **Products**
  - One product for each cart item
  - One product for shipping (if applicable)
  - One product for taxes (if applicable)

3. **Payment Page**
  - Contains all line items
  - Includes customer information
  - Has return URLs configured

### Order Metadata

The plugin stores the following metadata on orders:

- `_breeze_customer_id`: Breeze customer ID
- `_breeze_payment_page_id`: Breeze payment page ID

## Security

The plugin implements several security best practices:

- Input sanitization using `sanitize_text_field()` and `wp_unslash()`
- Output escaping using `esc_html()`, `esc_attr()`, and `wp_kses_post()`
- Direct file access prevention
- Secure API communication over HTTPS
- Base64 encoded API authentication
- WooCommerce nonce verification (handled by WooCommerce)

## Development

### Prerequisites

Before you begin, make sure you have the following installed:

- **Docker** (v20.10+) — [Install Docker](https://docs.docker.com/get-docker/)
- **Docker Compose** (v2.0+) — included with Docker Desktop; for Linux see [Install the Compose plugin](https://docs.docker.com/compose/install/)
- **make** — pre-installed on macOS/Linux; on Windows use [WSL2](https://learn.microsoft.com/en-us/windows/wsl/install) or [Git Bash](https://gitforwindows.org/)

#### Verifying your installation

```bash
docker --version        # Docker version 20.10+
docker compose version  # Docker Compose version v2.0+
make --version          # GNU Make 3.81+
```

### Docker Environment

The local dev stack is defined in `docker-compose.yml` and spins up three containers:

| Container      | Image                      | Purpose                          |
|----------------|----------------------------|----------------------------------|
| `mywoo_db`     | `mysql:8.0`                | WordPress database               |
| `mywoo_wp`     | `wordpress:php8.2-apache`  | WordPress + the plugin mounted   |
| `mywoo_wpcli`  | `wordpress:cli-php8.2`     | WP-CLI for setup/admin commands  |

The plugin directory is bind-mounted directly into the WordPress container at:
```
./  →  /var/www/html/wp-content/plugins/breeze-payment-gateway
```
Any change you make locally is reflected immediately — no rebuild needed.

### Setup
- Copy the .env.example to .env
  ( This env file doesn't include anything critical only credentials for the docker testing DB and WordPress site.)
- Run `make setup`
- Visit http://localhost:3100

### Dev Commands (Makefile)

Use these for local Docker workflows:

- `make setup` — download the containers and run setup (install WP, WooCommerce, sample data, ...)
- `make uninstall` — delete and clean up the containers (uninstall WP, WooCommerce, sample data, ...)
- `make up` — start containers (run WordPress + MySQL in the background)
- `make down` — stop containers (stop but keeps data for the next up)
- `make logs` — follow container logs (last 100 lines)
- `make shell` — open a shell in the WordPress container
- `make wpcli` — open a shell in the WP‑CLI container
- `make release VERSION=x.y.z` — bump version strings across all files, commit, tag, and push to trigger the GitHub Actions release workflow
### File Structure
```
./
├── breeze-payment-gateway.php          # Main plugin file
├── includes/
│   └── class-wc-breeze-blocks-support.php  # Blocks class for WooCommerce Blocks
│   └── class-wc-breeze-payment-gateway.php  # Gateway class
├── assets/
│   ├── images/
│   │   └── breeze-icon.png              # Gateway icon (shown at checkout)
│   ├── js/
│   │   └── blocks/
│   │       ├── breeze-blocks.js         # JavaScript handler for WooCommerce Checkout Block
│   │       └── breeze-blocks.asset.php  # Block asset manifest (dependencies + version)
│   └── css/
│       └── breeze-blocks.css            # Custom CSS for Test Mode WooCommerce Checkout Block
├── languages/                          # Translation files
│   └── breeze-payment-gateway.pot
├── uninstall.php                      # Cleanup script
└── .wordpress-org/                    # WordPress.org listing assets (banners, icons, screenshots)
```

### Hooks & Filters

**Filters:**
- `woocommerce_breeze_gateway_icon` - Gateway icon URL
- `breeze_api_base_url` - Override the Breeze API base URL (e.g. point to a Test environment)

---

Made with ❤️ by [Breeze](https://breeze.cash)