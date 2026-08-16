import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

/**
 * Partners — who owns this business, and what each is owed.
 *
 * The register is deliberately balance-first. The question an owner opens this
 * screen to answer is almost never "what is Jane's phone number"; it is "what
 * has Jane put in, what has she taken out, and what do we owe her". So the
 * three ledger balances are the row, and the contact details sit behind it.
 */

type PartnerBalances = {
    capital_minor: number;
    current_minor: number;
    owed_minor: number;
};

type Partner = {
    id: string;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    joined_on: string | null;
    profit_share_percent: number | null;
    is_verified: boolean;
    can_transact: boolean;
    verified_at: string | null;
    has_capital_account: boolean;
    currency: string;
    balances: PartnerBalances;
};

type PartnersResponse = {
    data: Partner[];
    summary: {
        total: number;
        verified: number;
        currency: string;
        share_allocated_percent: number;
        share_unallocated_percent: number;
    };
};

function money(minor: number, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
    }).format(minor / 100);
}

export default function Partners() {
    useDocumentTitle('Partners');

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const { data, isPending, isError, refetch } = useQuery({
        queryKey: ['partners', { search, status }],
        queryFn: ({ signal }) =>
            api.get<PartnersResponse>('/partners', {
                params: { search: search || undefined, status: status || undefined },
                signal,
            }),
        placeholderData: (previous) => previous,
    });

    const partners = data?.data ?? [];
    const summary = data?.summary;
    const currency = summary?.currency ?? 'BDT';

    return (
        <div className="mx-auto max-w-[1400px]">
            <PageHeader
                title="Partners"
                eyebrow="Ownership"
                icon="handshake"
                description="Who owns this business, what they have put in, and what they are owed."
            />

            {/* The share total is the one number worth surfacing above the list:
                a book that does not add to 100% will distribute profit wrongly,
                and the owner should see that before a run rather than after. */}
            {summary && summary.total > 0 && (
                <div className="mb-4 grid gap-3 sm:grid-cols-3">
                    <div className="card p-4">
                        <p className="text-sm text-[var(--color-text-muted)]">Partners</p>
                        <p className="mt-1 text-2xl font-bold">{summary.total}</p>
                        <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                            {summary.verified} verified
                        </p>
                    </div>
                    <div className="card p-4">
                        <p className="text-sm text-[var(--color-text-muted)]">Share allocated</p>
                        <p className="mt-1 text-2xl font-bold">
                            {summary.share_allocated_percent}%
                        </p>
                    </div>
                    <div className="card p-4">
                        <p className="text-sm text-[var(--color-text-muted)]">Unallocated</p>
                        <p
                            className={`mt-1 text-2xl font-bold ${
                                Math.abs(summary.share_unallocated_percent) > 0.001
                                    ? 'text-[var(--color-warning)]'
                                    : ''
                            }`}
                        >
                            {summary.share_unallocated_percent}%
                        </p>
                        {Math.abs(summary.share_unallocated_percent) > 0.001 && (
                            <p className="mt-1 text-xs text-[var(--color-warning)]">
                                Shares do not add to 100%
                            </p>
                        )}
                    </div>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search partners"
                    className="input max-w-xs"
                />
                <select
                    value={status}
                    onChange={(e) => setStatus(e.target.value)}
                    className="input max-w-[12rem]"
                >
                    <option value="">All partners</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="verified">Verified</option>
                    <option value="unverified">Not verified</option>
                </select>
            </div>

            {isError && (
                <div className="card p-6 text-center">
                    <p className="text-sm">The partner register could not be loaded.</p>
                    <button type="button" onClick={() => void refetch()} className="btn btn-secondary mt-4">
                        Try again
                    </button>
                </div>
            )}

            <div className="card overflow-x-auto">
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-[var(--color-border-light)] text-left">
                            <th className="px-4 py-3 text-sm font-semibold">Partner</th>
                            <th className="px-4 py-3 text-sm font-semibold">Share</th>
                            <th className="px-4 py-3 text-right text-sm font-semibold">Capital</th>
                            <th className="px-4 py-3 text-right text-sm font-semibold">Current</th>
                            <th className="px-4 py-3 text-right text-sm font-semibold">Owed to them</th>
                            <th className="px-4 py-3 text-sm font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {isPending ? (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-[var(--color-text-muted)]">
                                    Loading…
                                </td>
                            </tr>
                        ) : partners.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center">
                                    <p className="font-medium">No partners yet</p>
                                    <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                        If this business is owned by more than one person, add them
                                        here to track capital, drawings and profit share.
                                    </p>
                                </td>
                            </tr>
                        ) : (
                            partners.map((p) => (
                                <tr
                                    key={p.id}
                                    className="border-b border-[var(--color-border-light)] last:border-0 hover:bg-[var(--color-site-bg)]"
                                >
                                    <td className="px-4 py-3">
                                        <p className="font-medium">{p.name}</p>
                                        <p className="text-xs text-[var(--color-text-muted)]">
                                            {p.code}
                                            {p.email ? ` · ${p.email}` : ''}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3 text-sm">
                                        {p.profit_share_percent === null
                                            ? <span className="text-[var(--color-text-muted)]">By capital</span>
                                            : `${p.profit_share_percent}%`}
                                    </td>
                                    <td className="px-4 py-3 text-right text-sm font-medium">
                                        {p.has_capital_account
                                            ? money(p.balances.capital_minor, currency)
                                            : <span className="text-[var(--color-text-muted)]">—</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right text-sm font-medium">
                                        {p.has_capital_account
                                            ? money(p.balances.current_minor, currency)
                                            : <span className="text-[var(--color-text-muted)]">—</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right text-sm font-medium">
                                        {money(p.balances.owed_minor, currency)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {!p.is_verified ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-[var(--color-warning-subtle)] px-2.5 py-0.5 text-xs font-semibold text-[var(--color-warning)]">
                                                <Icon name="warning" size={12} />
                                                Not verified
                                            </span>
                                        ) : p.is_active ? (
                                            <span className="inline-flex items-center rounded-full bg-[var(--color-success-subtle)] px-2.5 py-0.5 text-xs font-semibold text-[var(--color-success)]">
                                                Active
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-full bg-[var(--color-border-light)] px-2.5 py-0.5 text-xs font-semibold text-[var(--color-text-muted)]">
                                                Inactive
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Stated rather than left to be discovered: an unverified partner
                can be filed but cannot hold capital, draw, or take profit. */}
            {partners.some((p) => !p.is_verified) && (
                <p className="mt-3 text-sm text-[var(--color-text-muted)]">
                    A partner must have their identity verified before they can hold capital, take
                    drawings, or receive a profit share.
                </p>
            )}
        </div>
    );
}
