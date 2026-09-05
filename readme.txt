=== Product Workflow for WooCommerce ===
Contributors: ali-abdollahzadeh
Donate link: https://aliabdollahzadeh.dev/
Tags: woocommerce, workflow, product management, telegram, pipeline
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WooCommerce into an end-to-end product production pipeline with role assignments, stage gates, audit trails, and Telegram notifications.

== Description ==

In fast-growing e-commerce operations, preparing a product for sale is rarely a one-person job. From receiving a factory sample to the live storefront, a product passes through multiple specialized departments: warehouse staff draft initial requests, photographers capture imagery, copywriters craft descriptions, managers review accuracy, and marketing teams prepare social campaigns.

Without a dedicated workflow, teams rely on scattered spreadsheets, lost chat messages, and risk accidental publication of incomplete products.

**Product Workflow for WooCommerce** organizes this entire lifecycle into a controlled, role-based production pipeline with built-in audit logging, concurrency locking, and two-way Telegram bot integration.

= Features =

* **9-Stage Production Pipeline**: Structured state progression from Factory draft to Social completion.
* **Role-Based Workspaces**: Five specialized roles (Factory, Photographer, Content Manager, Reviewer, Social Media) plus Shop Manager oversight.
* **Two-Way Telegram Bot**: Instant task alerts, team channel broadcasts, bot task listings (`/tasks`), and direct photo uploads into the WordPress Media Library from mobile devices.
* **Enterprise Concurrency & Integrity**: Per-product MySQL named locks (`GET_LOCK`) and atomic InnoDB database transactions prevent race conditions.
* **Bypass Prevention**: Enforces that incomplete drafts cannot bypass review or be published directly through classic WooCommerce screens.
* **Immutable Audit Trail**: Append-only event history records every status transition, assignment, timestamp, and review comment.
* **Multilingual & RTL Ready**: Complete built-in support for English, Persian (فارسی), and Italian, with automatic RTL layout switching.
* **REST API**: Clean endpoints under `/wp-json/product-workflow/v1` for external integrations.

== External Services ==

This plugin can optionally connect to the **Telegram Bot API** (`https://api.telegram.org`) to send automated task notifications, broadcast workflow updates to your team channel, and receive photo uploads sent by photographers directly into the WordPress Media Library.

* **Service Provider**: Telegram FZ-LLC
* **Service URL**: https://api.telegram.org
* **Telegram Terms of Service**: https://telegram.org/tos
* **Telegram Privacy Policy**: https://telegram.org/privacy

**When and what data is transmitted?**
Connecting to Telegram is completely optional. If an administrator configures a Bot Token in the plugin settings:
1. Product workflow event summaries (product title, stage name, task URL, and recipient Telegram Chat ID) are sent via HTTPS POST to the Telegram Bot API when workflow events occur.
2. Inbound photos sent by authorized team members to the Telegram bot are downloaded over HTTPS from `api.telegram.org` into your WordPress uploads directory.
No customer data, financial details, or order information is ever transmitted to Telegram.

== Installation ==

= Automatic Installation =
1. Log in to your WordPress admin dashboard.
2. Go to **Plugins → Add New Plugin**.
3. Search for **Product Workflow for WooCommerce**.
4. Click **Install Now**, then click **Activate**.

= Manual Installation =
1. Download the plugin ZIP archive.
2. Go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Activate the plugin.

= Initial Configuration =
1. Ensure **WooCommerce** is installed and active.
2. Navigate to **Users** or **Product Workflow → Settings → Team & Roles** to assign users to their workflow roles (`Factory`, `Photographer`, `Content Manager`, `Product Reviewer`, `Social Media`).
3. (Optional) In **Product Workflow → Settings**, enter your Telegram Bot Token and default channel Chat ID to enable notifications.

== Frequently Asked Questions ==

= Does this plugin modify existing WooCommerce products? =
No. Existing products continue to work normally. Only products explicitly created through the workflow or enrolled into it are tracked by the state machine.

= Can workers access general WooCommerce settings or orders? =
No. Workers (Photographers, Content Managers, Factory, etc.) are restricted to their scoped task screens. They cannot access store orders, customer data, or store settings.

= Is Telegram required for the plugin to work? =
No. Telegram integration is entirely optional. If not configured, all task assignment, reviews, and workflows operate seamlessly within the WordPress admin dashboard.

= What happens if someone modifies an approved product natively? =
If a user edits an approved product directly through WooCommerce before publication, the plugin automatically revokes approval and returns the product to the Review stage to ensure content integrity.

= Can I customize the required fields for publication? =
Yes. You can use the `pwf_required_product_fields_missing` filter to add custom validation rules or custom post meta requirements.

== Screenshots ==

1. Product Workflow dashboard displaying active products, current stages, and assignees.
2. Scoped task screen for photographers and content creators.
3. Reviewer screen with approval and mandatory rejection feedback controls.
4. Settings page for Telegram Bot API configuration and team role mappings.

== Changelog ==

= 1.2.0 =
* Added two-way Telegram Bot integration (`/start`, `/tasks`, `/photo_{id}`, `/done_photo_{id}`).
* Added direct Telegram photo ingestion into WordPress Media Library.
* Added multilingual support for English, Persian, and Italian with native RTL styles.
* Added WooCommerce HPOS (High-Performance Order Storage) compatibility.
* Added standard uninstall handler and WordPress.org compatibility.

= 1.1.0 =
* Added REST API endpoints under `/wp-json/product-workflow/v1`.
* Added scoped "My Tasks" screen for assigned team members.
* Added MySQL named locks for concurrent transition serialization.

= 1.0.0 =
* Initial release with 9-stage auditable production pipeline.
* Added custom workflow roles and capabilities.
* Added append-only audit trail table.
* Added native publish bypass prevention.

== Upgrade Notice ==

= 1.2.0 =
Upgrade to 1.2.0 for Telegram Bot integration, multilingual support, and enhanced role management.
