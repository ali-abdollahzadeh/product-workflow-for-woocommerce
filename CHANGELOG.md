# Changelog

All notable changes to **Product Workflow for WooCommerce** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.0] - 2026-09-05

### Added
- **Full Telegram Bot Integration**:
  - Direct two-way Telegram Bot interaction (`/start`, `/tasks`, `/photo_{id}`, `/done_photo_{id}`).
  - Photo upload ingestion directly from Telegram mobile chats into WordPress Media Library.
  - Interactive inline and custom reply keyboards with localized buttons.
  - Real-time broadcasts to a default channel and personal task assignment notifications to individual team members.
  - Webhook registration and diagnostic test message sender in Settings.
- **Multilingual & RTL Support**:
  - Full translations for English (`en_US`), Persian (`fa_IR`), and Italian (`it_IT`).
  - Seamless Right-to-Left (RTL) styling for Persian language environments.
  - Centralized translation dictionary loader (`class-i18n.php` and `languages/*.json`).
- **Enhanced Settings Page**:
  - Dedicated dashboard under **Product Workflow → Settings**.
  - Telegram Bot API configuration and webhook management.
  - User role assignment panel mapping team members to workflow roles with individual Telegram Chat IDs.
  - Notification event toggles (`task_assigned`, `status_changed`, `created`, `content_saved`, `images_saved`).
- **Comprehensive Unit Testing Suite**:
  - 96 automated tests for Telegram bot interactions, keyboards, webhook payloads, and role assignments (`tests/telegram-settings-test.php`).
  - Translation key validation utility (`tests/check_translations.py`).

### Fixed
- Fixed orphan workflow cleanup when tracked products are permanently deleted.
- Excluded trashed products from active worker task queues.
- Synchronized completion checkboxes with Telegram bot state.

---

## [1.1.0] - 2026-08-20

### Added
- **REST API Endpoints**:
  - Authenticated endpoints under `/wp-json/product-workflow/v1` for drafts, assignments, status transitions, content, and image management.
  - Object-level authorization callbacks and nonce validation.
- **Dedicated Task Views**:
  - "My Tasks" screen scoped to the logged-in user's assigned queue.
  - Protected worker editors preventing unauthorized access to general WooCommerce settings.

### Changed
- Improved error messaging on invalid status transition attempts.
- Enforced atomic InnoDB transactions with MySQL named locks per product.

---

## [1.0.0] - 2026-08-01

### Added
- Initial release of Product Workflow for WooCommerce.
- 9-stage auditable product pipeline:
  - New Product → Waiting Photography → Photography Completed → Waiting Content → Content Completed → Waiting Review → Approved → Published → Ready for Social → Completed.
- Role definitions: Factory, Photographer, Content Manager, Product Reviewer, Social Media.
- Append-only audit history table (`wp_pwf_history`) recording all transitions, users, timestamps, and review notes.
- Mandatory rejection reason logging when returning products for revisions.
- Native WooCommerce publish bypass prevention (drafts enrolled in workflow must be approved before publication).
