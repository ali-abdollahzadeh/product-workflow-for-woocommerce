# SOFTWARE DEFINITION

Team Task & Product Workflow Management System for WooCommerce

*Version 2.1 — Production-Ready Operational & Technical Specification*

## DOCUMENT PURPOSE & EXECUTIVE SUMMARY

This document defines the comprehensive operational logic, data architecture, and team workflows for the central Task & Product Workflow System. It resolves all ambiguities from earlier drafts, establishing clear dual-cycle lifecycles (Requests vs. Tasks), explicit permission boundaries, quality control standards, Telegram bot alerts, and deep WooCommerce synchronization.

## 1. System Definition & Architectural Overview

The proposed system is an enterprise operational work-management and coordination hub built on top of WordPress and WooCommerce. Unlike rigid publishing pipelines that force every action into a single linear sequence, this system operates as a central dispatcher that receives requests and operational updates from the CEO, Factory Secretary, and Sales Manager, and transforms them into organized, trackable tasks for the Photographer, Programmer, and Digital Admin.

Management & Planning functions as the central operational command. It acts as the intake gatekeeper, evaluates incoming requests, defines work scope, assigns accountable users, enforces deadlines, reviews deliverables against strict quality criteria, and provides executive progress reports to the CEO.

Crucially, the architecture adopts a dual-entity model: High-level 'Requests' capture organizational needs, while granular 'Tasks' drive team execution. WooCommerce remains the authoritative product database, but the task engine also independently manages non-product work, including technical website/application updates, physical print production, and social media/Telegram campaigns.

## 2. Main Objectives & Strategic Value

- **Unified Intake:** Consolidate all company requests, factory notifications, and team assignments into a single auditable system, eliminating fragmented chats and lost instructions.
- **Unambiguous Accountability:** Every task has exactly one accountable assignee, an explicit priority level (Urgent, High, Normal, Low), and a strict due date.
- **Total Operational Transparency:** Real-time visual tracking of every work item across distinct operational stages (Assigned, In Progress, Submitted, Needs Revision, Approved, On Hold, Cancelled).
- **Mandatory Quality Assurance:** Deliverables must pass formal review by Management & Planning before a task can be closed, ensuring brand and catalog standards.
- **Auditable Work History:** Full append-only audit trail logging every assignment change, revision request with written notes, submission timestamp, and manager approval.
- **Flexible Task Scope:** Supports both WooCommerce product-linked tasks (draft to publish) and standalone operational tasks (software fixes, marketing campaigns, print files).
- **Telegram Notification Bridge:** Integrate Telegram Bot API for instant real-time alerts, deadline warnings, and quick task access while keeping all proprietary data inside WordPress.

## 3. Actors, Roles & Responsibilities

The system coordinates seven distinct organizational roles, each with defined responsibilities, inputs, and outputs:

| **Role**              | **Primary Responsibilities**                                                                                                                                | **System Inputs & Triggered Actions**                                                                                                      | **Expected Outputs**                                                                              |
|-----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| CEO / Factory Manager | Sets company priorities, initiates strategic requests, reviews factory production readiness, and oversees company performance.                              | Submits high-level requests; views executive overview dashboard; receives scheduled performance reports.                                   | Strategic priorities, priority approvals, executive decisions.                                    |
| Factory Secretary     | Registers newly manufactured products, inputs initial technical specs, tracks factory production batches, and coordinates sample shipments for photography. | Submits 'New Product Registration' and 'Production Tracking' requests; enters draft product specs into WooCommerce.                        | Draft WooCommerce products, physical sample dispatch dates, technical dimension sheets.           |
| Sales Manager         | Identifies market demand, determines photography urgencies for high-selling items, and requests promotional campaigns for Telegram and social channels.     | Submits sales-driven requests with urgency tags and target channels.                                                                       | Campaign requests, priority sales lists, special discount/offer notices.                          |
| Management & Planning | Central coordinator: reviews requests, breaks requests into discrete tasks, assigns personnel, enforces deadlines, conducts QA reviews, and reports to CEO. | Approves/converts requests; creates tasks; assigns team members; approves submissions or requests revisions with notes; generates reports. | Structured task assignments, revision feedback, verified catalog items, executive weekly reports. |
| Photographer          | Receives physical products, executes studio/lifestyle photography, edits visual assets, and uploads standardized deliverables.                              | Receives photography task notifications; uploads high-res assets & gallery images; submits work for review.                                | Standardized web-ready images, lifestyle photos, raw archive links.                               |
| Programmer            | Maintains website infrastructure, WooCommerce customizations, custom plugin features, application APIs, and technical bug fixes.                            | Receives technical development tasks; updates staging environment; submits deployment notes and pull request links.                        | Tested code updates, resolved issue tickets, staging demonstration URLs.                          |
| Digital Admin         | Prepares Persian product descriptions, formats attributes, publishes approved products to WooCommerce, and posts to Telegram and social media.              | Receives content/publishing tasks; edits WooCommerce fields; publishes approved products; posts to Telegram/Instagram.                     | Live published WooCommerce products, published Telegram announcements, social post links.         |

## 4. Dual Operational Lifecycles & Workflow Mechanics

To ensure operational scalability, the system separates the high-level request intake from granular task execution:

### 4.1 Request Intake & Decomposition Cycle

Incoming Request (CEO / Secretary / Sales) → Management & Planning Review → Evaluation → Convert to 1..N Actionable Tasks (OR Reject with Reason) → Auto-Completion when all child tasks finish.

### 4.2 Task Execution & Quality Assurance Cycle

Task Assigned (with Due Date & Priority) → Assigned Worker Starts (In Progress) → Deliverables Uploaded (Submitted) → Manager Review → \[Approved & Completed OR Returned for Revision OR Put On Hold\].

### REVISION AND REJECTION LOOP MECHANICS

If a submitted deliverable does not satisfy quality criteria, Management returns the task to 'Needs Revision' with a mandatory written correction note. The assignee is instantly notified via Telegram/Dashboard, reviews the notes, corrects the work, and re-submits. All previous revision notes, timestamps, and uploaded versions remain preserved in the audit history for complete accountability.

## 5. State Dictionaries & Transition Rules

### 5.1 Request Statuses

| **Status**          | **Meaning & System Trigger**                                                                    | **Authorized Actors** | **Next Allowed States**        |
|---------------------|-------------------------------------------------------------------------------------------------|-----------------------|--------------------------------|
| Submitted           | Initial request registered by CEO, Factory Secretary, or Sales Manager.                         | Requester, Management | In Review, Rejected            |
| In Review           | Management is evaluating priority, resource availability, and technical scope.                  | Management & Planning | Planned / Converted, Rejected  |
| Planned / Converted | Request has been decomposed into one or more concrete execution tasks.                          | Management & Planning | Completed, Cancelled           |
| Rejected            | Request declined (e.g. duplicate, incomplete information, unfeasible). Mandatory reason logged. | Management & Planning | Archived, In Review (Reopened) |
| Completed           | All child tasks linked to this request have reached 'Approved / Completed'.                     | System Automated      | Closed                         |

### 5.2 Task Statuses

| **Status**           | **Meaning & Operational Context**                                                     | **Authorized Actors** | **Next Allowed States**              |
|----------------------|---------------------------------------------------------------------------------------|-----------------------|--------------------------------------|
| Assigned             | Task defined with accountable user, priority level, and deadline.                     | Management & Planning | In Progress, On Hold, Cancelled      |
| In Progress          | Assignee has acknowledged and is actively working on the deliverables.                | Assigned Worker       | Submitted, On Hold                   |
| On Hold              | Task paused due to external dependency (e.g., waiting for factory sample shipment).   | Management, Worker    | In Progress, Cancelled               |
| Submitted            | Worker has delivered required assets, links, or notes for formal QA review.           | Assigned Worker       | Approved / Completed, Needs Revision |
| Needs Revision       | Output failed QA; returned to worker with mandatory written feedback.                 | Management & Planning | In Progress, Cancelled               |
| Approved / Completed | Deliverables verified and accepted. Task closed; auto-updates linked product/request. | Management & Planning | Archived, Reopened (Manager only)    |
| Cancelled            | Task aborted due to product cancellation or strategy change. Reason logged.           | Management & Planning | Archived                             |

## 6. Task Taxonomy & Standard Deliverables

Every task type has predefined deliverable requirements to eliminate guesswork and standardize submissions:

| **Task Type**                     | **Default Assignee**    | **WC Product Link**   | **Required Deliverables & Formats**                                                                  |
|-----------------------------------|-------------------------|-----------------------|------------------------------------------------------------------------------------------------------|
| 1\. New Product Registration      | Factory Secretary       | Creates Draft Product | Basic title, SKU, dimensions, technical material specs, and reference photos.                        |
| 2\. Production Batch Follow-up    | Factory Secretary       | Mandatory Link        | Batch progress percentage, estimated completion date, ready quantity.                                |
| 3\. Product Photography           | Photographer            | Mandatory Link        | High-resolution white-background photos (minimum 3 angles), 1 lifestyle photo, web-optimized format. |
| 4\. Content Preparation           | Digital Admin           | Mandatory Link        | Persian marketing description, bulleted feature list, SEO title and meta description.                |
| 5\. Website Product Publishing    | Digital Admin           | Mandatory Link        | Completed pricing, verified categories, stock status, published live product URL.                    |
| 6\. Social Media & Telegram Post  | Digital Admin           | Optional Link         | Formatted Persian post copy, attached banner/video, scheduled time, channel link.                    |
| 7\. Print File Preparation        | Photographer / Designer | Optional Link         | Print-ready CMYK PDF file, 300 DPI layout, packaging/tag cut-line specifications.                    |
| 8\. Programming & App Development | Programmer              | No Link (Standalone)  | Git branch / PR link, test staging URL, deployment summary, affected file notes.                     |
| 9\. Custom Management Task        | Any Eligible User       | Optional Link         | Custom deliverable text, file uploads, or external document links as defined at creation.            |

## 7. Comprehensive Functional Requirements (FR-01 to FR-16)

| **ID** | **Requirement Name**                  | **Detailed Functional Specification**                                                                                                  |
|--------|---------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| FR-01  | Multi-Role Request Intake             | CEO, Factory Secretary, and Sales Manager can submit structured requests via a clean intake modal in WordPress or via Telegram bot.    |
| FR-02  | 1-to-N Task Decomposition             | Management can convert any request into multiple independent tasks, assigning distinct workers, priorities, and deadlines.             |
| FR-03  | Role-Based Assignment                 | Tasks can be assigned to individual registered team members filtered by their functional capability.                                   |
| FR-04  | Mandatory Priority & Due Dates        | Every task requires an explicit priority (Urgent, High, Normal, Low) and a due date. Overdue tasks are highlighted automatically.      |
| FR-05  | Focused 'My Tasks' View               | Workers have access to a clean workspace showing only their active and revision-pending tasks with direct action buttons.              |
| FR-06  | Multi-Format Deliverable Submission   | Workers can submit direct media library file uploads, ZIP archives, external URLs (Drive/Figma/Git), and explanatory notes.            |
| FR-07  | Formal QA Approval & Revision         | Management can approve deliverables or return them with mandatory written feedback. Multi-round revision histories are preserved.      |
| FR-08  | Flexible Product Linking              | Tasks can link to a WooCommerce product or exist as standalone operations with full independence.                                      |
| FR-09  | Immutable Audit Log                   | All status changes, reassignments, notes, deadlines, and approvals are stored in an append-only audit log with UTC timestamps.         |
| FR-10  | Executive Reporting Dashboard         | Provides real-time metrics on open, overdue, submitted, and completed tasks with filtering by assignee, role, and period.              |
| FR-11  | Role & Capability Matrix              | Granular WordPress capabilities restrict operations according to organizational responsibility (see matrix below).                     |
| FR-12  | Real-Time Multi-Channel Alerts        | Automatic notifications dispatched for task assignment, approaching deadlines (24h/6h), deliverable submission, and revision requests. |
| FR-13  | Telegram Bot Integration              | Dispatches instant alerts to personal Telegram chat IDs and the management channel, featuring direct task view links.                  |
| FR-14  | Automated WooCommerce Publishing      | Option for 1-click or automated status transition of WooCommerce products from 'Draft' to 'Published' upon final task approval.        |
| FR-15  | Exception Handling (On Hold & Cancel) | Allows pausing tasks with recorded blockers or cancelling obsolete tasks without corrupting product catalog state.                     |
| FR-16  | Batch & Multi-Product Workflows       | Support for assigning photography or content tasks across product batches sharing identical specifications.                            |

### 7.1 Granular Role & Permission Matrix

| **Role**              | **Submit Request** | **Plan & Assign** | **Execute & Submit** | **QA Review & Approve** | **Cancel Task** | **Publish WC** | **View Scope**          |
|-----------------------|--------------------|-------------------|----------------------|-------------------------|-----------------|----------------|-------------------------|
| CEO / Factory Manager | Yes                | No                | No                   | Final Reports           | No              | No             | All (Executive Read)    |
| Factory Secretary     | Yes                | No                | Draft Products       | No                      | No              | No             | Own Requests + Products |
| Sales Manager         | Yes                | No                | No                   | No                      | No              | No             | Own Requests            |
| Management & Planning | Yes                | Yes               | No                   | Yes                     | Yes             | Yes            | Full Administrative     |
| Photographer          | No                 | No                | Yes (Images)         | No                      | No              | No             | My Tasks Only           |
| Programmer            | No                 | No                | Yes (Code/URLs)      | No                      | No              | No             | My Tasks Only           |
| Digital Admin         | No                 | No                | Yes (Content)        | No                      | No              | Yes (Approved) | My Tasks + Catalog      |

## 8. WooCommerce Integration & Data Architecture

The system maintains a clean architectural boundary between the WooCommerce Product Catalog and the Operational Task Engine:

- **Canonical Product Data:** WooCommerce continues to store canonical product entities: SKU, regular price, sale price, stock levels, taxonomies (categories and tags), attributes, media attachments, and public visibility.
- **Dedicated Task Tables:** Operational metadata is stored in dedicated, indexed plugin tables: Request records, Task assignments, Deliverables, Status histories, and Audit trails. This prevents database bloat in wp_postmeta and guarantees fast querying.
- **Relational Linkage:** When Factory Secretary registers a product, a WooCommerce draft product is initialized. Tasks link via product_id. Standalone tasks (Programmer updates, Print brochures) store a NULL product_id.
- **Publishing Gateway:** When the 'Website Product Publishing' task is formally approved by Management & Planning, the system triggers the WooCommerce post_status transition from 'draft' to 'publish'.

## 9. Proposed User Interface & Screens

- **Management Command Dashboard:** Central table and Kanban views for Management & Planning. Multi-attribute filters by assignee, priority, status, task type, and deadline.
- **Personal 'My Tasks' Screen:** Streamlined personal screen for workers displaying only active tasks, due date countdowns, status badges, and action buttons ('Start', 'Upload Deliverables').
- **Simplified Request Intake Modal:** Minimalist modal accessible to CEO, Secretary, and Sales Manager to quickly log needs with title, description, priority, and optional sample photo.
- **Task Detail & Deliverable Review Screen:** Comprehensive task inspector displaying description, linked product details, full audit history, uploaded deliverables, and the manager QA approval/revision form.
- **WooCommerce Product Panel:** A dedicated panel embedded directly within the native WooCommerce product editor showing linked task statuses, assigned personnel, and quick navigation.
- **Executive Analytics & Reporting:** Visual analytics displaying total completed items, average turnaround time per worker, overdue ratios, and exportable weekly performance summaries.

## 10. Phased Implementation Roadmap

| **Phase**   | **Milestone Focus**                 | **Core Deliverables & Capabilities Included**                                                                                                                       |
|-------------|-------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Version 1.0 | Core Task & Workflow Engine         | Dual-entity database tables (Requests, Tasks, History); Roles & permissions; My Tasks screen; Management Dashboard; QA review & revision loop; WooCommerce linking. |
| Version 2.0 | Telegram Notification Bridge        | Telegram Bot API integration; direct task alerts to worker chat IDs; management channel alerts; approaching deadline automated warnings (24h/6h).                   |
| Version 3.0 | Interactive Telegram Actions        | Interactive Telegram inline keyboards: Workers can acknowledge tasks, check pending assignments, and submit completion notes directly through Telegram.             |
| Version 4.0 | Workflow Automation & AI Assistance | Automated image compression & web optimization upon photographer upload; AI-assisted product descriptions; automated batch publishing schedules.                    |

## 11. Definitive Operational Policy (Client Confirmations Resolved)

This section formally resolves all 7 items from earlier drafts, establishing definitive operational rules for system implementation:

- **Decision 1: Request Intake Flow:** The CEO, Factory Secretary, and Sales Manager enter requests directly into the system via a simplified web modal or Telegram bot form. Management & Planning acts as the evaluation gatekeeper, reviewing incoming requests, verifying feasibility, and converting approved requests into discrete tasks. This eliminates manual messenger chaos while maintaining executive oversight.
- **Decision 2: Standalone Tasks Without Products:** The system natively supports tasks with NO WooCommerce product. Product linkage is an optional foreign key. Technical programming fixes, general marketing campaigns, Telegram announcements, and print catalog tasks operate independently of the WooCommerce product catalog.
- **Decision 3: Mandatory Management QA Approval:** By default, EVERY deliverable requires review and approval by Management & Planning before task closure to safeguard brand quality. However, Management can optionally check a 'Self-Closing / Notify Only' flag on routine administrative tasks to prevent operational bottlenecks.
- **Decision 4: Mandatory Priority & Smart Deadlines:** Priority is mandatory for all tasks (Urgent, High, Normal, Low; defaults to Normal). Deadlines are mandatory during task planning. If an intake requester leaves the deadline blank, Management & Planning assigns a realistic due date based on the team's active workload.
- **Decision 5: Programmer Task Taxonomy:** Programmer tasks are formally classified into five categories: (a) Website Bug Fixes, (b) WooCommerce Feature Development, (c) Application & API Updates, (d) UI/UX Layout Enhancements, and (e) Server & Performance Optimizations. All require code commit/PR links and test URLs upon submission.
- **Decision 6: Digital Admin Scope:** The Digital Admin is responsible for both WooCommerce website publishing and Telegram/social media channel publishing. In teams with multiple administrators, permissions allow splitting these into separate assignees (Store Publisher vs. Social Media Specialist).
- **Decision 7: CEO System Access & Visibility:** The CEO receives both modes of visibility: (1) An Executive Read-Only Live Dashboard showing real-time status of all company tasks, bottlenecks, and overdue alerts, and (2) Automated Weekly Performance Reports delivered via PDF and Telegram summary.

## SIGN-OFF & APPROVAL STATUS

This Version 2.1 document serves as the approved software specification baseline. Development of the database schema, administrative interfaces, and integration adapters proceeds directly in accordance with these rules.
