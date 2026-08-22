import { lazy, Suspense, type ComponentType, type ReactNode } from 'react';
import { createBrowserRouter, Navigate, Outlet, useLocation } from 'react-router';

import { AppLayout } from '@/layouts/AppLayout';
import { useSession } from '@/providers/SessionProvider';

/**
 * The route table.
 *
 * ── Every page is its own chunk ──────────────────────────────────────────────
 *
 * `lazy()` around each import means Vite emits one file per screen, fetched the
 * first time it is opened and cached by the browser after that. Somebody who
 * only ever opens Orders never downloads Payroll, Reports or the layout
 * builder — which on the connections most of this tool's users are on is the
 * difference between a second and eight.
 *
 * ── And can be fetched before it is needed ───────────────────────────────────
 *
 * The same import functions are exported below so the sidebar can call one on
 * hover. The gap between a pointer resting on a menu item and the click that
 * follows is a few hundred milliseconds — comfortably longer than fetching a
 * two-kilobyte chunk — so by the time the click lands, the screen is already in
 * memory and renders on the next frame with no network at all.
 */

const pages = {
    dashboard: () => import('@/pages/Dashboard'),
    categoryDashboard: () => import('@/pages/CategoryDashboard'),
    profile: () => import('@/pages/Profile'),
    billing: () => import('@/pages/Billing'),
    settings: () => import('@/pages/settings/Settings'),
    roadmap: () => import('@/pages/Roadmap'),
    home: () => import('@/pages/Home'),
    workspaces: () => import('@/pages/Workspaces'),
    businesses: () => import('@/pages/Businesses'),
    inbox: () => import('@/pages/Inbox'),
    module: () => import('@/pages/Module'),
    landing: () => import('@/pages/Landing'),
    reports: () => import('@/pages/Reports'),
    accounts: () => import('@/pages/Accounts'),
    transactions: () => import('@/pages/Transactions'),
    fiscalYears: () => import('@/pages/FiscalYears'),
    journal: () => import('@/pages/Journal'),
    credentials: () => import('@/pages/Credentials'),
    operator: () => import('@/pages/Operator'),
    customers: () => import('@/pages/Customers'),
    partners: () => import('@/pages/Partners'),
    orders: () => import('@/pages/Orders'),
    products: () => import('@/pages/Products'),
    stock: () => import('@/pages/Stock'),
    pointOfSale: () => import('@/pages/PointOfSale'),
    invoicing: () => import('@/pages/Invoicing'),
    payments: () => import('@/pages/Payments'),
    offersCoupons: () => import('@/pages/OffersCoupons'),
    storefronts: () => import('@/pages/Storefronts'),
    warehouses: () => import('@/pages/Warehouses'),
    returns: () => import('@/pages/Returns'),
    courier: () => import('@/pages/Courier'),
    campaigns: () => import('@/pages/Campaigns'),
    loyalty: () => import('@/pages/Loyalty'),
    reviews: () => import('@/pages/Reviews'),
    employees: () => import('@/pages/Employees'),
    attendance: () => import('@/pages/Attendance'),
    ledgers: () => import('@/pages/Ledgers'),
    liveChat: () => import('@/pages/LiveChat'),
    channels: () => import('@/pages/Channels'),
    messageTemplates: () => import('@/pages/MessageTemplates'),
    automations: () => import('@/pages/Automations'),
    usersRoles: () => import('@/pages/UsersRoles'),
    documents: () => import('@/pages/Documents'),
    auditLog: () => import('@/pages/AuditLog'),
    login: () => import('@/pages/auth/Login'),
    register: () => import('@/pages/auth/Register'),
    forgotPassword: () => import('@/pages/auth/ForgotPassword'),
    resetPassword: () => import('@/pages/auth/ResetPassword'),
    twoFactor: () => import('@/pages/auth/TwoFactor'),
    confirmPassword: () => import('@/pages/auth/ConfirmPassword'),
    notFound: () => import('@/pages/NotFound'),
} as const;

export type PageKey = keyof typeof pages;

/** Warm a page's chunk before the user asks for it. */
export function prefetchPage(key: PageKey): void {
    void pages[key]().catch(() => {
        // A prefetch that fails is a prefetch, not an error — the real
        // navigation will try again and report properly if it matters.
    });
}

/** Which page a URL belongs to, for prefetching from a plain href. */
export function pageKeyForPath(path: string): PageKey | null {
    if (path.startsWith('/soon/')) {
        return 'module';
    }

    // Every settings tab is one page; the tab is a route parameter.
    if (path.startsWith('/settings')) {
        return 'settings';
    }

    const map: Record<string, PageKey> = {
        '/': 'landing',
        '/home': 'home',
        '/workspaces': 'workspaces',
        '/businesses': 'businesses',
        '/inbox': 'inbox',
        '/dashboard': 'dashboard',
        '/category-dashboard': 'categoryDashboard',
        '/profile': 'profile',
        '/billing': 'billing',
        '/settings': 'settings',
        '/roadmap': 'roadmap',
        '/reports': 'reports',
        '/accounts': 'accounts',
        '/transactions': 'transactions',
        '/journal': 'journal',
        '/fiscal-years': 'fiscalYears',
        '/credentials': 'credentials',
        '/operator': 'operator',
        '/customers': 'customers',
        '/partners': 'partners',
        '/orders': 'orders',
        '/products': 'products',
        '/stock': 'stock',
        '/pos': 'pointOfSale',
        '/invoicing': 'invoicing',
        '/payments': 'payments',
        '/offers': 'offersCoupons',
        '/storefronts': 'storefronts',
        '/warehouses': 'warehouses',
        '/returns': 'returns',
        '/courier': 'courier',
        '/campaigns': 'campaigns',
        '/loyalty': 'loyalty',
        '/reviews': 'reviews',
        '/employees': 'employees',
        '/attendance': 'attendance',
        '/ledgers': 'ledgers',
        '/live-chat': 'liveChat',
        '/channels': 'channels',
        '/message-templates': 'messageTemplates',
        '/automations': 'automations',
        '/users-roles': 'usersRoles',
        '/documents': 'documents',
        '/audit-log': 'auditLog',
    };

    return map[path] ?? null;
}

function page(loader: () => Promise<{ default: ComponentType }>) {
    const Component = lazy(loader);

    return <Component />;
}

/**
 * The gap while a chunk downloads.
 *
 * Deliberately empty rather than a spinner. The shell is already on screen and
 * the chunk usually arrives in a few milliseconds — often zero, because it was
 * prefetched on hover — so a spinner would flash on and off and announce a load
 * the user never experienced. Nothing is the honest rendering of "this is about
 * to be here".
 */
function PageBoundary({ children }: { children: ReactNode }) {
    return <Suspense fallback={null}>{children}</Suspense>;
}

/** Signed in, or sent to sign in — remembering where they were going. */
function RequireAuth() {
    const { auth } = useSession();
    const location = useLocation();

    if (auth === null) {
        return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
    }

    return <Outlet />;
}

/** Signed out only. Somebody already in has no business on the sign-in screen. */
function RequireGuest() {
    const { auth } = useSession();

    if (auth !== null) {
        // Home is now at /home for authenticated users
        return <Navigate to="/home" replace />;
    }

    return <Outlet />;
}

/**
 * Inside the shell.
 *
 * The layout is a route rather than something each page renders, so React keeps
 * it mounted across every navigation underneath it. The sidebar holds its
 * scroll position and its open sections, the header does not blink, and no
 * layout effect in the shell runs twice. Only what is inside <Outlet /> changes.
 */
function Shell() {
    return (
        <AppLayout>
            <PageBoundary>
                <Outlet />
            </PageBoundary>
        </AppLayout>
    );
}

/** Auth screens have no shell — a sidebar for a business you are not in yet. */
function Bare() {
    return (
        <PageBoundary>
            <Outlet />
        </PageBoundary>
    );
}

/**
 * Landing or Home depending on auth status.
 *
 * Guests see the marketing landing page; authenticated users see their home dashboard.
 */
function LandingOrHome() {
    const { auth } = useSession();

    if (auth === null) {
        // Guest - show landing page without shell
        const Component = lazy(pages.landing);
        return (
            <Suspense fallback={null}>
                <Component />
            </Suspense>
        );
    }

    // Authenticated - show home with shell
    return (
        <AppLayout>
            <Suspense fallback={null}>
                {page(pages.home)}
            </Suspense>
        </AppLayout>
    );
}

export const router = createBrowserRouter([
    // Public landing page - accessible without authentication
    {
        path: '/',
        element: (
            <PageBoundary>
                <LandingOrHome />
            </PageBoundary>
        ),
    },
    {
        element: <RequireGuest />,
        children: [
            {
                element: <Bare />,
                children: [
                    { path: '/login', element: page(pages.login) },
                    { path: '/register', element: page(pages.register) },
                    { path: '/forgot-password', element: page(pages.forgotPassword) },
                    { path: '/reset-password/:token', element: page(pages.resetPassword) },
                ],
            },
        ],
    },
    {
        element: <RequireAuth />,
        children: [
            {
                // Signed in but not necessarily past the second factor, so
                // these sit outside the shell and outside its guards. Putting
                // the challenge behind the check that the challenge has been
                // answered is a loop nobody escapes.
                element: <Bare />,
                children: [
                    { path: '/two-factor', element: page(pages.twoFactor) },
                    { path: '/confirm-password', element: page(pages.confirmPassword) },
                ],
            },
            {
                element: <Shell />,
                children: [
                    // Home is the account's own landing, not a business's —
                    // signing in no longer drops you straight into one set of
                    // books, because a subscriber may have several.
                    { path: '/home', element: page(pages.home) },
                    { path: '/workspaces', element: page(pages.workspaces) },
                    { path: '/businesses', element: page(pages.businesses) },
                    { path: '/inbox', element: page(pages.inbox) },

                    { path: '/dashboard', element: page(pages.dashboard) },
                    { path: '/category-dashboard', element: page(pages.categoryDashboard) },
                    { path: '/customers', element: page(pages.customers) },
                    { path: '/partners', element: page(pages.partners) },
                    { path: '/orders', element: page(pages.orders) },
                    { path: '/products', element: page(pages.products) },
                    { path: '/stock', element: page(pages.stock) },
                    { path: '/pos', element: page(pages.pointOfSale) },
                    { path: '/invoicing', element: page(pages.invoicing) },
                    { path: '/payments', element: page(pages.payments) },
                    { path: '/offers', element: page(pages.offersCoupons) },
                    { path: '/storefronts', element: page(pages.storefronts) },
                    { path: '/warehouses', element: page(pages.warehouses) },
                    { path: '/returns', element: page(pages.returns) },
                    { path: '/courier', element: page(pages.courier) },
                    { path: '/campaigns', element: page(pages.campaigns) },
                    { path: '/loyalty', element: page(pages.loyalty) },
                    { path: '/reviews', element: page(pages.reviews) },
                    { path: '/employees', element: page(pages.employees) },
                    { path: '/attendance', element: page(pages.attendance) },
                    { path: '/ledgers', element: page(pages.ledgers) },
                    { path: '/live-chat', element: page(pages.liveChat) },
                    { path: '/channels', element: page(pages.channels) },
                    { path: '/message-templates', element: page(pages.messageTemplates) },
                    { path: '/automations', element: page(pages.automations) },
                    { path: '/users-roles', element: page(pages.usersRoles) },
                    { path: '/documents', element: page(pages.documents) },
                    { path: '/audit-log', element: page(pages.auditLog) },
                    { path: '/transactions', element: page(pages.transactions) },
                    { path: '/journal', element: page(pages.journal) },
                    { path: '/accounts', element: page(pages.accounts) },
                    { path: '/fiscal-years', element: page(pages.fiscalYears) },
                    { path: '/profile', element: page(pages.profile) },
                    { path: '/billing', element: page(pages.billing) },

                    // The tab is part of the address, so a settings tab can be
                    // linked to and the back button returns to the one you came
                    // from — which a client-side tab strip cannot do.
                    { path: '/settings', element: page(pages.settings) },
                    { path: '/settings/:group', element: page(pages.settings) },
                    { path: '/roadmap', element: page(pages.roadmap) },
                    { path: '/reports', element: page(pages.reports) },
                    { path: '/accounts', element: page(pages.accounts) },
                    { path: '/transactions', element: page(pages.transactions) },
                    { path: '/journal', element: page(pages.journal) },
                    { path: '/credentials', element: page(pages.credentials) },
                    { path: '/operator', element: page(pages.operator) },
                    { path: '/soon/:key', element: page(pages.module) },
                ],
            },
        ],
    },
    {
        // Anything else. Rendered bare because an unknown address may well be
        // one the viewer is not signed in for.
        path: '*',
        element: (
            <PageBoundary>
                {page(pages.notFound)}
            </PageBoundary>
        ),
    },
]);
