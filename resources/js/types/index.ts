/**
 * The contract between the API and the client, typed once.
 *
 * Mirrors App\Support\BootPayload and the endpoints under app/Http/Api. Written
 * by hand rather than generated because the payload is small, hand-assembled on
 * the server for good reasons, and a generator is only ever as correct as the
 * last time somebody ran it.
 */

export type Capability = string;

export type AuthUser = {
    id: string;
    name: string;
    email: string;
    avatar: string | null;
    is_owner: boolean;
    timezone: string;
    two_factor_enabled: boolean;
    email_verified: boolean;
    has_password: boolean;
};

export type Auth = {
    user: AuthUser;
    capabilities: Capability[];
};

export type AccountStatus = 'trialing' | 'active' | 'past_due' | 'suspended' | 'cancelled';

export type AccountSummary = {
    id: string;
    name: string;
    status: AccountStatus;
    currency: string;
    trial_ends_at: string | null;
    usable: boolean;
    onboarding_completed?: boolean;
    onboarding_steps?: Record<string, boolean>;
};

export type BusinessSummary = {
    id: string;
    /** Which workspace holds it, so the sidebar's business select can narrow
     *  to the chosen workspace without another request. */
    workspace: string | null;
    name: string;
    short_code: string | null;
    currency: string;
    logo_url?: string | null;
    business_category_id?: number | null;
    categories?: Array<{
        id: number;
        name: string;
        key: string;
        icon: string | null;
    }>;
    country?: string | null;
    timezone?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
};

export type WorkspaceSummary = {
    id: string;
    name: string;
    slug: string;
    icon?: string | null;
};

/** What the plan still allows, so a create button can be drawn honestly
 *  rather than offered and then refused. A null `limit` means unlimited —
 *  never a sentinel number, which renders as "3 of -1" the moment somebody
 *  forgets to special-case it. */
export type PlanQuota = { used: number; limit: number | null; can_add: boolean };

export type Allowance = {
    plan: string | null;
    workspaces: PlanQuota;
    businesses: PlanQuota | null;
};

export type Tenant = {
    account: AccountSummary;
    workspace: WorkspaceSummary | null;
    business: BusinessSummary | null;
    workspaces: WorkspaceSummary[];
    businesses: BusinessSummary[];
    allowance: Allowance;
    /** Per-workspace business allowances, keyed by workspace public_id */
    workspace_allowances: Record<string, PlanQuota | null>;
};

export type NavItem = {
    key: string;
    label: string;
    icon: string;
    summary: string;
    /** False for a department that is mapped out but not yet built. */
    built: boolean;
    href: string;
    /** Path prefixes this item lights up for. See lib/utils#pathMatches. */
    match: string[];
};

export type NavSection = {
    key: string;
    label: string | null;
    icon: string;
    /** A flat section has no parent to fold — its items are the top level. */
    flat: boolean;
    items: NavItem[];
};

/**
 * The payload the HTML document carries and /api/v1/bootstrap returns.
 *
 * One shape, two delivery routes — the inlined copy is what lets the first
 * render draw the whole shell without a request.
 */
/**
 * What the tool calls itself here — the subscriber's own name and marks where
 * they have set them, the platform's where they have not. Resolved to URLs on
 * the server, so the client never has to know how a media id becomes an
 * address.
 */
export type Brand = {
    name: string;
    tagline: string;
    logo: string | null;
    logo_mark: string | null;
    favicon: string | null;
    show_logo: boolean;
};

/** The tool's own mark — ours, set from the admin panel. Distinct from `app`,
 *  which is whatever the subscriber renamed themselves to in Settings. */
export type PlatformBrand = {
    name: string | null;
    tagline: string | null;
    logo: string | null;
    logo_mark: string | null;
};

export type BootPayload = {
    app: Brand;
    platform: PlatformBrand;
    config: {
        registration_enabled: boolean;
        turnstile_site_key: string | null;
        /** Part of the /modules URL, so that response can be cached hard and
         *  still never be stale. See App\Support\Modules::version(). */
        catalogue_version: string;
        /** False when no model key is configured — the palette then leaves the
         *  ask affordance out rather than offering one that cannot answer. */
        assistant_enabled: boolean;
    };
    auth: Auth | null;
    tenant: Tenant | null;
    /** What the shell reports money in. See App\Support\BootPayload::money(). */
    money: Money;
    nav: NavSection[];
    todo_marks: Record<string, string>;
};

export type Money = {
    /** ISO code — 'MYR'. For arithmetic and for Intl. */
    base: string;
    /** What a reader recognises — 'RM'. For headings and labels. */
    symbol: string;
    /** Changes whenever the base or the rates behind it do. Keyed into every
     *  money-bearing query and written into every read's URL, so neither
     *  React Query nor the browser can answer with a figure from the currency
     *  just left. */
    scope: string;
};

export type PlannedModule = {
    key: string;
    label: string;
    icon: string;
    summary?: string;
    why?: string;
    does?: string[];
    group: string;
    group_label: string | null;
};
