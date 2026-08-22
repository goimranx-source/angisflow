import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import {
    CashFlowMini,
    ExpenseBreakdown,
    PendingTasks,
    QuickActions,
    RecentOrders,
    SalesChart,
    TopCustomers,
    TopProducts,
} from '@/components/dashboard';
import { Icon } from '@/components/ui/Icon';
import { SkeletonKpi } from '@/components/ui/Skeleton';
import { StatsCard } from '@/components/ui/StatsCard';
import { DateRangePicker, type DateRange } from '@/components/ui/DateRangePicker';
import { ExportMenu } from '@/components/ui/ExportMenu';
import { useBusinessScope } from '@/hooks/useBusinessScope';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useMoney } from '@/hooks/useMoney';
import { api } from '@/lib/api';
import { formatMoneyShortWith, formatMoneyWith } from '@/lib/money';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';

type Kpi = {
    key: string;
    label: string;
    icon: string;
    value: string;
    raw: number;
    delta: number | null;
    direction: 'up' | 'down' | 'flat';
    rise_is_good: boolean;
};

type CategoryRef = { id: number; key: string; name: string; icon: string | null };

type DashboardPayload = {
    data: {
        period: { key: string; label: string; compare_label: string };
        currency: string;
        kpis: Kpi[];
        /** False while the trading modules are still being built. */
        trading_ready: boolean;
        context: {
            business: { id: string; name: string; currency: string } | null;
            primary_category: CategoryRef | null;
            categories: CategoryRef[];
            /** Module keys this business can actually open — the union
             *  across every category it carries. */
            capabilities: string[];
            /** The same, split back out per category id — what lets the
             *  chips below act as tabs rather than static labels. */
            capabilities_by_category: Record<number, string[]>;
        };
    };
};

/** `2026-08-16`, in the viewer's own timezone rather than UTC — toISOString()
 *  would hand the server yesterday's date for anybody east of Greenwich. */
function toIsoDate(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(
        d.getDate(),
    ).padStart(2, '0')}`;
}

/** Opens on the current month, which is what a dashboard is asked for first. */
function currentMonth(): DateRange {
    const now = new Date();

    return {
        start: new Date(now.getFullYear(), now.getMonth(), 1),
        end: new Date(now.getFullYear(), now.getMonth() + 1, 0),
    };
}

/**
 * The colour each headline figure wears.
 *
 * Money earned is the brand colour, money owed to us is a warning, money going
 * out is a danger — so the tiles mean something rather than being five colours
 * in a row. Anything not named here falls back to brand, which is the right
 * answer for a figure with no particular valence.
 */
const KPI_ACCENT: Record<string, 'brand' | 'success' | 'warning' | 'danger' | 'info'> = {
    revenue: 'brand',
    profit: 'success',
    receivables: 'warning',
    expenses: 'danger',
    businesses: 'brand',
    team: 'info',
    plan: 'success',
};

/** Only the financial KPIs are money — 'businesses', 'team' and 'plan' are a
 *  count and a subscription phrase, and running those through the currency
 *  formatter would print "$3" beside a headcount. */
const MONEY_KPI_KEYS = new Set(['revenue', 'profit', 'receivables']);

/** The KPIs actually measured against a prior period — receivables is a
 *  balance as of today, not a span of time, so "compared with July" would be
 *  a comparison that was never made rather than one this figure happens not
 *  to have. */
const PERIOD_COMPARED_KPI_KEYS = new Set(['revenue', 'profit']);

/**
 * What a headline figure shows, and what it says on hover.
 *
 * Money is compacted — "RM 28.9K" — so a row of cards stays scannable and a
 * business whose revenue grows into the millions does not reflow the layout
 * every month. The exact amount is a hover away rather than gone.
 *
 * A money KPI that is not a number is the server saying it had no rate to
 * convert through (see DashboardEndpoint::moneyKpi, which refuses to convert
 * at par). That is reported as the gap it is, with the fix named, rather than
 * rendered as a confident zero.
 */
function kpiValue(kpi: Kpi, symbol: string, base: string): { shown: string; exact?: string } {
    if (!MONEY_KPI_KEYS.has(kpi.key)) {
        return { shown: kpi.value };
    }

    const amount = Number.parseFloat(kpi.value);

    if (!Number.isFinite(amount)) {
        return {
            shown: 'No rate',
            exact: `No exchange rate into ${base} yet — add one under Settings › Currency.`,
        };
    }

    return {
        shown: formatMoneyShortWith(symbol, amount),
        exact: formatMoneyWith(symbol, amount),
    };
}

/**
 * The Overview.
 *
 * ── What decides the shape of this page ──────────────────────────────────────
 *
 * Not the category. A dashboard written as "if retail, show stock; if
 * hospitality, show covers" has to be edited every time a category is added, and
 * it has nothing sensible to say about the bakery with a café attached — which
 * is one business carrying both, and the ordinary case rather than the odd one.
 *
 * So the panels are gated on what the business can actually open: it gets the
 * orders panels because it has an orders module, and the bookings panels because
 * it has a bookings one. The capability list is the union across all of its
 * categories, worked out by the same code that builds the sidebar (see
 * Navigation::capabilitiesFor), so this screen and the menu can never disagree
 * about what exists. Give a business a second category and both keep up without
 * being told.
 *
 * The category chips act as tabs on top of that: clicking one narrows `can()`
 * down to capabilities_by_category[that id] instead of the union, so a
 * bakery-with-a-café clicking into "Café" stops seeing the bakery's stock
 * panels. "All" — the union, the default — is always the first chip.
 *
 * ── Switching business ───────────────────────────────────────────────────────
 *
 * Every query on this page is keyed by the open business — see useBusinessScope
 * for why clearing the cache on switch is not on its own enough. The header
 * reads the business out of the same payload as the figures rather than out of
 * the session, so the name above the numbers is always the name the numbers came
 * from, including during the frame where one has arrived and the other has not.
 */
export default function Dashboard() {
    const { tenant } = useSession();
    const business = useBusinessScope();
    const { symbol, base, scope: money } = useMoney();
    const queryClient = useQueryClient();
    const [range, setRange] = useState<DateRange>(currentMonth);
    const [activeCategory, setActiveCategory] = useState<number | null>(null);

    useDocumentTitle('Overview');

    const from = toIsoDate(range.start);
    const to = toIsoDate(range.end);

    const { data, isPending, isFetching, isError, refetch } = useQuery({
        queryKey: ['dashboard', 'summary', business, from, to, money],
        queryFn: ({ signal }) =>
            api.get<DashboardPayload>('/dashboard', { params: { from, to }, signal }),
        // Held across a change of period — the figures dim and update in place,
        // which beats the whole page collapsing to skeletons for one dropdown.
        // Dropped across a change of business: keeping it there would leave the
        // company just left on screen, under the name of the one just opened,
        // for as long as the request takes.
        placeholderData: (previous, previousQuery) =>
            previousQuery?.queryKey[2] === business ? previous : undefined,
    });

    const summary = data?.data;
    const context = summary?.context;

    // The union when no chip is picked (or the business carries only one
    // category, in which case a tab strip would have nothing to switch
    // between); the one category's own slice once a chip is clicked.
    const capabilities =
        activeCategory !== null
            ? (context?.capabilities_by_category[activeCategory] ?? context?.capabilities ?? [])
            : (context?.capabilities ?? []);
    const can = (module: string) => capabilities.includes(module);

    // The session's copy is used only until the payload lands, so the heading is
    // never blank on a cold load. Once it has, the payload wins — it is the one
    // that came back with the figures underneath it.
    const businessName = context?.business?.name ?? tenant?.business?.name ?? 'Overview';
    const categories = context?.categories ?? [];
    const primaryKey = context?.primary_category?.key;

    // Every query this page's panels raise shares the 'dashboard' prefix in
    // their key, so one invalidation call catches all of them — the summary,
    // the chart, the cash strip, the four lists — rather than only the
    // summary refetching while the panels beneath it sit stale.
    const refresh = () => {
        void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    };

    return (
        <div className="mx-auto max-w-[1400px]">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="truncate text-2xl font-bold text-[var(--color-text-main)]">
                        {businessName}
                    </h1>

                    {/* Categories only — the date lives in the picker at the
                        top right and saying it twice on one screen is not a
                        second fact, it is the same fact taking two lines. */}
                    {categories.length > 0 && (
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            {/* "All" only earns its place once there is
                                something to switch away from — a single-
                                category business has nothing for a tab strip
                                to do. */}
                            {categories.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() => setActiveCategory(null)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-[var(--shell-radius-sm)] px-2 py-0.5 text-xs font-semibold transition-colors',
                                        activeCategory === null
                                            ? 'bg-[var(--color-brand-subtle)] text-[var(--color-brand-text)]'
                                            : 'bg-[var(--shell-tint)] text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                                    )}
                                >
                                    All
                                </button>
                            )}

                            {categories.map((category) => (
                                <button
                                    key={category.id}
                                    type="button"
                                    onClick={() =>
                                        setActiveCategory((current) =>
                                            current === category.id ? null : category.id,
                                        )
                                    }
                                    title={
                                        categories.length > 1
                                            ? `Show only ${category.name}`
                                            : undefined
                                    }
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-[var(--shell-radius-sm)] px-2 py-0.5 text-xs font-semibold transition-colors',
                                        activeCategory === category.id ||
                                            (activeCategory === null &&
                                                categories.length === 1 &&
                                                category.key === primaryKey)
                                            ? 'bg-[var(--color-brand-subtle)] text-[var(--color-brand-text)]'
                                            : 'bg-[var(--shell-tint)] text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]',
                                    )}
                                >
                                    {category.icon && <Icon name={category.icon} size={13} />}
                                    {category.name}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {/*
                        Both controls do what they say. What stood here first was
                        a button printing today's date twice and an Export that
                        exported nothing; what replaced it was a run of fixed
                        periods, which is quicker for "this month" and cannot
                        express "3 to 17 August" at all. The picker carries both
                        — presets down one side, a calendar down the other.
                    */}
                    <DateRangePicker value={range} onChange={setRange} />

                    <ExportMenu
                        endpoint="/dashboard-export"
                        params={{
                            from: toIsoDate(range.start),
                            to: toIsoDate(range.end),
                        }}
                    />

                    <button
                        type="button"
                        onClick={refresh}
                        disabled={isFetching}
                        className="topbar-icon"
                        aria-label="Refresh figures"
                        title="Refresh figures"
                    >
                        <Icon
                            name="arrows-clockwise"
                            size={16}
                            className={cn(isFetching && 'animate-spin')}
                        />
                    </button>
                </div>
            </div>

            {isError && (
                <div className="card mt-6 p-6 text-center">
                    <p className="text-sm text-[var(--color-text-body)]">
                        Those figures could not be loaded.
                    </p>
                    <button
                        type="button"
                        onClick={() => void refetch()}
                        className="btn btn-secondary mt-4"
                    >
                        Try again
                    </button>
                </div>
            )}

            {/*
                Said plainly rather than drawn as empty charts.

                A dashboard showing a confident zero for revenue reads as a
                business having a terrible month, not as a module that has not
                shipped — and that is a phone call from the owner either way.
            */}
            {summary?.trading_ready === false && (
                <div className="card mt-6 p-8 text-center">
                    <Icon
                        name="chart-line-up"
                        size={28}
                        className="mx-auto text-[var(--color-text-subtle)]"
                    />
                    <p className="mt-3 font-semibold text-[var(--color-text-main)]">
                        Trading figures arrive with the Orders and Ledger modules
                    </p>
                    <p className="mx-auto mt-1 max-w-md text-sm text-[var(--color-text-muted)]">
                        The shell, the API and the figures above are wired end to end. Everything
                        from here is a screen on top of them, not a rebuild.
                    </p>
                </div>
            )}

            {/*
                The reading order of the page.

                Widest thing first — one chart big enough to actually read a
                trend off — with the list that qualifies it beside rather than
                below, because "revenue is up" and "who bought" are one question
                asked twice and scrolling between them loses the connection.

                The figures then sit under the chart rather than above it: they
                are the summary of what has just been shown, and a row of five
                numbers is a poor thing to meet a page with.
            */}
            {summary?.trading_ready && (
                <>
                    {/*
                        The headline figures open the page rather than
                        summarise it. They are the three numbers an owner
                        checks first, on their own the moment they land here —
                        the chart and the lists below are what explain them,
                        not the other way round.
                    */}
                    <div className="mt-5">
                        <div
                            className={cn(
                                'grid gap-3 sm:grid-cols-2 lg:grid-cols-3',
                                isFetching && !isPending && 'opacity-60',
                            )}
                        >
                            {isPending
                                ? Array.from({ length: 3 }, (_, index) => <SkeletonKpi key={index} />)
                                : summary?.kpis.map((kpi) => (
                                      <StatsCard
                                          key={kpi.key}
                                          label={kpi.label}
                                          value={kpiValue(kpi, symbol, base).shown}
                                          valueTitle={kpiValue(kpi, symbol, base).exact}
                                          icon={kpi.icon}
                                          accent={KPI_ACCENT[kpi.key] ?? 'brand'}
                                          delta={kpi.delta}
                                          direction={kpi.direction}
                                          riseIsGood={kpi.rise_is_good}
                                          comparisonHint={
                                              PERIOD_COMPARED_KPI_KEYS.has(kpi.key)
                                                  ? `Compared with ${summary.period.compare_label}.`
                                                  : undefined
                                          }
                                      />
                                  ))}
                        </div>
                    </div>

                    {/*
                        Three fifths to the chart, two to the list beside it.
                        A chart earns width up to the point where the columns
                        are readable and no further; a list of names and
                        figures keeps earning it, because the alternative is
                        truncating the names. Two thirds and one third gave
                        the chart room it was not using and left "Beats
                        Headphones" as "Beats He…".
                    */}
                    <div className="mt-4 grid gap-4 xl:grid-cols-5">
                        {can('revenue.orders') && (
                            <SalesChart from={from} to={to} className="xl:col-span-3" />
                        )}

                        {can('revenue.orders') && (
                            <RecentOrders limit={5} className="xl:col-span-2" />
                        )}
                    </div>

                    <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        {can('finance.invoicing') && (
                            <CashFlowMini from={from} to={to} className="xl:col-span-3" />
                        )}
                        {can('finance.invoicing') && (
                            <ExpenseBreakdown from={from} to={to} className="xl:col-span-2" />
                        )}
                    </div>

                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        {can('catalogue.products') && <TopProducts limit={5} />}
                        {can('revenue.customers') && <TopCustomers limit={5} />}
                        <PendingTasks />
                    </div>

                    <div className="mt-4">
                        <QuickActions />
                    </div>
                </>
            )}
        </div>
    );
}
