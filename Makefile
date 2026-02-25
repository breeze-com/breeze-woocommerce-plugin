.PHONY: up down reset logs shell wpcli setup uninstall release

up:
	docker compose up

down:
	docker compose down --remove-orphans

logs:
	docker compose logs -f --tail=100

shell:
	docker exec -it mywoo_wp bash

wpcli:
	docker compose run --rm wpcli bash

setup:
	docker compose up -d
	docker compose run --rm -T --user root wpcli /scripts/setup.sh

uninstall:
	-docker compose kill
	-docker compose rm -f
	docker compose down -v --rmi all --remove-orphans

# Cross-platform note: `-i ''` is macOS/BSD sed syntax.
# On Linux (GNU sed), use `-i` without the empty string argument.
release:
ifndef VERSION
	$(error VERSION is required. Usage: make release VERSION=1.2.3)
endif
	@echo "Bumping version to $(VERSION)..."
	sed -i '' 's/^\( \* Version: *\).*/\1$(VERSION)/' breeze-payment-gateway.php
	sed -i '' "s/define( 'BREEZE_PAYMENT_GATEWAY_VERSION', '[^']*' )/define( 'BREEZE_PAYMENT_GATEWAY_VERSION', '$(VERSION)' )/" breeze-payment-gateway.php
	sed -i '' 's/^Stable tag: .*/Stable tag: $(VERSION)/' readme.txt
	sed -i '' "s/'version' => '[^']*'/'version' => '$(VERSION)'/" assets/js/blocks/breeze-blocks.asset.php
	sed -i '' 's|/badge/version-[^-]*-|/badge/version-$(VERSION)-|' README.md
	git add breeze-payment-gateway.php readme.txt assets/js/blocks/breeze-blocks.asset.php README.md
	git commit -m "Bump version to $(VERSION)"
	git tag v$(VERSION)
	git push origin main v$(VERSION)
	@echo "Released v$(VERSION) — GitHub Actions will build the zip."
