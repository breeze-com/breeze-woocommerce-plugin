# Contributing to Breeze Payment Gateway

Thank you for your interest in contributing! This document explains how to report bugs, submit pull requests, and follow the coding standards for this project.

---

## Reporting Bugs

1. **Search first** — check [existing issues](https://github.com/breeze/breeze-woocommerce-plugin/issues) to avoid duplicates.
2. **Open a new issue** and include:
   - WordPress version, WooCommerce version, PHP version
   - Plugin version
   - Steps to reproduce
   - Expected vs actual behaviour
   - Any relevant log output (WooCommerce > Status > Logs, filter by `breeze`)

> **Security vulnerabilities** — please do **not** open a public issue. See [SECURITY.md](SECURITY.md) instead.

---

## Submitting Pull Requests

1. **Fork** the repository and create a feature branch from `main`:
   ```bash
   git checkout -b fix/your-description
   ```
2. **Keep changes focused** — one logical change per PR.
3. **Test locally** using the Docker dev environment (see README.md → Development).
4. **Follow coding standards** (see below).
5. **Open the PR** against `main` with a clear title and description of what changed and why.

---

## Coding Standards

This plugin follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

Key points:

- **PHP** — tabs for indentation, Yoda conditions, spaces inside parentheses for control structures.
- **Naming** — `snake_case` for functions and variables; class names in `PascalCase`.
- **Sanitization & escaping** — all user input must be sanitized (`sanitize_text_field()`, `wp_unslash()`, etc.) and all output must be escaped (`esc_html()`, `esc_attr()`, `wp_kses_post()`).
- **Prefixes** — all functions, hooks, and globals must be prefixed with `breeze_` or `BREEZE_` to avoid conflicts.
- **Internationalization** — wrap all user-facing strings with `__()` or `_e()` using the `breeze-payment-gateway` text domain.
- **No direct DB queries** — use WooCommerce and WordPress APIs rather than raw SQL.

---

## Development Setup

See [README.md](README.md#development) for full instructions on spinning up the local Docker environment with `make setup`.

---

## License

By contributing, you agree that your contributions will be licensed under the [GPL v2 or later](LICENSE).
