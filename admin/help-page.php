<?php
defined('ABSPATH') || exit;

/**
 * PWF Help Page — in-plugin user guide explaining the full workflow
 * for every role, from request intake through task execution to publishing.
 */
class PWF_Help_Page {

    public static function boot() {
        add_action('admin_menu', function () {
            add_submenu_page(
                'pwf',
                pwf_t('Workflow Guide'),
                pwf_t('Help'),
                'view_assigned_products',
                'pwf-help',
                array(__CLASS__, 'render')
            );
        });
    }

    public static function render() {
        if (!current_user_can('view_assigned_products')) { wp_die(pwf_t('Access denied.')); }
        $locale = PWF_I18n::locale();
        $is_rtl = ($locale === 'fa_IR');

        echo '<div class="wrap pwf"' . PWF_I18n::attributes() . '>';

        // Hero
        echo '<div class="pwf-hero">';
        echo '<div class="pwf-hero-title">';
        echo '<div class="pwf-hero-icon"><span class="dashicons dashicons-book"></span></div>';
        echo '<div>';
        echo '<h1>' . esc_html(pwf_t('Workflow Guide')) . '</h1>';
        echo '<p>' . esc_html(pwf_t('Step-by-step reference for every role — from submitting a request to publishing a product.')) . '</p>';
        echo '</div></div>';
        echo '<div class="pwf-hero-actions">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=pwf')) . '" class="button pwf-btn-subtle">&larr; ' . esc_html(pwf_t('Dashboard')) . '</a>';
        echo '</div></div>';

        echo '<div style="max-width:900px; margin:24px auto 0;">';

        // Tab navigation (role-based)
        $tabs = array(
            'overview'   => pwf_t('Overview'),
            'request'    => pwf_t('Submitting a Request'),
            'management' => pwf_t('Management & Planning'),
            'worker'     => pwf_t('Worker / Executor'),
            'pipeline'   => pwf_t('Product Pipeline'),
            'telegram'   => pwf_t('Telegram Bot'),
            'settings'   => pwf_t('First-Time Setup'),
        );
        $active = sanitize_key(wp_unslash($_GET['tab'] ?? 'overview'));
        if (!isset($tabs[$active])) { $active = 'overview'; }

        echo '<div class="nav-tab-wrapper pwf-help-tabs" style="border-bottom:2px solid var(--pwf-primary-light, #e0e7ff);">';
        foreach ($tabs as $slug => $label) {
            $url   = admin_url('admin.php?page=pwf-help&tab=' . $slug);
            $class = ($slug === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . $class . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';

        echo '<div class="pwf-card" style="margin-top:0; border-radius:0 0 12px 12px; padding:32px 36px;">';

        switch ($active) {
            case 'overview':   self::tab_overview();   break;
            case 'request':    self::tab_request();    break;
            case 'management': self::tab_management(); break;
            case 'worker':     self::tab_worker();     break;
            case 'pipeline':   self::tab_pipeline();   break;
            case 'telegram':   self::tab_telegram();   break;
            case 'settings':   self::tab_settings();   break;
        }

        echo '</div>';
        echo '</div>'; // max-width wrapper
        echo '</div>'; // wrap
    }

    // ── TAB: Overview ────────────────────────────────────────────────────────────
    private static function tab_overview() {
        self::heading('🗺️ ' . pwf_t('How the Plugin Works'));
        self::para(pwf_t('Product Workflow for WooCommerce is a team coordination system. Instead of each person editing products directly in WooCommerce, everything flows through a structured process:'));

        self::flow(array(
            array('📥', pwf_t('Request'), pwf_t('CEO, Secretary, or Sales Manager submits a work request (e.g. "We need photos for product X").')),
            array('📋', pwf_t('Task'), pwf_t('Management & Planning converts the request into one or more Tasks, assigns them to specific team members with a due date and priority.')),
            array('⚙️', pwf_t('Execution'), pwf_t('The assigned worker sees the task in "My Tasks", starts it, completes the work, and submits a deliverable (URL, notes, or files).')),
            array('🔍', pwf_t('QA Review'), pwf_t('Management reviews the submission. They either Approve it ✅ (task closes) or Return it for Revision 🔴 (worker must fix and resubmit).')),
            array('🚀', pwf_t('Publish'), pwf_t('If the task type is "Website Publishing", approval automatically publishes the WooCommerce product.')),
        ));

        self::heading('👥 ' . pwf_t('Who Does What'));
        self::role_table(array(
            array(pwf_t('CEO / Factory Manager'), pwf_t('Executive Dashboard (read-only), submit Requests')),
            array(pwf_t('Factory Secretary'), pwf_t('Submit Requests, create new product drafts via Telegram or the web panel')),
            array(pwf_t('Sales Manager'), pwf_t('Submit Requests for photography, content, or social media')),
            array(pwf_t('Management & Planning'), pwf_t('Convert Requests to Tasks, assign workers, set priorities and deadlines, approve or return deliverables')),
            array(pwf_t('Photographer'), pwf_t('Execute photography tasks: upload product images')),
            array(pwf_t('Content Manager / Digital Admin'), pwf_t('Execute content tasks: write product description, SEO, attributes')),
            array(pwf_t('Product Reviewer'), pwf_t('Review and approve product content in the pipeline')),
            array(pwf_t('Social Media'), pwf_t('Execute social media tasks: publish posts after handoff')),
            array(pwf_t('Programmer'), pwf_t('Execute programming and app development tasks')),
        ));

        self::heading('🔄 ' . pwf_t('Two Parallel Systems'));
        self::para(pwf_t('The plugin operates two parallel systems:'));
        self::list_items(array(
            '<strong>' . esc_html(pwf_t('Request → Task Engine (new)')) . '</strong>: ' . esc_html(pwf_t('Flexible work items of 9 types, with priority, deadline, QA loop, and Telegram notifications.')),
            '<strong>' . esc_html(pwf_t('Product Pipeline (classic)')) . '</strong>: ' . esc_html(pwf_t('A linear flow for simple products: Photography → Content → Review → Published → Social → Completed.')),
        ));
    }

    // ── TAB: Submitting a Request ────────────────────────────────────────────────
    private static function tab_request() {
        self::heading('📥 ' . pwf_t('How to Submit a Request'));
        self::para(pwf_t('Requests are the starting point of all work. Any CEO, Factory Secretary, or Sales Manager can submit a request.'));

        self::steps(array(
            array(pwf_t('Go to Product Workflow → Requests in the sidebar.'), null),
            array(pwf_t('Click "Submit a new request" to expand the intake form.'), null),
            array(pwf_t('Fill in the required fields:'), array(
                '<strong>' . esc_html(pwf_t('Title')) . '</strong>: ' . esc_html(pwf_t('Short, clear summary of what is needed. Example: "Product photography for Summer Collection item XYZ".')),
                '<strong>' . esc_html(pwf_t('Request Type')) . '</strong>: ' . esc_html(pwf_t('Choose the category of work: New Product Registration, Photography, Content, Publishing, Social Media, Print File, Programming, or General.')),
                '<strong>' . esc_html(pwf_t('Priority')) . '</strong>: ' . esc_html(pwf_t('Urgent / High / Normal / Low. This helps Management schedule work.')),
                '<strong>' . esc_html(pwf_t('Linked Product ID (optional)')) . '</strong>: ' . esc_html(pwf_t('If the request is about an existing WooCommerce product, paste its ID here.')),
                '<strong>' . esc_html(pwf_t('Description / Notes')) . '</strong>: ' . esc_html(pwf_t('Any context, special instructions, or reference information.')),
            )),
            array(pwf_t('Click "Submit Request".'), null),
            array(pwf_t('Management & Planning will receive a Telegram notification and will review, then convert the request to one or more Tasks.'), null),
        ));

        self::heading('📊 ' . pwf_t('Request Statuses'));
        self::status_table(array(
            array('📥', pwf_t('Submitted'), pwf_t('Waiting for Management to review.')),
            array('🔍', pwf_t('In Review'), pwf_t('Management is reviewing the request.')),
            array('📋', pwf_t('Planned / Converted'), pwf_t('One or more Tasks have been created from this request.')),
            array('✅', pwf_t('Completed'), pwf_t('All tasks linked to this request have been approved.')),
            array('❌', pwf_t('Rejected'), pwf_t('Management rejected the request with a written reason.')),
        ));

        self::tip(pwf_t('You can track the status of all your requests at any time from Product Workflow → Requests → My Requests.'));
    }

    // ── TAB: Management & Planning ────────────────────────────────────────────────
    private static function tab_management() {
        self::heading('📋 ' . pwf_t('Management & Planning Workflow'));
        self::para(pwf_t('Management & Planning is the central coordinator. This role reviews incoming requests, creates tasks, assigns workers, and performs QA review on submitted deliverables.'));

        self::subheading(pwf_t('1. Review and Convert a Request'));
        self::steps(array(
            array(pwf_t('Go to Product Workflow → Requests. New requests appear with "Submitted" status.'), null),
            array(pwf_t('Click View to open the Request Detail page.'), null),
            array(pwf_t('Review the details. Optionally update status to "In Review" to signal you are working on it.'), null),
            array(pwf_t('Scroll to the "Create Task from Request" panel on the right side.'), null),
            array(pwf_t('Fill in the task form:'), array(
                '<strong>' . esc_html(pwf_t('Task Title')) . '</strong>: ' . esc_html(pwf_t('Specific work to be done. Can differ from request title.')),
                '<strong>' . esc_html(pwf_t('Task Type')) . '</strong>: ' . esc_html(pwf_t('Select the appropriate type (Photography, Content, Programming, etc.).')),
                '<strong>' . esc_html(pwf_t('Assign to')) . '</strong>: ' . esc_html(pwf_t('Select the team member responsible. They will receive a Telegram notification.')),
                '<strong>' . esc_html(pwf_t('Priority')) . '</strong>: ' . esc_html(pwf_t('Urgent, High, Normal, or Low.')),
                '<strong>' . esc_html(pwf_t('Due Date')) . '</strong> <span style="color:var(--pwf-danger);">*</span>: ' . esc_html(pwf_t('REQUIRED. Every task must have a deadline.')),
                '<strong>' . esc_html(pwf_t('Self-closing')) . '</strong>: ' . esc_html(pwf_t('Check this for simple tasks that auto-approve when the worker submits (no QA review needed).')),
            )),
            array(pwf_t('Click "Create Task". The assigned worker will receive an immediate Telegram alert.'), null),
        ));

        self::subheading(pwf_t('2. Reviewing a Submitted Task'));
        self::steps(array(
            array(pwf_t('When a worker submits deliverables, you receive a Telegram message and the task appears as "Submitted" in the dashboard.'), null),
            array(pwf_t('Click the task to open the Task Detail page.'), null),
            array(pwf_t('Review the deliverable (link, notes, or files attached).'), null),
            array(pwf_t('Choose one of:'), array(
                '✅ <strong>' . esc_html(pwf_t('Approve & Complete')) . '</strong>: ' . esc_html(pwf_t('Task is done. For Publishing tasks, the WooCommerce product is auto-published.')),
                '🔴 <strong>' . esc_html(pwf_t('Request Revision')) . '</strong>: ' . esc_html(pwf_t('MANDATORY: Write a clear correction note explaining what needs to change. The worker receives a Telegram alert with your note.')),
            )),
        ));

        self::subheading(pwf_t('3. Creating Standalone Tasks'));
        self::para(pwf_t('You can also create tasks without a Request (e.g. for internal work). Go to Product Workflow → New Task.'));

        self::subheading(pwf_t('4. Dashboard Overview'));
        self::para(pwf_t('The main Dashboard shows two KPI sections:'));
        self::list_items(array(
            '<strong>' . esc_html(pwf_t('Task Engine')) . '</strong>: ' . esc_html(pwf_t('Counts of Assigned / In Progress / Submitted / Needs Revision / Approved tasks.')),
            '<strong>' . esc_html(pwf_t('Product Pipeline')) . '</strong>: ' . esc_html(pwf_t('Classic pipeline statistics by photography/content/review stage.')),
        ));

        self::tip(pwf_t('Overdue tasks are highlighted with a ⚠ warning. Telegram reminders fire automatically 24 hours and 6 hours before each deadline.'));
    }

    // ── TAB: Worker / Executor ────────────────────────────────────────────────────
    private static function tab_worker() {
        self::heading('⚙️ ' . pwf_t('Worker Guide — My Tasks'));
        self::para(pwf_t('When Management assigns a task to you, you will receive a Telegram notification with a direct link. You can also find all your active tasks under Product Workflow → My Tasks.'));

        self::subheading(pwf_t('Task Lifecycle'));
        self::flow(array(
            array('📋', pwf_t('Assigned'), pwf_t('You have been assigned a task. Click ▶ Start Task to begin.')),
            array('⚙️', pwf_t('In Progress'), pwf_t('You are working on the task. When finished, click 📤 Submit for Review.')),
            array('🔴', pwf_t('Needs Revision'), pwf_t('Management returned the task with a correction note (shown in red). Read the note, fix the issue, and resubmit.')),
            array('⏸', pwf_t('On Hold'), pwf_t('The task is paused (e.g. waiting for factory sample). Click ▶ Resume when ready.')),
            array('✅', pwf_t('Approved'), pwf_t('Management approved your work. The task is complete.')),
        ));

        self::subheading(pwf_t('How to Submit Deliverables'));
        self::steps(array(
            array(pwf_t('Open the task (from My Tasks or via the Telegram link).'), null),
            array(pwf_t('Click 📤 Submit for Review.'), null),
            array(pwf_t('Paste a URL if applicable (Google Drive, GitHub, Figma, etc.).'), null),
            array(pwf_t('Write a brief summary of what you completed in the Notes field.'), null),
            array(pwf_t('Click Submit. Management receives a Telegram notification.'), null),
        ));

        self::subheading(pwf_t('Handling a Revision Request'));
        self::para(pwf_t('If your task is returned with status "Needs Revision", a red note will appear at the top of your My Tasks list explaining exactly what to fix. After making the corrections, resubmit the task using the same steps above.'));

        self::warning(pwf_t('Important: The revision note from Management is mandatory and must be addressed before resubmitting. Submitting without addressing the feedback may result in another revision request.'));

        self::subheading(pwf_t('Pipeline Tasks (Photography, Content, Review, Social)'));
        self::para(pwf_t('For the classic product pipeline, your tasks appear in the "Product Pipeline Tasks" section of My Tasks. Click "Open task" to see the product and perform your stage actions (upload images, save content, etc.).'));
    }

    // ── TAB: Product Pipeline ─────────────────────────────────────────────────────
    private static function tab_pipeline() {
        self::heading('🏭 ' . pwf_t('Classic Product Pipeline'));
        self::para(pwf_t('The Product Pipeline is the original linear flow for getting a product from the factory floor to WooCommerce. Each product moves through stages sequentially.'));

        self::flow(array(
            array('🆕', pwf_t('New Product'), pwf_t('Factory Secretary creates a draft product (in WordPress or via Telegram bot). Management enrolls it to the workflow.')),
            array('📸', pwf_t('Photography'), pwf_t('Photographer is assigned. They upload product images using the image uploader on the product page. When done, they advance the stage.')),
            array('✍️', pwf_t('Content'), pwf_t('Content Manager is assigned. They complete the product description, SKU, price, categories, SEO fields, and attributes using the workflow form.')),
            array('🔍', pwf_t('Review'), pwf_t('Product Reviewer checks all content and images. They can Approve (moves to publishing) or Reject with a reason (returns to content for corrections).')),
            array('🚀', pwf_t('Published'), pwf_t('Product Reviewer or Management publishes the product to WooCommerce. Product becomes live on the website.')),
            array('📱', pwf_t('Ready for Social'), pwf_t('Social Media team is notified to create posts. They access product assets (images, description) for their posts.')),
            array('✅', pwf_t('Completed'), pwf_t('Social Media marks the task complete. Full workflow cycle done.')),
        ));

        self::subheading(pwf_t('Starting a New Product'));
        self::steps(array(
            array(pwf_t('Go to Product Workflow → New Product.'), null),
            array(pwf_t('Enter the product name, SKU (optional), and factory notes. Click Create draft product.'), null),
            array(pwf_t('The product is now "New Product" status. Management can assign a Photographer immediately.'), null),
            array(pwf_t('Alternatively, you can also use the Telegram bot to create a new product and send reference photos without touching WordPress.'), null),
        ));

        self::subheading(pwf_t('Enrolling an Existing Draft'));
        self::para(pwf_t('If a product was already created directly in WooCommerce as a draft, enroll it from the Dashboard using the "Enroll an existing draft product" form. Enter its WooCommerce Product ID.'));

        self::tip(pwf_t('Editing a published product in WooCommerce directly will reset its approval status and return it to the Review stage automatically to maintain data integrity.'));
    }

    // ── TAB: Telegram Bot ─────────────────────────────────────────────────────────
    private static function tab_telegram() {
        self::heading('🤖 ' . pwf_t('Telegram Integration'));
        self::para(pwf_t('The Telegram integration has two features: (1) Automatic notifications sent to team members, and (2) An interactive bot for creating products and viewing tasks.'));

        self::subheading(pwf_t('Automatic Notifications'));
        self::para(pwf_t('These alerts are sent without any manual action:'));
        self::notification_table(array(
            array(pwf_t('New Request Submitted'),       pwf_t('Management channel')),
            array(pwf_t('Request Status Changed'),      pwf_t('Request submitter')),
            array(pwf_t('New Task Assigned'),           pwf_t('Assigned worker')),
            array(pwf_t('Task Submitted for Review'),   pwf_t('Management channel')),
            array(pwf_t('Task Approved'),               pwf_t('Assigned worker')),
            array(pwf_t('Revision Required'),           pwf_t('Assigned worker (includes correction note)')),
            array(pwf_t('24h Deadline Warning'),        pwf_t('Assigned worker')),
            array(pwf_t('6h Deadline Warning'),         pwf_t('Assigned worker')),
        ));

        self::subheading(pwf_t('Bot Commands (Interactive)'));
        self::para(pwf_t('After enabling the Webhook in Settings, team members can use the bot directly in Telegram:'));
        self::list_items(array(
            '<code>/start</code> — ' . esc_html(pwf_t('Welcome message and menu.')),
            '<code>/new</code> — ' . esc_html(pwf_t('Start a guided flow to create a new product (name → SKU → factory notes → reference photos).')),
            '<code>/tasks</code> — ' . esc_html(pwf_t('View your currently active assigned tasks.')),
            '<code>/cancel</code> — ' . esc_html(pwf_t('Cancel the current conversation flow at any time.')),
        ));

        self::subheading(pwf_t('Setting Up Your Personal Chat ID'));
        self::steps(array(
            array(pwf_t('Open Telegram and search for @userinfobot or message the bot.'), null),
            array(pwf_t('Send /start to get your numerical Chat ID (e.g. 123456789).'), null),
            array(pwf_t('Give this number to your Administrator, who will enter it in Settings → Team & Roles.'), null),
            array(pwf_t('You will now receive all task notifications directly in your Telegram.'), null),
        ));

        self::tip(pwf_t('Use your numerical Chat ID only — not your username or t.me/ link. Only numerical IDs work for private users.'));
    }

    // ── TAB: First-Time Setup ─────────────────────────────────────────────────────
    private static function tab_settings() {
        self::heading('⚙️ ' . pwf_t('First-Time Setup Guide'));
        self::para(pwf_t('Follow these steps as an Administrator to configure the plugin for your team.'));

        self::subheading(pwf_t('Step 1 — Activate the Plugin'));
        self::steps(array(
            array(pwf_t('Install and activate the plugin from WordPress → Plugins.'), null),
            array(pwf_t('Ensure WooCommerce is also active. The plugin requires WooCommerce.'), null),
            array(pwf_t('On first activation, all database tables and user roles are created automatically.'), null),
        ));

        self::subheading(pwf_t('Step 2 — Assign Roles to Team Members'));
        self::steps(array(
            array(pwf_t('Go to Product Workflow → Settings → Team & Roles.'), null),
            array(pwf_t('Each WordPress user in your team is listed. Select a role from the dropdown:'), array(
                pwf_t('Management & Planning — Central coordinator (assign this to your manager)'),
                pwf_t('CEO / Factory Manager — Executive read-only view'),
                pwf_t('Sales Manager — Can submit requests'),
                pwf_t('Factory (Secretary) — Can create products and submit requests'),
                pwf_t('Photographer — Photography stage tasks'),
                pwf_t('Content Manager — Content stage tasks'),
                pwf_t('Product Reviewer — Approve or reject in Review stage'),
                pwf_t('Social Media — Social media tasks after publishing'),
                pwf_t('Programmer — Programming and development tasks'),
            )),
            array(pwf_t('Click "Update Role & Telegram" to save each user.'), null),
        ));

        self::subheading(pwf_t('Step 3 — Configure Telegram (optional but recommended)'));
        self::steps(array(
            array(pwf_t('Create a Telegram bot via @BotFather. You will receive a Bot Token.'), null),
            array(pwf_t('Create a group or channel for management notifications. Add the bot as admin.'), null),
            array(pwf_t('Go to Settings → Telegram Notifications.'), null),
            array(pwf_t('Paste the Bot Token and enable notifications.'), null),
            array(pwf_t('Enter the management group/channel ID as the Default Chat / Channel ID.'), null),
            array(pwf_t('Click "Save Settings", then "Send Test Message" to verify.'), null),
            array(pwf_t('Ask each team member to message @userinfobot to find their personal Chat ID. Enter each personal Chat ID in Team & Roles.'), null),
            array(pwf_t('Optionally enable the Webhook to allow the interactive bot commands (/new, /tasks).'), null),
        ));

        self::subheading(pwf_t('Step 4 — Set Default Language'));
        self::steps(array(
            array(pwf_t('Go to Settings → Languages.'), null),
            array(pwf_t('Set the Default System Language. Supported: English, Persian (فارسی), Italian.'), null),
            array(pwf_t('Individual users can override the language from any workflow page using the language selector at the bottom.'), null),
        ));

        self::subheading(pwf_t('Step 5 — Start Using'));
        self::list_items(array(
            pwf_t('Have your Secretary/CEO/Sales Manager submit the first Request.'),
            pwf_t('Management converts it to a Task and assigns a worker.'),
            pwf_t('The worker sees it in My Tasks and completes it.'),
            pwf_t('Management reviews and approves.'),
        ));

        self::tip(pwf_t('All workflow data is stored in separate database tables — it never conflicts with standard WooCommerce product data.'));
    }

    // ── Reusable layout helpers ───────────────────────────────────────────────────

    private static function heading($text) {
        echo '<h2 style="font-size:20px; font-weight:700; color:var(--pwf-slate-800,#1e293b); margin:28px 0 12px; display:flex; align-items:center; gap:10px;">' . wp_kses_post($text) . '</h2>';
    }

    private static function subheading($text) {
        echo '<h3 style="font-size:16px; font-weight:600; color:var(--pwf-slate-700,#334155); margin:22px 0 8px; border-bottom:1px solid var(--pwf-slate-100,#f1f5f9); padding-bottom:6px;">' . esc_html($text) . '</h3>';
    }

    private static function para($text) {
        echo '<p style="color:var(--pwf-slate-600,#475569); line-height:1.75; margin:0 0 12px;">' . esc_html($text) . '</p>';
    }

    private static function tip($text) {
        echo '<div style="background:rgba(99,102,241,0.07); border-left:4px solid var(--pwf-primary,#6366f1); border-radius:6px; padding:12px 16px; margin:16px 0; color:var(--pwf-primary,#6366f1); font-size:13px;">';
        echo '<strong>💡 ' . esc_html(pwf_t('Tip')) . ':</strong> ' . esc_html($text);
        echo '</div>';
    }

    private static function warning($text) {
        echo '<div style="background:rgba(239,68,68,0.07); border-left:4px solid #ef4444; border-radius:6px; padding:12px 16px; margin:16px 0; color:#b91c1c; font-size:13px;">';
        echo '<strong>⚠️ ' . esc_html(pwf_t('Important')) . ':</strong> ' . esc_html($text);
        echo '</div>';
    }

    private static function list_items($items) {
        echo '<ul style="margin:8px 0 16px 20px; padding:0; color:var(--pwf-slate-600,#475569); line-height:1.8;">';
        foreach ($items as $item) {
            echo '<li style="margin-bottom:4px;">' . wp_kses_post($item) . '</li>';
        }
        echo '</ul>';
    }

    private static function steps($steps) {
        echo '<ol style="margin:8px 0 16px 20px; padding:0; color:var(--pwf-slate-600,#475569); line-height:1.9;">';
        foreach ($steps as list($label, $sub)) {
            echo '<li style="margin-bottom:6px;">' . esc_html($label);
            if ($sub) {
                echo '<ul style="margin:6px 0 0 16px; padding:0;">';
                foreach ($sub as $s) {
                    echo '<li style="margin-bottom:4px; font-size:13px;">' . wp_kses_post($s) . '</li>';
                }
                echo '</ul>';
            }
            echo '</li>';
        }
        echo '</ol>';
    }

    /** Flow: array of [icon, title, description] */
    private static function flow($steps) {
        echo '<div style="position:relative; margin:16px 0 24px;">';
        foreach ($steps as $i => $step) {
            list($icon, $title, $desc) = $step;
            $is_last = ($i === count($steps) - 1);
            echo '<div style="display:flex; gap:16px; margin-bottom:' . ($is_last ? '0' : '24') . 'px; position:relative;">';
            // Icon column + connector
            echo '<div style="display:flex; flex-direction:column; align-items:center; flex-shrink:0;">';
            echo '<div style="width:44px; height:44px; border-radius:50%; background:var(--pwf-primary-bg,#eef2ff); display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 0 0 3px rgba(99,102,241,0.1);">' . esc_html($icon) . '</div>';
            if (!$is_last) {
                echo '<div style="width:2px; flex:1; background:linear-gradient(to bottom, rgba(99,102,241,0.3), rgba(99,102,241,0.05)); min-height:20px; margin:4px 0;"></div>';
            }
            echo '</div>';
            // Text
            echo '<div style="padding-top:10px;">';
            echo '<strong style="display:block; font-size:14px; color:var(--pwf-slate-800,#1e293b); margin-bottom:3px;">' . esc_html($title) . '</strong>';
            echo '<span style="font-size:13px; color:var(--pwf-slate-500,#64748b); line-height:1.6;">' . esc_html($desc) . '</span>';
            echo '</div></div>';
        }
        echo '</div>';
    }

    /** Role table */
    private static function role_table($rows) {
        echo '<div class="pwf-table-container"><table class="widefat" style="margin:12px 0 20px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html(pwf_t('Role')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Responsibilities')) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as list($role, $desc)) {
            echo '<tr><td><strong>' . esc_html($role) . '</strong></td><td style="font-size:13px; color:var(--pwf-slate-600,#475569);">' . esc_html($desc) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Status table: icon, status, description */
    private static function status_table($rows) {
        echo '<div class="pwf-table-container"><table class="widefat" style="margin:12px 0 20px;">';
        echo '<thead><tr>';
        echo '<th style="width:40px;"></th>';
        echo '<th>' . esc_html(pwf_t('Status')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Meaning')) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as list($icon, $status, $desc)) {
            echo '<tr>';
            echo '<td style="text-align:center; font-size:18px;">' . esc_html($icon) . '</td>';
            echo '<td><strong>' . esc_html($status) . '</strong></td>';
            echo '<td style="font-size:13px; color:var(--pwf-slate-600,#475569);">' . esc_html($desc) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Notification table: event, recipient */
    private static function notification_table($rows) {
        echo '<div class="pwf-table-container"><table class="widefat" style="margin:12px 0 20px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html(pwf_t('Event')) . '</th>';
        echo '<th>' . esc_html(pwf_t('Recipient')) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as list($event, $recipient)) {
            echo '<tr>';
            echo '<td style="font-size:13px;">' . esc_html($event) . '</td>';
            echo '<td style="font-size:13px; color:var(--pwf-slate-600,#475569);">' . esc_html($recipient) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}

function pwf_help_page() { PWF_Help_Page::render(); }
