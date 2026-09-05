# Product Workflow Architecture & Technical Design

This document details the architectural foundation, data models, state machine, and integration layers of **Product Workflow for WooCommerce**.

---

## Architecture Overview

Product Workflow is built as a modular, decoupled extension for WooCommerce. It isolates content preparation and media workflows while preserving standard WooCommerce data integrity.

```mermaid
flowchart TD
    subgraph UI["User Interfaces"]
        AdminForms["WordPress Admin Dashboard & Forms"]
        MyTasks["Scoped 'My Tasks' View"]
        NativePanel["WooCommerce Product Panel"]
        TelegramUI["Telegram Bot Chat & Keyboards"]
    end

    subgraph API["API & Ingestion"]
        REST["Authenticated REST Endpoints (/wp-json/product-workflow/v1)"]
        Webhook["Telegram Webhook Receiver"]
    end

    subgraph Core["Workflow Engine"]
        WorkflowManager["PWF_Workflow_Manager (State Machine)"]
        AssignmentManager["PWF_Assignment_Manager (Stage Assignees)"]
        PermissionManager["PWF_Permission_Manager (Role Capabilities)"]
        HistoryManager["PWF_History_Manager (Immutable Audit Log)"]
        LockManager["Concurrency Mutex (Named Locks)"]
    end

    subgraph Integrations["Integration & Notification"]
        TelegramClient["Telegram Client & Notifier"]
        TelegramMedia["Media Downloader & Attachment Creator"]
        I18n["Multilingual & RTL Engine"]
    end

    subgraph Storage["Database (MySQL / MariaDB InnoDB)"]
        WpProducts["wp_posts & wp_postmeta (WC Products)"]
        TblWorkflow["wp_pwf_workflows"]
        TblAssignment["wp_pwf_assignments"]
        TblHistory["wp_pwf_history"]
    end

    AdminForms --> Core
    MyTasks --> Core
    NativePanel --> Core
    REST --> Core
    Webhook --> TelegramClient
    TelegramClient --> TelegramMedia
    TelegramMedia --> WpProducts
    TelegramUI <--> TelegramClient
    TelegramClient --> Core

    Core --> LockManager
    Core --> PermissionManager
    Core --> WorkflowManager
    Core --> AssignmentManager
    Core --> HistoryManager
    Core --> Integrations

    WorkflowManager --> Storage
    AssignmentManager --> Storage
    HistoryManager --> Storage
```

---

## State Machine & Lifecycle

The product lifecycle consists of an explicit, auditable 9-stage state machine:

```mermaid
stateDiagram-v2
    [*] --> NewProduct
    NewProduct --> WaitingPhotography: Manager assigns & queues
    WaitingPhotography --> PhotographyCompleted: Assigned Photographer uploads & completes
    PhotographyCompleted --> WaitingContent: Manager queues
    WaitingContent --> ContentCompleted: Assigned Content Manager fills data & completes
    ContentCompleted --> WaitingReview: Manager submits for review
    
    WaitingReview --> WaitingPhotography: Reviewer rejects (mandatory reason)
    WaitingReview --> WaitingContent: Reviewer rejects (mandatory reason)
    WaitingReview --> Approved: Reviewer approves
    
    Approved --> WaitingReview: Native product edit resets approval
    Approved --> Published: Publisher publishes to storefront
    
    Published --> ReadyForSocial: Publisher hands off assets
    ReadyForSocial --> Completed: Social Media team completes workflow
    
    Published --> WaitingReview: Native unpublish / trash withdrawal
    ReadyForSocial --> WaitingReview: Native unpublish / trash withdrawal
    Completed --> WaitingReview: Native unpublish / trash withdrawal
```

### Transition Guard Rules

1. **Permission Check**: Every transition requires the actor to have the corresponding capability (`upload_product_images`, `edit_product_content`, `review_product`, `publish_product`, `complete_social_task`, or `manage_workflow`).
2. **Assignment Ownership**: Workers can only act on products currently assigned to them.
3. **Product Readiness**: Content completion, review approval, and publication require mandatory product fields:
   - Product title / name
   - SKU
   - Description
   - Regular price
   - Main featured image
   - Category
4. **Mandatory Rejection Notes**: When a reviewer returns a product to photography or content, a detailed explanatory reason is required and recorded in the audit history.
5. **Concurrency Serialization**: Transitions use MySQL named locks (`GET_LOCK`) and atomic InnoDB database transactions to guarantee that simultaneous requests never corrupt state.

---

## Data Model & Schema

All workflow tables use the MySQL **InnoDB** storage engine with UTF-8mb4 collations.

```mermaid
erDiagram
    WC_PRODUCT ||--o| PWF_WORKFLOWS : "tracked by"
    PWF_WORKFLOWS ||--o{ PWF_ASSIGNMENTS : "stage assignments"
    PWF_WORKFLOWS ||--|{ PWF_HISTORY : "audit history"
    WP_USERS ||--o{ PWF_ASSIGNMENTS : "assigned worker"
    WP_USERS ||--o{ PWF_HISTORY : "action author"

    PWF_WORKFLOWS {
        bigint product_id PK "WC Product Post ID"
        varchar current_status "Current state"
        bigint created_by "User ID"
        datetime created_at "UTC Timestamp"
        datetime updated_at "UTC Timestamp"
    }

    PWF_ASSIGNMENTS {
        bigint assignment_id PK "Auto increment"
        bigint product_id FK "WC Product ID"
        varchar stage "photography | content | review | social"
        bigint user_id FK "Assigned WP User ID"
        datetime assigned_at "UTC Timestamp"
        date due_date "Optional deadline"
        datetime completed_at "Completion timestamp"
    }

    PWF_HISTORY {
        bigint history_id PK "Auto increment"
        bigint product_id FK "WC Product ID"
        varchar event "Event name"
        varchar previous_status "Old state"
        varchar new_status "New state"
        bigint changed_by FK "WP User ID"
        datetime changed_at "UTC Timestamp"
        text note "Review comments / context"
    }
```

---

## Telegram Integration Architecture

The Telegram integration (`integrations/class-telegram.php` and `integrations/telegram/services/`) provides real-time notifications and bot interactions:

- **Client & Webhook**: Communicates with the Telegram Bot API (`sendMessage`, `sendPhoto`, `getFile`). Webhooks receive updates routed securely to `/wp-json/product-workflow/v1/telegram-webhook`.
- **Notifier**: Hooks into `pwf_workflow_event` after successful database commits. Broadcasts public announcements to the configured default channel, while delivering targeted alerts to the assigned user's personal `telegram_chat_id`.
- **Interactive Bot Commands**:
  - `/start`: Onboards users, displays personalized role menu.
  - `/tasks`: Shows active tasks assigned to the Telegram user with direct command buttons.
  - `/photo_{id}`: Initiates photo upload mode; downloads photos sent to the bot directly into the WordPress Media Library attached to the product.
  - `/done_photo_{id}` or completion buttons: Advances product status to `waiting_content` and notifies the next queue.
- **Fail-Safe Isolation**: Any network or API failure communicating with Telegram is safely logged and caught; Telegram failures **never** abort or roll back saved WordPress workflow changes.

---

## Role-Based Access Control (RBAC)

| Role | Core Capabilities |
| --- | --- |
| **Factory (`pwf_factory`)** | `create_product_request`, `view_assigned_products` |
| **Photographer (`pwf_photographer`)** | `upload_product_images`, `complete_photography_task`, `view_assigned_products` |
| **Content Manager (`pwf_content_manager`)** | `edit_product_content`, `complete_content_task`, `view_assigned_products` |
| **Reviewer (`pwf_reviewer`)** | `review_product`, `view_assigned_products` |
| **Social Media (`pwf_social`)** | `complete_social_task`, `view_assigned_products` |
| **Shop Manager / Administrator** | All above, plus `assign_tasks`, `manage_workflow`, `publish_product` |

---

## Internationalization & Localization

- Translation dictionary managed in `languages/{locale}.json`.
- Supported locales: English (`en_US`), Persian (`fa_IR`), Italian (`it_IT`).
- RTL support dynamically applied when viewing in Persian or other RTL languages.
