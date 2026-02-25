#!/usr/bin/env bash
set -euo pipefail

WP_PATH="/var/www/html"

wait_for_db() {
  echo "Waiting for database..."
  until mysqladmin ping -h"db" -u"${WORDPRESS_DB_USER}" -p"${WORDPRESS_DB_PASSWORD}" --skip-ssl --silent; do
    sleep 2
  done
}

wait_for_db

cd "$WP_PATH"

mkdir -p wp-content/uploads wp-content/upgrade wp-content/uploads/wc-logs
# Only adjust ownership on writable dirs; plugin dir is a bind mount and can't be chowned.
chown -R www-data:www-data wp-content/uploads wp-content/upgrade || true
chmod -R 775 wp-content/uploads wp-content/upgrade

if ! wp core is-installed --allow-root > /dev/null 2>&1; then
  wp core install \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email \
    --allow-root
fi

wp plugin install woocommerce --activate --allow-root
wp plugin activate breeze-payment-gateway --allow-root

# Inject BREEZE_API_BASE_URL into wp-config.php if set in the environment
if [ -n "${BREEZE_API_BASE_URL:-}" ]; then
  wp config set BREEZE_API_BASE_URL "$BREEZE_API_BASE_URL" --allow-root || \
  wp config set BREEZE_API_BASE_URL "$BREEZE_API_BASE_URL" --allow-root --force
fi

# Basic WooCommerce settings
wp option update woocommerce_coming_soon 'no' --allow-root
wp option update woocommerce_store_address "123 Test St" --allow-root
wp option update woocommerce_store_city "Testville" --allow-root
wp option update woocommerce_store_postcode "12345" --allow-root
wp option update woocommerce_default_country "US:CA" --allow-root
wp option update woocommerce_currency "USD" --allow-root
wp option update woocommerce_calc_taxes "no" --allow-root
wp option update woocommerce_allowed_countries "all" --allow-root

# Create WooCommerce pages if needed
wp wc tool run install_pages --user="$ADMIN_USER" --allow-root || true

# Permalinks for nicer URLs
wp rewrite structure "/%postname%/" --allow-root
wp rewrite flush --allow-root

# Sample tech products with images
product_names=(
  "Wireless Headphones"
  "Smartphone Dock"
  "True Wireless Earbuds"
  "Mechanical Keyboard"
  "Ergonomic Mouse"
  "Smartwatch"
  "Laptop Stand"
  "Tech Essentials Bundle"
)
product_prices=("149.00" "29.00" "99.00" "129.00" "59.00" "199.00" "49.00" "249.00")
product_descs=(
  "Noise-cancelling headphones for focused work."
  "A minimal dock for your daily driver phone."
  "Compact earbuds with a charging case."
  "Tactile switches and clean backlighting."
  "Precision tracking with a comfortable grip."
  "Track fitness, notifications, and calls."
  "Elevates your laptop for better posture."
  "A curated set of must-have desk tech."
)
product_images=(
  "/scripts/sample-products/tech-headphones.jpg"
  "/scripts/sample-products/tech-apple-desk.jpg"
  "/scripts/sample-products/tech-earbuds.jpg"
  "/scripts/sample-products/tech-keyboard.jpg"
  "/scripts/sample-products/tech-mouse.jpg"
  "/scripts/sample-products/tech-smartwatch.jpg"
  "/scripts/sample-products/tech-laptop-desk.jpg"
  "/scripts/sample-products/tech-gadgets.jpg"
)

for i in "${!product_names[@]}"; do
  img_id=""
  if img_id=$(wp media import "${product_images[$i]}" --porcelain --allow-root 2>/dev/null); then
    wp wc product create \
      --name="${product_names[$i]}" \
      --type="simple" \
      --regular_price="${product_prices[$i]}" \
      --description="${product_descs[$i]}" \
      --images="[{\"id\":${img_id}}]" \
      --user="$ADMIN_USER" \
      --allow-root
  else
    wp wc product create \
      --name="${product_names[$i]}" \
      --type="simple" \
      --regular_price="${product_prices[$i]}" \
      --description="${product_descs[$i]}" \
      --user="$ADMIN_USER" \
      --allow-root
  fi
done

echo "Bootstrap complete."
