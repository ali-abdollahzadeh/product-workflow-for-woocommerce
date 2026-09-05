# Testing & Validation Guide

This directory contains test harnesses, automated suites, and manual checklists for validating **Product Workflow for WooCommerce**.

---

## 1. Automated Test Suites

### A. Workflow Behavioral Test Suite
Runs in-memory simulation of WordPress, WooCommerce, and MySQL transaction layers to verify permissions, stage transitions, product readiness, concurrency locks, and audit rollbacks.
```bash
php tests/workflow-test.php
```
- **Coverage**: 63 checks verifying capability checks, role assignments, mutual exclusions, and native publish bypass protections.

### B. Telegram & Settings Test Suite
Validates the Telegram client, webhook endpoints, bot command parser (`/start`, `/tasks`, `/photo_{id}`, `/done_photo_{id}`), multi-user chat notifications, photo uploads, and settings persistence.
```bash
php tests/telegram-settings-test.php
```
- **Coverage**: 96 unit tests covering bot workflows, sessions, and notification delivery.

### C. Translation Consistency Checker
Validates that all language dictionaries (`languages/*.json`) contain identical translation keys and valid string format placeholders.
```bash
python tests/check_translations.py
```

---

## 2. Real WordPress Integration Suite

For end-to-end integration testing in a real WordPress environment:
1. Set up a disposable local WordPress test site with database name `pwf_test`.
2. Activate WooCommerce and PHP GD extension.
3. Run the integration test against your `wp-load.php`:
   ```bash
   php tests/integration.php /path/to/wordpress/wp-load.php
   ```
> [!CAUTION]
> Never run `integration.php` against a production database. It creates test users, terms, and products, and intentionally triggers simulated query failures to verify transaction rollback behavior.

---

## 3. Manual Acceptance Checklist

On your staging environment, run through the following verification steps:

1. **Activation**: Activate the plugin on a clean WordPress install. Confirm creation of `wp_pwf_workflows`, `wp_pwf_assignments`, and `wp_pwf_history`.
2. **Role Verification**: Confirm that the 5 custom roles (`Factory`, `Photographer`, `Content Manager`, `Reviewer`, `Social Media`) appear in **Users** and have scoped capabilities.
3. **Draft Creation**: Log in as a Factory user and submit a new product draft with reference photos. Verify initial audit entry is created.
4. **Task Assignment**: Log in as Manager, assign users and due dates to each stage. Verify that only assigned workers see their tasks under **My Tasks**.
5. **Worker Scoping**: Ensure workers cannot edit other workers' stages or bypass review via the native WooCommerce product editor.
6. **Rejection with Reason**: Reject a product during review; confirm that a reason is required and that the assigned task is cleanly reopened.
7. **Telegram Delivery**: Send a test alert from **Product Workflow → Settings** and confirm delivery to your configured Telegram Chat ID.
