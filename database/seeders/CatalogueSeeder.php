<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalogue\Models\BusinessCategory;
use App\Domain\Catalogue\Models\Module;
use App\Domain\Catalogue\Models\ModulePillar;
use App\Domain\Catalogue\ModuleAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue itself.
 *
 * ── Idempotent on purpose ────────────────────────────────────────────────────
 *
 * Everything here is upserted by key, never inserted blindly. This seeder runs
 * again on every deploy that changes the catalogue, against databases that
 * already hold live workspaces — so it has to be safe to re-run, and it must
 * never touch workspace_modules. Adding a module to this file makes it
 * *available*; it does not reach into anybody's workspace and switch it on.
 *
 * ── Why presets are declared per pillar ──────────────────────────────────────
 *
 * Eight categories times ninety modules is seven hundred decisions, and nobody
 * can hold that in their head or review it honestly. The real decision is
 * coarser than that: a retailer wants the Catalogue pillar, a consultancy does
 * not. So defaults are declared one pillar at a time and expanded to the
 * modules inside them. A module needing an exception can still get one later,
 * as a row in category_module_presets — this is the starting point, not a
 * ceiling.
 */
class CatalogueSeeder extends Seeder
{
    /**
     * The twelve groups the sidebar draws.
     *
     * @var list<array{key: string, label: string, icon: string}>
     */
    private const PILLARS = [
        ['key' => 'work', 'label' => 'Workspace', 'icon' => 'chart-line-up'],
        ['key' => 'revenue', 'label' => 'Revenue', 'icon' => 'shopping-cart'],
        ['key' => 'delivery', 'label' => 'Service Delivery', 'icon' => 'calendar-check'],
        ['key' => 'cx', 'label' => 'Customer Experience', 'icon' => 'chats-circle'],
        ['key' => 'catalogue', 'label' => 'Catalogue & Inventory', 'icon' => 'package'],
        ['key' => 'operations', 'label' => 'Operations', 'icon' => 'factory'],
        ['key' => 'finance', 'label' => 'Finance', 'icon' => 'scales'],
        ['key' => 'people', 'label' => 'People', 'icon' => 'users-three'],
        ['key' => 'growth', 'label' => 'Growth', 'icon' => 'megaphone'],
        ['key' => 'web', 'label' => 'Storefront & Web', 'icon' => 'storefront'],
        ['key' => 'intelligence', 'label' => 'Intelligence', 'icon' => 'sparkle'],
        ['key' => 'documents', 'label' => 'Documents', 'icon' => 'notebook'],
        ['key' => 'platform', 'label' => 'Platform', 'icon' => 'gear'],
    ];

    /**
     * Every module, by pillar.
     *
     * `built` is true only where a screen genuinely exists today — currently
     * the dashboard and settings, per App\Support\Modules::BUILT. Claiming
     * otherwise would put dead entries in the sidebar.
     *
     * `core` marks what nobody may switch off: the ledger everything posts
     * into, the customer record everything hangs off, the platform's own
     * plumbing, and the conversation tools every subscriber gets regardless
     * of what they sell.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private const MODULES = [
        'work' => [
            ['key' => 'work.mine', 'label' => 'My workspace', 'icon' => 'user-focus', 'core' => true, 'path' => '/workspace'],
            ['key' => 'work.dashboard', 'label' => 'Dashboard', 'icon' => 'squares-four', 'can' => 'dashboard.view', 'core' => true, 'built' => true, 'path' => '/dashboard'],
        ],

        'revenue' => [
            ['key' => 'revenue.customers', 'label' => 'Customers', 'icon' => 'address-book', 'core' => true, 'built' => true, 'path' => '/customers'],
            ['key' => 'revenue.leads', 'label' => 'Leads', 'icon' => 'user-plus', 'built' => true, 'path' => '/leads'],
            ['key' => 'revenue.pipeline', 'label' => 'Pipeline & Deals', 'icon' => 'flow-arrow', 'built' => true, 'path' => '/pipeline'],
            ['key' => 'revenue.quotes', 'label' => 'Quotes & Proposals', 'icon' => 'file-magnifying-glass'],
            ['key' => 'revenue.orders', 'label' => 'Orders', 'icon' => 'shopping-cart', 'can' => 'orders.view', 'requires' => ['finance.transactions'], 'built' => true, 'path' => '/orders'],
            ['key' => 'revenue.pos', 'label' => 'Point of Sale', 'icon' => 'cash-register', 'can' => 'orders.create', 'requires' => ['revenue.orders'], 'built' => true, 'path' => '/pos'],
            ['key' => 'revenue.returns', 'label' => 'Returns & RTO', 'icon' => 'arrow-u-down-left', 'can' => 'orders.view', 'requires' => ['revenue.orders'], 'built' => true, 'path' => '/returns'],
            ['key' => 'revenue.courier', 'label' => 'Courier & Delivery', 'icon' => 'truck', 'can' => 'orders.view', 'requires' => ['revenue.orders'], 'built' => true, 'path' => '/courier'],
            ['key' => 'revenue.contracts', 'label' => 'Contracts & Renewals', 'icon' => 'handshake'],
            ['key' => 'revenue.subscriptions', 'label' => 'Subscriptions', 'icon' => 'arrows-clockwise', 'requires' => ['finance.invoicing']],
        ],

        'delivery' => [
            ['key' => 'delivery.projects', 'label' => 'Projects & Tasks', 'icon' => 'target'],
            ['key' => 'delivery.timesheets', 'label' => 'Timesheets', 'icon' => 'clock-user', 'requires' => ['delivery.projects']],
            ['key' => 'delivery.bookings', 'label' => 'Bookings & Appointments', 'icon' => 'calendar-check'],
            ['key' => 'delivery.field', 'label' => 'Field Service', 'icon' => 'truck'],
            ['key' => 'delivery.jobs', 'label' => 'Job Cards', 'icon' => 'wrench'],
            ['key' => 'delivery.scheduling', 'label' => 'Resource Scheduling', 'icon' => 'columns'],
            ['key' => 'delivery.slas', 'label' => 'SLAs', 'icon' => 'seal-check'],
        ],

        'cx' => [
            ['key' => 'cx.inbox', 'label' => 'Inbox', 'icon' => 'chats-circle', 'core' => true, 'built' => true, 'path' => '/inbox'],
            ['key' => 'cx.live_chat', 'label' => 'Live Chat', 'icon' => 'chat-text', 'core' => true, 'built' => true, 'path' => '/live-chat'],
            ['key' => 'cx.channels', 'label' => 'Channels', 'icon' => 'plug', 'requires' => ['cx.inbox'], 'built' => true, 'path' => '/channels'],
            ['key' => 'cx.templates', 'label' => 'Message Templates', 'icon' => 'chat-text', 'requires' => ['cx.inbox'], 'built' => true, 'path' => '/message-templates'],
            ['key' => 'cx.automations', 'label' => 'Automations', 'icon' => 'flow-arrow', 'requires' => ['cx.inbox'], 'built' => true, 'path' => '/automations'],
            ['key' => 'cx.helpdesk', 'label' => 'Helpdesk & Tickets', 'icon' => 'seal-check', 'requires' => ['cx.inbox']],
            ['key' => 'cx.knowledge', 'label' => 'Knowledge Base', 'icon' => 'notebook'],
            ['key' => 'cx.feedback', 'label' => 'CSAT & Feedback', 'icon' => 'medal'],
        ],

        'catalogue' => [
            ['key' => 'catalogue.products', 'label' => 'Products', 'icon' => 'squares-four', 'can' => 'catalogue.view', 'built' => true, 'path' => '/products'],
            ['key' => 'catalogue.services', 'label' => 'Services & Rate Cards', 'icon' => 'tag', 'built' => true, 'path' => '/services'],
            ['key' => 'catalogue.pricing', 'label' => 'Price Lists', 'icon' => 'currency-circle-dollar', 'built' => true, 'path' => '/price-lists'],
            ['key' => 'catalogue.stock', 'label' => 'Stock', 'icon' => 'package', 'can' => 'stock.view', 'built' => true, 'path' => '/stock'],
            ['key' => 'catalogue.warehouses', 'label' => 'Warehouses', 'icon' => 'warehouse', 'can' => 'stock.view', 'requires' => ['catalogue.stock'], 'built' => true, 'path' => '/warehouses'],
            ['key' => 'catalogue.purchasing', 'label' => 'Purchasing', 'icon' => 'shopping-bag-open', 'can' => 'stock.edit', 'built' => true, 'path' => '/purchasing'],
            ['key' => 'catalogue.batches', 'label' => 'Batch & Serial', 'icon' => 'list-magnifying-glass', 'requires' => ['catalogue.stock'], 'built' => true, 'path' => '/batches'],
        ],

        'operations' => [
            ['key' => 'operations.bom', 'label' => 'Bill of Materials', 'icon' => 'tree-structure', 'requires' => ['catalogue.products']],
            ['key' => 'operations.production', 'label' => 'Production Orders', 'icon' => 'factory', 'can' => 'stock.edit', 'requires' => ['operations.bom']],
            ['key' => 'operations.quality', 'label' => 'Quality Control', 'icon' => 'seal-check', 'can' => 'stock.edit'],
            ['key' => 'operations.maintenance', 'label' => 'Maintenance', 'icon' => 'wrench', 'can' => 'stock.edit'],
            ['key' => 'operations.fleet', 'label' => 'Fleet', 'icon' => 'truck'],
        ],

        'finance' => [
            ['key' => 'finance.transactions', 'label' => 'Transactions', 'icon' => 'receipt', 'core' => true, 'built' => true, 'path' => '/transactions'],
            ['key' => 'finance.journal', 'label' => 'Daily Journal', 'icon' => 'notebook', 'core' => true, 'built' => true, 'path' => '/journal'],
            ['key' => 'finance.accounts', 'label' => 'Chart of Accounts', 'icon' => 'tree-structure', 'core' => true, 'built' => true, 'path' => '/accounts'],
            ['key' => 'finance.fiscal', 'label' => 'Fiscal Years', 'icon' => 'calendar-check', 'core' => true, 'built' => true, 'path' => '/fiscal-years'],
            ['key' => 'finance.invoicing', 'label' => 'Invoicing', 'icon' => 'receipt', 'requires' => ['finance.transactions'], 'built' => true, 'path' => '/invoicing'],
            ['key' => 'finance.payments', 'label' => 'Payments', 'icon' => 'currency-circle-dollar', 'requires' => ['finance.transactions'], 'built' => true, 'path' => '/payments'],
            ['key' => 'finance.ledgers', 'label' => 'Receivable & Payable', 'icon' => 'scales', 'can' => 'transactions.view', 'built' => true, 'path' => '/ledgers'],
            ['key' => 'finance.expenses', 'label' => 'Expense Claims', 'icon' => 'receipt-x', 'can' => 'transactions.create', 'built' => true, 'path' => '/expenses'],
            ['key' => 'finance.assets', 'label' => 'Assets', 'icon' => 'buildings', 'can' => 'transactions.edit'],
            ['key' => 'finance.budgets', 'label' => 'Budgets', 'icon' => 'chart-line'],
            ['key' => 'finance.tax', 'label' => 'Tax & Compliance', 'icon' => 'scales'],
            ['key' => 'finance.reports', 'label' => 'Reports', 'icon' => 'chart-line', 'can' => 'reports.view'],
        ],

        'people' => [
            ['key' => 'people.employees', 'label' => 'Employees', 'icon' => 'users-three', 'can' => 'people.view', 'built' => true, 'path' => '/employees'],
            ['key' => 'people.attendance', 'label' => 'Attendance & Leave', 'icon' => 'clock-user', 'can' => 'people.view', 'built' => true, 'path' => '/attendance'],
            ['key' => 'people.shifts', 'label' => 'Shifts & Rota', 'icon' => 'columns', 'requires' => ['people.employees']],
            ['key' => 'people.payroll', 'label' => 'Payroll', 'icon' => 'wallet', 'can' => 'people.edit', 'requires' => ['people.employees', 'finance.transactions']],
            ['key' => 'people.commissions', 'label' => 'Commissions & Rewards', 'icon' => 'medal', 'requires' => ['people.employees']],
            ['key' => 'people.recruitment', 'label' => 'Recruitment', 'icon' => 'user-plus', 'can' => 'people.edit'],
            ['key' => 'people.performance', 'label' => 'Performance', 'icon' => 'target', 'can' => 'people.view'],
            ['key' => 'people.training', 'label' => 'Training', 'icon' => 'graduation-cap', 'can' => 'people.edit'],
        ],

        'growth' => [
            ['key' => 'growth.campaigns', 'label' => 'Campaigns', 'icon' => 'megaphone', 'can' => 'reports.view', 'requires' => ['cx.channels'], 'built' => true, 'path' => '/campaigns'],
            ['key' => 'growth.offers', 'label' => 'Offers & Coupons', 'icon' => 'tag', 'can' => 'catalogue.edit', 'built' => true, 'path' => '/offers'],
            ['key' => 'growth.loyalty', 'label' => 'Loyalty', 'icon' => 'medal', 'can' => 'catalogue.edit', 'built' => true, 'path' => '/loyalty'],
            ['key' => 'growth.referrals', 'label' => 'Referrals', 'icon' => 'handshake', 'built' => true, 'path' => '/referrals'],
            ['key' => 'growth.reviews', 'label' => 'Reviews', 'icon' => 'star', 'built' => true, 'path' => '/reviews'],
            ['key' => 'growth.forms', 'label' => 'Forms & Landing Pages', 'icon' => 'images', 'built' => true, 'path' => '/forms'],
        ],

        'web' => [
            ['key' => 'web.storefronts', 'label' => 'Storefronts', 'icon' => 'storefront', 'can' => 'stores.edit', 'built' => true, 'path' => '/storefronts'],
            ['key' => 'web.store', 'label' => 'Online Store', 'icon' => 'shopping-bag-open', 'requires' => ['catalogue.products'], 'built' => true, 'path' => '/online-store'],
            ['key' => 'web.booking_pages', 'label' => 'Booking Pages', 'icon' => 'calendar-check', 'requires' => ['delivery.bookings']],
            ['key' => 'web.portal', 'label' => 'Customer Portal', 'icon' => 'user-focus'],
            ['key' => 'web.payment_links', 'label' => 'Payment Links', 'icon' => 'currency-circle-dollar', 'requires' => ['finance.payments'], 'built' => true, 'path' => '/payment-links'],
        ],

        'intelligence' => [
            ['key' => 'intelligence.ask', 'label' => 'Ask Angisflow', 'icon' => 'sparkle', 'can' => 'reports.view', 'built' => true, 'path' => '/ask'],
            ['key' => 'intelligence.dashboards', 'label' => 'Dashboards & KPIs', 'icon' => 'chart-line-up', 'can' => 'reports.view', 'built' => true, 'path' => '/dashboards'],
            ['key' => 'intelligence.reports', 'label' => 'AI Reports', 'icon' => 'file-magnifying-glass', 'can' => 'reports.view', 'built' => true, 'path' => '/ai-reports'],
            ['key' => 'intelligence.alerts', 'label' => 'Alerts & Anomalies', 'icon' => 'warning-diamond', 'can' => 'reports.view', 'built' => true, 'path' => '/alerts'],
            ['key' => 'intelligence.forecasting', 'label' => 'Forecasting', 'icon' => 'chart-line-up', 'can' => 'reports.view', 'built' => true, 'path' => '/forecasting'],
        ],

        'documents' => [
            ['key' => 'documents.store', 'label' => 'Documents', 'icon' => 'notebook', 'built' => true, 'path' => '/documents'],
            ['key' => 'documents.esign', 'label' => 'E-signature', 'icon' => 'pencil-simple'],
            ['key' => 'documents.templates', 'label' => 'Templates', 'icon' => 'copy'],
            ['key' => 'documents.audit', 'label' => 'Audit Log', 'icon' => 'list-magnifying-glass', 'can' => 'settings.view', 'core' => true, 'built' => true, 'path' => '/audit-log'],
        ],

        'platform' => [
            ['key' => 'platform.users', 'label' => 'Users & Roles', 'icon' => 'shield-check', 'can' => 'settings.view', 'core' => true, 'built' => true, 'path' => '/users-roles'],
            ['key' => 'platform.settings', 'label' => 'Settings', 'icon' => 'gear', 'can' => 'settings.view', 'core' => true, 'built' => true, 'path' => '/settings', 'matches' => ['/settings']],
            ['key' => 'platform.partners', 'label' => 'Partners', 'icon' => 'handshake'],
            ['key' => 'platform.integrations', 'label' => 'Integrations & API', 'icon' => 'plug', 'can' => 'settings.view', 'built' => true, 'path' => '/integrations-api'],
            ['key' => 'platform.automations', 'label' => 'Automations', 'icon' => 'flow-arrow', 'can' => 'settings.view'],
            ['key' => 'platform.billing', 'label' => 'Billing', 'icon' => 'wallet'],
            ['key' => 'platform.localization', 'label' => 'Localization', 'icon' => 'compass', 'can' => 'settings.view'],
        ],
    ];

    /**
     * Operating model first, the subscriber's own word for themselves second.
     *
     * @var list<array{key: string, icon: string, name: string, summary: string, children: list<array{key: string, name: string}>}>
     */
    private const CATEGORIES = [
        [
            'key' => 'retail', 'icon' => 'storefront', 'name' => 'Retail & E-commerce',
            'summary' => 'Sells physical goods from stock.',
            'children' => [
                ['key' => 'retail.online', 'name' => 'Online store'],
                ['key' => 'retail.fashion', 'name' => 'Fashion & apparel'],
                ['key' => 'retail.electronics', 'name' => 'Electronics'],
                ['key' => 'retail.grocery', 'name' => 'Grocery & FMCG'],
                ['key' => 'retail.home', 'name' => 'Home & furniture'],
                ['key' => 'retail.multi', 'name' => 'Multi-brand retail'],
                ['key' => 'retail.marketplace', 'name' => 'Marketplace seller'],
            ],
        ],
        [
            'key' => 'hospitality', 'icon' => 'cash-register', 'name' => 'Food & Hospitality',
            'summary' => 'Perishable stock, shift labour, table or room turnover.',
            'children' => [
                ['key' => 'hospitality.restaurant', 'name' => 'Restaurant'],
                ['key' => 'hospitality.cafe', 'name' => 'Café & bakery'],
                ['key' => 'hospitality.cloud', 'name' => 'Cloud kitchen'],
                ['key' => 'hospitality.catering', 'name' => 'Catering'],
                ['key' => 'hospitality.bar', 'name' => 'Bar'],
                ['key' => 'hospitality.hotel', 'name' => 'Hotel & guesthouse'],
            ],
        ],
        [
            'key' => 'professional', 'icon' => 'handshake', 'name' => 'Professional Services',
            'summary' => 'Sells expertise measured in time.',
            'children' => [
                ['key' => 'professional.agency', 'name' => 'Agency'],
                ['key' => 'professional.consulting', 'name' => 'Consulting'],
                ['key' => 'professional.accounting', 'name' => 'Accounting & legal'],
                ['key' => 'professional.it', 'name' => 'IT services'],
                ['key' => 'professional.engineering', 'name' => 'Architecture & engineering'],
                ['key' => 'professional.studio', 'name' => 'Studio & freelance'],
            ],
        ],
        [
            'key' => 'field', 'icon' => 'wrench', 'name' => 'Field & Home Services',
            'summary' => 'Sends people and vehicles to customer sites.',
            'children' => [
                ['key' => 'field.repair', 'name' => 'Repair & maintenance'],
                ['key' => 'field.cleaning', 'name' => 'Cleaning'],
                ['key' => 'field.construction', 'name' => 'Construction & contracting'],
                ['key' => 'field.installation', 'name' => 'Installation'],
                ['key' => 'field.landscaping', 'name' => 'Landscaping'],
                ['key' => 'field.pest', 'name' => 'Pest control'],
            ],
        ],
        [
            'key' => 'wellness', 'icon' => 'hospital', 'name' => 'Health & Wellness',
            'summary' => 'Appointment-led, repeat clients, records.',
            'children' => [
                ['key' => 'wellness.clinic', 'name' => 'Clinic & practice'],
                ['key' => 'wellness.dental', 'name' => 'Dental'],
                ['key' => 'wellness.salon', 'name' => 'Salon & spa'],
                ['key' => 'wellness.gym', 'name' => 'Gym & fitness'],
                ['key' => 'wellness.therapy', 'name' => 'Therapy'],
                ['key' => 'wellness.vet', 'name' => 'Veterinary'],
            ],
        ],
        [
            'key' => 'education', 'icon' => 'graduation-cap', 'name' => 'Education & Training',
            'summary' => 'Cohorts, enrolment, recurring fees.',
            'children' => [
                ['key' => 'education.school', 'name' => 'School & institute'],
                ['key' => 'education.coaching', 'name' => 'Coaching centre'],
                ['key' => 'education.online', 'name' => 'Online courses'],
                ['key' => 'education.tutoring', 'name' => 'Tutoring'],
                ['key' => 'education.driving', 'name' => 'Driving school'],
            ],
        ],
        [
            'key' => 'industrial', 'icon' => 'factory', 'name' => 'Manufacturing & Wholesale',
            'summary' => 'Transforms or moves goods in bulk, on B2B terms.',
            'children' => [
                ['key' => 'industrial.manufacturing', 'name' => 'Manufacturing'],
                ['key' => 'industrial.assembly', 'name' => 'Assembly'],
                ['key' => 'industrial.wholesale', 'name' => 'Wholesale & distribution'],
                ['key' => 'industrial.trade', 'name' => 'Import & export'],
            ],
        ],
        [
            'key' => 'rental', 'icon' => 'key', 'name' => 'Rental & Assets',
            'summary' => 'Earns from use of owned assets over time.',
            'children' => [
                ['key' => 'rental.equipment', 'name' => 'Equipment rental'],
                ['key' => 'rental.vehicle', 'name' => 'Vehicle rental'],
                ['key' => 'rental.property', 'name' => 'Property & real estate'],
                ['key' => 'rental.event', 'name' => 'Event rental'],
            ],
        ],
        [
            'key' => 'other', 'icon' => 'squares-four', 'name' => 'Something else',
            'summary' => 'A broad starting set you can trim to fit.',
            'children' => [],
        ],
    ];

    /**
     * Which pillars a category starts with, from the published preset matrix.
     *
     * `work`, `cx`, `finance`, `people` and `platform` are omitted from every
     * row because they are on for everyone — the matrix showed no category
     * without them, which is the same finding that made several of their
     * modules core.
     *
     * @var array<string, list<string>>
     */
    private const PRESETS = [
        'retail' => ['revenue', 'catalogue', 'growth', 'web', 'intelligence'],
        'hospitality' => ['revenue', 'delivery', 'catalogue', 'growth', 'web'],
        'professional' => ['revenue', 'delivery', 'intelligence', 'documents'],
        'field' => ['revenue', 'delivery', 'catalogue', 'operations', 'web', 'documents'],
        'wellness' => ['revenue', 'delivery', 'growth', 'web', 'documents'],
        'education' => ['revenue', 'delivery', 'growth', 'web', 'documents'],
        'industrial' => ['revenue', 'catalogue', 'operations', 'intelligence', 'documents'],
        'rental' => ['revenue', 'delivery', 'catalogue', 'operations', 'web', 'documents'],
        'other' => ['revenue', 'catalogue', 'growth', 'web', 'intelligence', 'documents'],
    ];

    /** Pillars every category gets, whatever it sells. */
    private const ALWAYS_ON = ['work', 'cx', 'finance', 'people', 'platform'];

    /**
     * Modules that are never on by default, whatever their pillar.
     *
     * Pillar-level defaults alone put roughly eighty of eighty-six modules in
     * every new workspace, which is not a starting point — it is the whole
     * catalogue with extra steps, and it buries the ten screens somebody
     * actually opens. These are the advanced or specialist ones: real, but
     * wanted by a minority, and better found deliberately than met on day one.
     *
     * @var list<string>
     */
    private const NEVER_DEFAULT = [
        'platform.partners',        // only businesses with shared ownership
        'platform.automations',
        'platform.localization',
        'finance.budgets',
        'finance.assets',
        'finance.tax',              // meaningless until a country pack is chosen
        'people.commissions',
        'people.recruitment',
        'people.performance',
        'people.training',
        'documents.esign',
        'documents.templates',
        'intelligence.forecasting',
        'intelligence.alerts',
        'cx.feedback',
        'cx.knowledge',
        'growth.referrals',
        'growth.forms',
        'revenue.contracts',
        'revenue.subscriptions',
        'catalogue.batches',
    ];

    /**
     * Where a category disagrees with its own pillars.
     *
     * A pillar is the right unit for a default until it isn't: Revenue belongs
     * to a consultancy, but Point of Sale and Courier inside it plainly do not.
     * These are the corrections, and they are worth stating one by one because
     * each is a real judgement about how that trade works.
     *
     * @var array<string, array<string, bool>>
     */
    private const EXCEPTIONS = [
        'retail' => [
            // Booking Pages publishes the appointment diary, and a shop that
            // does not take appointments has no diary to publish — the Delivery
            // pillar, where Bookings lives, is off for this category. Leaving
            // it on produced the one unmet dependency in the whole matrix.
            'web.booking_pages' => false,
        ],
        'hospitality' => [
            // Serves and delivers; does not run projects or visit sites.
            'delivery.projects' => false, 'delivery.timesheets' => false,
            'delivery.field' => false, 'delivery.jobs' => false, 'delivery.slas' => false,
        ],
        'professional' => [
            // Sells hours, never stock — so no till, no parcels, no returns.
            'revenue.pos' => false, 'revenue.courier' => false, 'revenue.returns' => false,
            'delivery.field' => false, 'delivery.jobs' => false,
            'catalogue.services' => true,   // the thing it actually sells
            'revenue.contracts' => true, 'documents.esign' => true,
            // The Web pillar is off for this category, which left a
            // consultancy with no way for a client to see their own documents
            // or settle an invoice. These two are the parts of it that a firm
            // selling hours genuinely needs; a shopfront is not.
            'web.portal' => true, 'web.payment_links' => true,
        ],
        'field' => [
            // Fixes and installs; does not manufacture.
            'operations.production' => false, 'operations.bom' => false,
            'operations.quality' => false,
            'catalogue.services' => true,
            // Goes to the customer; the customer never comes to a counter.
            // A plumber has no till, ships no parcels, and takes no parcel
            // returns — a job that goes wrong is a revisit, not an RTO.
            'revenue.pos' => false, 'revenue.courier' => false, 'revenue.returns' => false,
            // Sells labour on site, not goods online. The public presence
            // that matters is a booking page, which stays on.
            'web.store' => false,
        ],
        'wellness' => [
            'revenue.courier' => false, 'revenue.returns' => false,
            'delivery.projects' => false, 'delivery.timesheets' => false,
            'delivery.field' => false, 'delivery.jobs' => false,
            'catalogue.products' => true,   // salons and clinics do retail too
            'catalogue.services' => true,
        ],
        'education' => [
            'revenue.courier' => false, 'revenue.returns' => false,
            'delivery.field' => false, 'delivery.jobs' => false,
            'catalogue.services' => true,
            'revenue.subscriptions' => true,   // recurring fees are the model
            // Fees are invoiced against an enrolment, not rung up at a
            // counter, and a school sells courses rather than shipping goods.
            'revenue.pos' => false, 'web.store' => false,
        ],
        'industrial' => [
            'revenue.pos' => false,
            'catalogue.batches' => true,       // lot traceability matters here
            // Same gap as Professional: no Web pillar meant a wholesaler had
            // nowhere for a trade buyer to check an order or pay against
            // terms. The B2B half of the pillar, without the retail shopfront.
            'web.portal' => true, 'web.payment_links' => true,
        ],
        'rental' => [
            'operations.production' => false, 'operations.bom' => false,
            'operations.quality' => false,
            'revenue.contracts' => true,       // a rental is a contract
            // Point of Sale stays: a hire counter takes payment like any
            // other. Courier and Returns do not — an asset coming back is the
            // end of a booking, tracked there, not a parcel RTO.
            'revenue.courier' => false, 'revenue.returns' => false,
            // Lists availability to book, rather than goods to buy.
            'web.store' => false,
        ],
        'other' => [
            // Same unmet dependency as Retail, for the same reason: this is a
            // broad starting set with no Delivery pillar, so there is no
            // appointment diary for Booking Pages to publish.
            'web.booking_pages' => false,
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $pillars = $this->seedPillars();
            $modules = $this->seedModules($pillars);
            $categories = $this->seedCategories();

            $this->seedPresets($categories, $modules);
        });

        // Outside the transaction, and last. Every sidebar is keyed on a
        // fingerprint of this catalogue, so a reseed that did not drop these
        // would leave menus rendering yesterday's product until the hour ran
        // out — the classic "I deployed it but nobody can see it".
        ModuleAccess::forgetCatalogue();
    }

    /** @return array<string, int> pillar key => id */
    private function seedPillars(): array
    {
        $ids = [];

        foreach (self::PILLARS as $order => $pillar) {
            $ids[$pillar['key']] = ModulePillar::updateOrCreate(
                ['key' => $pillar['key']],
                [
                    'label' => $pillar['label'],
                    'icon' => $pillar['icon'],
                    'sort_order' => $order * 10,
                ],
            )->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $pillars
     * @return array<string, int> module key => id
     */
    private function seedModules(array $pillars): array
    {
        $ids = [];

        foreach (self::MODULES as $pillarKey => $modules) {
            foreach ($modules as $order => $module) {
                $ids[$module['key']] = Module::updateOrCreate(
                    ['key' => $module['key']],
                    [
                        'pillar_id' => $pillars[$pillarKey],
                        'label' => $module['label'],
                        'icon' => $module['icon'],
                        'summary' => $module['summary'] ?? null,
                        'path' => $module['path'] ?? null,
                        'matches' => $module['matches'] ?? null,
                        'capability' => $module['can'] ?? null,
                        'is_core' => $module['core'] ?? false,
                        'is_built' => $module['built'] ?? false,
                        'requires' => $module['requires'] ?? null,
                        'sort_order' => $order * 10,
                    ],
                )->id;
            }
        }

        return $ids;
    }

    /** @return array<string, BusinessCategory> */
    private function seedCategories(): array
    {
        $all = [];

        foreach (self::CATEGORIES as $order => $category) {
            $parent = BusinessCategory::updateOrCreate(
                ['key' => $category['key']],
                [
                    'parent_id' => null,
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'summary' => $category['summary'],
                    'is_active' => true,
                    'sort_order' => $order * 10,
                ],
            );

            $all[$category['key']] = $parent;

            foreach ($category['children'] as $childOrder => $child) {
                $all[$child['key']] = BusinessCategory::updateOrCreate(
                    ['key' => $child['key']],
                    [
                        'parent_id' => $parent->id,
                        'name' => $child['name'],
                        'is_active' => true,
                        'sort_order' => $childOrder * 10,
                    ],
                );
            }
        }

        return $all;
    }

    /**
     * Expand the per-pillar defaults into one row per module.
     *
     * Every module gets a row, including the ones set false — a module absent
     * from this table was never considered for the category, while one present
     * and false was considered and declined, and only the second should be
     * offered prominently when somebody goes looking for more.
     *
     * @param  array<string, BusinessCategory>  $categories
     * @param  array<string, int>  $modules
     */
    private function seedPresets(array $categories, array $modules): void
    {
        $modulePillar = [];

        foreach (self::MODULES as $pillarKey => $entries) {
            foreach ($entries as $entry) {
                $modulePillar[$entry['key']] = $pillarKey;
            }
        }

        foreach (self::PRESETS as $categoryKey => $pillars) {
            $category = $categories[$categoryKey] ?? null;

            if ($category === null) {
                continue;
            }

            $onPillars = array_flip([...$pillars, ...self::ALWAYS_ON]);
            $neverDefault = array_flip(self::NEVER_DEFAULT);
            $exceptions = self::EXCEPTIONS[$categoryKey] ?? [];
            $rows = [];

            foreach ($modules as $moduleKey => $moduleId) {
                // Three passes, narrowest last: the pillar decides, the
                // never-default list trims, and the category's own exceptions
                // overrule both — including turning something back on.
                $enabled = isset($onPillars[$modulePillar[$moduleKey]]);

                if (isset($neverDefault[$moduleKey])) {
                    $enabled = false;
                }

                if (array_key_exists($moduleKey, $exceptions)) {
                    $enabled = $exceptions[$moduleKey];
                }

                $rows[$moduleId] = ['enabled_by_default' => $enabled];
            }

            $category->modules()->sync($rows);
        }
    }
}
