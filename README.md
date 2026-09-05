# Product Workflow for WooCommerce

[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-159%20Passing-success.svg)](tests/)

**Product Workflow for WooCommerce** turns your WooCommerce store into an end-to-end product production pipeline.

In real-world e-commerce businesses, getting a product from a sample to live storefront requires collaboration across departments: factory teams register new arrivals, photographers capture imagery, copywriters prepare descriptions, reviewers QA the details, and social media teams prep promotional assets.

Without a structured system, this process quickly descends into lost spreadsheet rows, uncoordinated chat messages, and premature store publications. **Product Workflow** solves this by establishing strict role-based stages, task ownership, atomic state management, append-only audit trails, and two-way Telegram bot notifications.

---

## The Workflow Pipeline

Each product moves through a controlled 9-stage state machine:

```
  [ New Product ] ──(Manager Queues)──> [ Waiting for Photography ]
                                                    │
                                            (Photographer Uploads)
                                                    ▼
  [ Waiting for Content ] <──(Manager Queues)── [ Photography Completed ]
            │
    (Content Manager Fills)
            ▼
  [ Content Completed ] ──(Manager Submits)──> [ Waiting for Review ]
                                                    │
                      ┌─────────────────────────────┴─────────────────────────────┐
                      ▼ (Reject with reason)                                      ▼ (Approve)
          [ Returns to Photo/Content ]                                      [ Approved ]
                                                                                  │
                                                                          (Publish to Store)
                                                                                  ▼
  [ Workflow Completed ] <──(Social Team Completes)── [ Ready for Social ] <── [ Published ]
```

---

## Key Features

### 👥 Role-Based Access Control
- **Factory**: Creates initial draft product requests with basic specs and reference photos.
- **Photographer**: Receives assigned photo tasks, uploads studio shots directly, and marks photography complete.
- **Content Manager**: Prepares localized titles, descriptions, SKU, pricing, taxonomies, and SEO metadata.
- **Reviewer**: Inspects completed products; approves or rejects back to earlier stages with mandatory explanatory notes.
- **Publisher / Manager**: Oversees queues, assigns team members and due dates, and performs storefront publication.
- **Social Media**: Accesses approved assets post-publication to prepare marketing campaigns.

Workers only see and edit their assigned tasks—they never get unrestricted access to your broader WooCommerce settings or customer data.

### 📱 Two-Way Telegram Bot Integration
- **Direct Task Alerts**: Team members receive private Telegram alerts the moment a task is assigned to them.
- **Channel Broadcasts**: Automatic status updates posted to your company's team channel.
- **Mobile Photo Ingestion**: Photographers can send photos directly to the Telegram bot—they are automatically downloaded and attached to the product in the WordPress Media Library.
- **Interactive Commands**: Workers can list their open tasks (`/tasks`), trigger photo uploads (`/photo_{id}`), and complete tasks (`/done_photo_{id}`) using inline Telegram keyboards.

### 🔒 Enterprise-Grade Data Integrity
- **No Native Publish Bypasses**: Prevents accidental or unauthorized publication of incomplete drafts through the standard WordPress editor.
- **Automatic Invalidation**: If an approved product is modified natively before launch, its approval status is automatically revoked and sent back for review.
- **Atomic Transactions & Named Locks**: Transitions use MySQL named locks (`GET_LOCK`) and atomic InnoDB transactions to prevent race conditions during concurrent updates.
- **Immutable Audit Trail**: Every status change, assignment, reviewer comment, and timestamp is permanently recorded in a dedicated audit table.

### 🌍 Multilingual & RTL Ready
- Native support for **English (`en_US`)**, **Persian (`fa_IR`)**, and **Italian (`it_IT`)**.
- Automatic Right-to-Left (RTL) layout styling for RTL languages.
- Language switcher available in plugin settings.

---

## Installation

### Requirements
- WordPress 6.5 or higher
- WooCommerce 8.0 or higher
- PHP 8.1 or higher (PHP 8.2+ recommended)
- MySQL 5.7+ or MariaDB 10.4+ (InnoDB engine required)

### Manual Installation
1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/ali-abdollahzadeh/product-workflow.git
   ```
2. Navigate to **Plugins → Installed Plugins** in your WordPress admin dashboard.
3. Locate **Product Workflow for WooCommerce** and click **Activate**.
4. Database tables and default role capabilities will be created automatically.

---

## Quick Setup Guide

### 1. Assign Roles to Team Members
Go to **Users → All Users** (or **Product Workflow → Settings → Team & Roles**):
Assign relevant staff members to their respective workflow roles:
- `Factory`
- `Photographer`
- `Content Manager`
- `Product Reviewer`
- `Social Media`

### 2. Configure Telegram Notifications (Optional)
1. Message [@BotFather](https://t.me/BotFather) on Telegram to create a bot and copy the API token.
2. In your WordPress admin, go to **Product Workflow → Settings**.
3. Paste your **Bot Token** and default **Channel/Chat ID**.
4. Register your webhook URL with one click from the settings page.
5. In the **Team & Roles** table, enter each user's Telegram Chat ID to enable personal notifications.

### 3. Enroll or Create Products
- **New Products**: Click **Product Workflow → New Product** to log a fresh arrival.
- **Existing Drafts**: Open the **Product Workflow** dashboard and enroll existing WooCommerce drafts by ID.
- **Product Details View**: Assign team members, set due dates, and monitor real-time audit history.

---

## REST API Reference

Product Workflow exposes secure REST endpoints under the namespace `/wp-json/product-workflow/v1`. All modifying endpoints require authentication (WordPress Cookie Nonce or Application Passwords).

| Method | Endpoint | Description |
| --- | --- | --- |
| `POST` | `/products` | Create a new workflow draft with initial specs |
| `GET` | `/products/{id}` | Retrieve scoped workflow state, assignees, and audit history |
| `POST` | `/products/{id}/assignment` | Assign a user and due date to a workflow stage |
| `POST` | `/products/{id}/transition` | Advance product state (`to`, `expected`, `note`) |
| `POST` | `/products/{id}/content` | Update allowlisted content fields (title, desc, price, SKU) |
| `POST` | `/products/{id}/images` | Update main featured image and gallery attachments |
| `POST` | `/telegram-webhook` | Incoming Telegram Bot webhook receiver |

---

## Developer Extensibility

Product Workflow is designed to integrate cleanly with your custom business rules and extensions via WordPress hooks:

### Action Hooks
- `pwf_workflow_event($event_name, $product_id, $event_data)`: Fires after a transaction is safely committed to the database. Handlers won't break workflow saves even if an external service fails.
- `pwf_product_enrolled($product_id, $user_id)`: Fires when an existing draft is enrolled into the workflow.

### Filter Hooks
- `pwf_required_product_fields_missing`: Extend or customize required fields before a product can be approved or published.
- `pwf_states`: Register custom workflow states.
- `pwf_transitions`: Customize allowed state machine transitions.

---

## Testing & Quality Assurance

The codebase includes an extensive automated test suite covering both business logic and Telegram integration:

```bash
# Run isolated workflow & state machine tests (63 tests)
php tests/workflow-test.php

# Run Telegram bot, keyboards & settings tests (96 tests)
php tests/telegram-settings-test.php

# Validate translation keys and placeholders
python tests/check_translations.py
```

---

## Architecture & Design

For in-depth details on database schema, state transitions, and component diagrams, see [DESIGN.md](DESIGN.md).

---

## Contributing

Contributions, bug reports, and suggestions are welcome! Please check out [CONTRIBUTING.md](CONTRIBUTING.md) for development guidelines.

---

## Security

If you discover any security vulnerabilities, please review [SECURITY.md](SECURITY.md) for responsible disclosure steps.

---

## License

This project is licensed under the **GNU General Public License v2.0 or later** - see the [LICENSE](LICENSE) file for details.

---

**Crafted by [AliABZ](https://aliabdollahzadeh.dev/)**
