# Contributing to Product Workflow for WooCommerce

Thank you for your interest in contributing to Product Workflow for WooCommerce! We welcome contributions from developers of all backgrounds.

---

## Code of Conduct

Please be respectful, helpful, and collaborative in all communications.

---

## Development Setup

To get your local development environment up and running:

1. **Requirements**:
   - PHP 8.1 or later (PHP 8.2+ recommended)
   - WordPress 6.5 or later
   - WooCommerce 8.0 or later
   - MySQL 5.7+ / MariaDB 10.4+ with InnoDB support

2. **Clone the repository**:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/ali-abdollahzadeh/product-workflow.git
   ```

3. **Activate the plugin**:
   - Go to your WordPress admin dashboard → **Plugins** → Activate **Product Workflow for WooCommerce**.

---

## Running the Test Suites

Before opening a pull request, make sure all test suites pass:

1. **Workflow Behavioral Suite**:
   ```bash
   php tests/workflow-test.php
   ```
   Runs 63 isolated behavioral checks verifying capabilities, concurrency locks, transitions, audit rollbacks, and role assignments.

2. **Telegram & Settings Unit Suite**:
   ```bash
   php tests/telegram-settings-test.php
   ```
   Runs 96 unit tests verifying Telegram bot commands, webhooks, multi-user chat notifications, sessions, and settings persistence.

3. **Translation Verifications**:
   ```bash
   python tests/check_translations.py
   ```
   Verifies message placeholder consistency across English (`en_US`), Persian (`fa_IR`), and Italian (`it_IT`).

---

## Coding Standards

- Follow [WordPress PHP Coding Standards (WPCS)](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- **Security First**:
  - Always verify nonces on form submissions and REST endpoints (`check_admin_referer` or `X-WP-Nonce`).
  - Always enforce capability checks on the server (`current_user_can` and `PWF_Permission_Manager`).
  - Sanitize inputs (`sanitize_text_field`, `absint`, etc.) and escape all output (`esc_html`, `esc_attr`, `esc_url`).
  - All workflow transitions and audit logging must be atomic and protected with per-product mutex locks.
- **Internationalization (i18n)**:
  - Wrap any user-facing strings with `pwf_t()` and ensure translation keys exist in `languages/*.json`.

---

## Submitting Pull Requests

1. Fork the repository and create your branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
2. Commit your changes with clear, descriptive commit messages.
3. Add corresponding tests for any new workflow logic or API endpoints.
4. Run all test suites to confirm nothing is broken.
5. Push to your fork and submit a Pull Request to `main`.
6. Include a concise summary of the problem, the solution, and any testing notes.
