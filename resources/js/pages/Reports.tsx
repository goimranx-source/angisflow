import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useSession } from '@/providers/SessionProvider';

// ── Types ─────────────────────────────────────────────────────────────────────

type Money = { minor: number; currency: string; formatted: string };

type ProfitAndLoss = {
    from: string; to: string; currency: string;
    revenue: { lines: Line[]; total: Money };
    deductions: { lines: Line[]; total: Money };
    net_revenue: Money;
    cost_of_sales: { lines: Line[]; total: Money };
    gross_profit: Money;
    gross_margin_pct: number | null;
    operating_expenses: { lines: Line[]; total: Money };
    operating_profit: Money;
    net_margin_pct: number | null;
};

type BalanceSheet = {
    as_at: string; currency: string;
    assets: { lines: Line[]; total: Money };
    liabilities: { lines: Line[]; total: Money };
    equity: { lines: Line[]; total: Money };
    current_earnings: Money;
    total_equity: Money;
    balances: boolean;
};

type TrialBalance = {
    rows: TbRow[]; debit: Money; credit: Money; balanced: boolean;
    from: string | null; to: string | null;
};

type Line = { code: string; name: string; subtype: string | null; amount: Money };
type TbRow = { code: string; name: string; type: string; debit: Money; credit: Money; balance: Money; balance_side: string };

// ── Helpers ───────────────────────────────────────────────────────────────────

const TABS = ['pl', 'bs', 'tb'] as const;
type Tab = typeof TABS[number];

const TAB_LABELS: Record<Tab, string> = {
    pl: 'Profit & Loss',
    bs: 'Balance Sheet',
    tb: 'Trial Balance',
};

function fmt(m: Money) {
    return m.formatted ?? `${m.currency} ${(m.minor / 100).toFixed(2)}`;
}

function pct(v: number | null) {
    if (v === null) return '—';
    return `${v > 0 ? '+' : ''}${v}%`;
}

function SectionLines({ lines }: { lines: Line[] }) {
    if (lines.length === 0) return <p className="py-2 text-sm text-[var(--color-text-muted)]">No activity.</p>;
    return (
        <div className="divide-y divide-[var(--color-border-subtle)]">
            {lines.map((l) => (
                <div key={l.code} className="flex items-center justify-between py-2 text-sm">
                    <span className="text-[var(--color-text-body)]">
                        <span className="mr-2 text-[var(--color-text-muted)]">{l.code}</span>
                        {l.name}
                    </span>
                    <span className="font-medium tabular-nums text-[var(--color-text-main)]">{fmt(l.amount)}</span>
                </div>
            ))}
        </div>
    );
}

function Subtotal({ label, value, highlight = false }: { label: string; value: Money; highlight?: boolean }) {
    return (
        <div className={cn(
            'flex items-center justify-between border-t border-[var(--color-border)] py-2.5 text-sm font-semibold',
            highlight && 'text-[var(--color-text-main)]',
        )}>
            <span>{label}</span>
            <span className="tabular-nums">{fmt(value)}</span>
        </div>
    );
}

// ── P&L ───────────────────────────────────────────────────────────────────────

function ProfitLossReport({ from, to }: { from: string; to: string }) {
    const { data, isPending, isError } = useQuery({
        queryKey: ['reports', 'pl', from, to],
        queryFn: ({ signal }) => api.get<{ data: ProfitAndLoss }>('/reports/profit-and-loss', { params: { from, to }, signal }),
    });

    if (isPending) return <ReportSkeleton />;
    if (isError) return <ReportError />;

    const pl = data.data;

    return (
        <div className="space-y-6">
            <ReportSection title="Revenue">
                <SectionLines lines={pl.revenue.lines} />
                {pl.deductions.lines.length > 0 && (
                    <>
                        <p className="mt-3 text-xs font-medium uppercase tracking-wide text-[var(--color-text-muted)]">Deductions</p>
                        <SectionLines lines={pl.deductions.lines} />
                    </>
                )}
                <Subtotal label="Net revenue" value={pl.net_revenue} />
            </ReportSection>

            <ReportSection title="Cost of Sales">
                <SectionLines lines={pl.cost_of_sales.lines} />
                <Subtotal label={`Gross profit${pl.gross_margin_pct !== null ? ` (${pct(pl.gross_margin_pct)} margin)` : ''}`} value={pl.gross_profit} highlight />
            </ReportSection>

            <ReportSection title="Operating Expenses">
                <SectionLines lines={pl.operating_expenses.lines} />
                <Subtotal label={`Operating profit${pl.net_margin_pct !== null ? ` (${pct(pl.net_margin_pct)} margin)` : ''}`} value={pl.operating_profit} highlight />
            </ReportSection>
        </div>
    );
}

// ── Balance Sheet ─────────────────────────────────────────────────────────────

function BalanceSheetReport({ asAt }: { asAt: string }) {
    const { data, isPending, isError } = useQuery({
        queryKey: ['reports', 'bs', asAt],
        queryFn: ({ signal }) => api.get<{ data: BalanceSheet }>('/reports/balance-sheet', { params: { as_at: asAt }, signal }),
    });

    if (isPending) return <ReportSkeleton />;
    if (isError) return <ReportError />;

    const bs = data.data;

    return (
        <div className="space-y-6">
            {!bs.balances && (
                <div className="rounded-lg border border-[var(--color-danger)] bg-[var(--color-danger-subtle)] px-4 py-3 text-sm text-[var(--color-danger-text)]">
                    The books do not balance — assets and liabilities + equity differ. Something was posted outside the ledger service.
                </div>
            )}

            <ReportSection title="Assets">
                <SectionLines lines={bs.assets.lines} />
                <Subtotal label="Total assets" value={bs.assets.total} highlight />
            </ReportSection>

            <ReportSection title="Liabilities">
                <SectionLines lines={bs.liabilities.lines} />
                <Subtotal label="Total liabilities" value={bs.liabilities.total} />
            </ReportSection>

            <ReportSection title="Equity">
                <SectionLines lines={bs.equity.lines} />
                <div className="flex items-center justify-between py-2 text-sm">
                    <span className="text-[var(--color-text-body)]">Current year earnings</span>
                    <span className="font-medium tabular-nums">{fmt(bs.current_earnings)}</span>
                </div>
                <Subtotal label="Total equity" value={bs.total_equity} highlight />
            </ReportSection>
        </div>
    );
}

// ── Trial Balance ─────────────────────────────────────────────────────────────

function TrialBalanceReport({ from, to }: { from: string; to: string }) {
    const { data, isPending, isError } = useQuery({
        queryKey: ['reports', 'tb', from, to],
        queryFn: ({ signal }) => api.get<{ data: TrialBalance }>('/reports/trial-balance', { params: { from, to }, signal }),
    });

    if (isPending) return <ReportSkeleton />;
    if (isError) return <ReportError />;

    const tb = data.data;

    return (
        <div className="card overflow-hidden">
            {!tb.balanced && (
                <div className="border-b border-[var(--color-danger)] bg-[var(--color-danger-subtle)] px-4 py-3 text-sm text-[var(--color-danger-text)]">
                    Debits and credits do not agree — the ledger has been written to outside Ledger::post().
                </div>
            )}
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-[var(--color-border)] text-left text-xs font-medium uppercase tracking-wide text-[var(--color-text-muted)]">
                        <th className="px-4 py-3">Account</th>
                        <th className="px-4 py-3 text-right">Debit</th>
                        <th className="px-4 py-3 text-right">Credit</th>
                        <th className="px-4 py-3 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-border-subtle)]">
                    {tb.rows.map((row) => (
                        <tr key={row.code} className="hover:bg-[var(--color-surface-raised)]">
                            <td className="px-4 py-2.5">
                                <span className="mr-2 text-[var(--color-text-muted)]">{row.code}</span>
                                {row.name}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">{fmt(row.debit)}</td>
                            <td className="px-4 py-2.5 text-right tabular-nums">{fmt(row.credit)}</td>
                            <td className="px-4 py-2.5 text-right tabular-nums font-medium">
                                {fmt(row.balance)}
                                <span className="ml-1 text-xs text-[var(--color-text-muted)]">{row.balance_side === 'debit' ? 'Dr' : 'Cr'}</span>
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t-2 border-[var(--color-border)] font-semibold">
                        <td className="px-4 py-3">Totals</td>
                        <td className="px-4 py-3 text-right tabular-nums">{fmt(tb.debit)}</td>
                        <td className="px-4 py-3 text-right tabular-nums">{fmt(tb.credit)}</td>
                        <td className="px-4 py-3 text-right">
                            {tb.balanced
                                ? <span className="text-[var(--color-success)]">Balanced</span>
                                : <span className="text-[var(--color-danger-text)]">Out of balance</span>}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

// ── Shared ────────────────────────────────────────────────────────────────────

function ReportSection({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="card p-5">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">{title}</h3>
            {children}
        </div>
    );
}

function ReportSkeleton() {
    return (
        <div className="space-y-4">
            {[1, 2, 3].map((i) => (
                <div key={i} className="card h-32 animate-pulse bg-[var(--color-surface-raised)]" />
            ))}
        </div>
    );
}

function ReportError() {
    return (
        <div className="card p-8 text-center text-sm text-[var(--color-text-muted)]">
            The report could not be loaded. Open a business first, or check that the ledger has entries.
        </div>
    );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function Reports() {
    const { tenant } = useSession();
    const [tab, setTab] = useState<Tab>('pl');

    useDocumentTitle('Reports');

    const today = new Date().toISOString().slice(0, 10);
    const monthStart = today.slice(0, 8) + '01';

    const noBusiness = tenant?.business === null;

    return (
        <div className="mx-auto max-w-4xl">
            <PageHeader
                title="Reports"
                description={tenant?.business?.name ?? 'Financial statements'}
                actions={
                    <div className="flex gap-1">
                        {TABS.map((t) => (
                            <button
                                key={t}
                                type="button"
                                onClick={() => setTab(t)}
                                className={cn(
                                    'rounded-lg px-3 py-1.5 text-[0.8125rem] font-medium transition-colors',
                                    t === tab
                                        ? 'bg-[var(--color-brand)] text-[var(--color-text-on-accent)]'
                                        : 'text-[var(--color-text-muted)] hover:bg-[var(--color-brand-subtle)]',
                                )}
                            >
                                {TAB_LABELS[t]}
                            </button>
                        ))}
                    </div>
                }
            />

            <div className="mt-6">
                {noBusiness ? (
                    <div className="card p-8 text-center">
                        <Icon name="chart-line" size={28} className="mx-auto text-[var(--color-text-subtle)]" />
                        <p className="mt-3 font-semibold text-[var(--color-text-main)]">Open a business to see its reports</p>
                        <p className="mt-1 text-sm text-[var(--color-text-muted)]">Reports are per set of books. Select a business from the header.</p>
                    </div>
                ) : (
                    <>
                        {tab === 'pl' && <ProfitLossReport from={monthStart} to={today} />}
                        {tab === 'bs' && <BalanceSheetReport asAt={today} />}
                        {tab === 'tb' && <TrialBalanceReport from={monthStart} to={today} />}
                    </>
                )}
            </div>
        </div>
    );
}
