import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type AccountRow = {
    id: string;
    name: string;
    slug: string;
    status: string;
    plan: string | null;
    created_at: string | null;
};

type Override = {
    module_key: string;
    kind: 'grant' | 'revoke';
    reason: string;
    expires_at: string | null;
    granted_by: string | null;
    created_at: string | null;
};

type WorkspaceDetail = {
    id: string;
    name: string;
    is_active: boolean;
    overrides: Override[];
};

type AccountDetail = {
    id: string;
    name: string;
    slug: string;
    status: string;
    country: string | null;
    base_currency: string;
    trial_ends_at: string | null;
    suspended_at: string | null;
    suspended_reason: string | null;
    created_at: string | null;
    user_count: number;
    subscription: {
        status: string;
        plan_code: string | null;
        plan_name: string | null;
        price_minor: number | null;
        currency: string | null;
        interval: string | null;
        trial_ends_at: string | null;
        current_period_end: string | null;
    } | null;
    workspaces: WorkspaceDetail[];
};

const STATUS_COLORS: Record<string, string> = {
    trialing:  'bg-blue-100 text-blue-700',
    active:    'bg-green-100 text-green-700',
    past_due:  'bg-amber-100 text-amber-700',
    suspended: 'bg-red-100 text-red-700',
    cancelled: 'bg-gray-100 text-gray-500',
};

function StatusBadge({ status }: { status: string }) {
    return (
        <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium', STATUS_COLORS[status] ?? 'bg-gray-100 text-gray-500')}>
            {status.replace('_', ' ')}
        </span>
    );
}

function AccountDetailPanel({ id, onClose }: { id: string; onClose: () => void }) {
    const qc = useQueryClient();
    const [suspendReason, setSuspendReason] = useState('');
    const [trialDays, setTrialDays] = useState('14');
    const [planCode, setPlanCode] = useState('');
    const [grantKey, setGrantKey] = useState('');
    const [grantReason, setGrantReason] = useState('');
    const [activeWorkspace, setActiveWorkspace] = useState<string | null>(null);

    const { data, isPending } = useQuery({
        queryKey: ['operator', 'accounts', id],
        queryFn: ({ signal }) => api.get<{ data: AccountDetail }>(`/operator/accounts/${id}`, { signal }),
    });

    const { data: plansData } = useQuery({
        queryKey: ['operator', 'plans'],
        queryFn: ({ signal }) => api.get<{ data: { code: string; name: string }[] }>('/operator/plans', { signal }),
    });

    const invalidate = () => {
        qc.invalidateQueries({ queryKey: ['operator', 'accounts', id] });
        qc.invalidateQueries({ queryKey: ['operator', 'accounts'] });
    };

    const suspend = useMutation({
        mutationFn: () => api.post(`/operator/accounts/${id}/suspend`, { reason: suspendReason }),
        onSuccess: invalidate,
    });

    const unsuspend = useMutation({
        mutationFn: () => api.post(`/operator/accounts/${id}/unsuspend`, {}),
        onSuccess: invalidate,
    });

    const extendTrial = useMutation({
        mutationFn: () => api.post(`/operator/accounts/${id}/extend-trial`, { days: parseInt(trialDays) }),
        onSuccess: invalidate,
    });

    const changePlan = useMutation({
        mutationFn: () => api.post(`/operator/accounts/${id}/change-plan`, { plan_code: planCode }),
        onSuccess: invalidate,
    });

    const grantModule = useMutation({
        mutationFn: (wsId: string) => api.post(`/operator/workspaces/${wsId}/grant-module`, {
            module_key: grantKey, reason: grantReason,
        }),
        onSuccess: () => { invalidate(); setGrantKey(''); setGrantReason(''); },
    });

    const removeOverride = useMutation({
        mutationFn: ({ wsId, key }: { wsId: string; key: string }) =>
            api.delete(`/operator/workspaces/${wsId}/overrides/${key}`),
        onSuccess: invalidate,
    });

    const account = data?.data;

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={onClose}>
            <div
                className="h-full w-full max-w-xl overflow-y-auto bg-[var(--color-card-bg)] shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="sticky top-0 flex items-center justify-between border-b border-[var(--color-border-light)] bg-[var(--color-card-bg)] px-5 py-4">
                    <h2 className="font-semibold">{account?.name ?? 'Loading…'}</h2>
                    <button className="btn-ghost text-sm" onClick={onClose}>Close</button>
                </div>

                {isPending ? (
                    <div className="p-5 text-sm text-[var(--color-text-muted)]">Loading…</div>
                ) : account ? (
                    <div className="space-y-6 p-5">

                        {/* Status + subscription */}
                        <div className="card space-y-2 p-4">
                            <div className="flex items-center gap-2">
                                <StatusBadge status={account.status} />
                                {account.subscription && (
                                    <span className="text-sm text-[var(--color-text-muted)]">
                                        {account.subscription.plan_name} · {account.subscription.status}
                                    </span>
                                )}
                            </div>
                            {account.suspended_reason && (
                                <p className="text-sm text-red-600">Reason: {account.suspended_reason}</p>
                            )}
                            <p className="text-xs text-[var(--color-text-muted)]">
                                {account.user_count} user{account.user_count !== 1 ? 's' : ''} ·
                                {account.base_currency} · joined {account.created_at ? new Date(account.created_at).toLocaleDateString() : '—'}
                            </p>
                        </div>

                        {/* Suspend / unsuspend */}
                        <div className="card space-y-3 p-4">
                            <h3 className="text-sm font-semibold">Suspension</h3>
                            {account.status !== 'suspended' ? (
                                <div className="flex gap-2">
                                    <input
                                        className="field-input flex-1 text-sm"
                                        placeholder="Reason (required)"
                                        value={suspendReason}
                                        onChange={(e) => setSuspendReason(e.target.value)}
                                    />
                                    <button
                                        className="btn-ghost text-sm text-red-600"
                                        disabled={!suspendReason || suspend.isPending}
                                        onClick={() => suspend.mutate()}
                                    >
                                        Suspend
                                    </button>
                                </div>
                            ) : (
                                <button
                                    className="btn-primary text-sm"
                                    disabled={unsuspend.isPending}
                                    onClick={() => unsuspend.mutate()}
                                >
                                    Unsuspend
                                </button>
                            )}
                        </div>

                        {/* Extend trial */}
                        <div className="card space-y-3 p-4">
                            <h3 className="text-sm font-semibold">Trial</h3>
                            <p className="text-xs text-[var(--color-text-muted)]">
                                Ends: {account.trial_ends_at ? new Date(account.trial_ends_at).toLocaleDateString() : '—'}
                            </p>
                            <div className="flex gap-2">
                                <input
                                    type="number"
                                    className="field-input w-24 text-sm"
                                    value={trialDays}
                                    min={1}
                                    max={365}
                                    onChange={(e) => setTrialDays(e.target.value)}
                                />
                                <span className="self-center text-sm text-[var(--color-text-muted)]">days</span>
                                <button
                                    className="btn-ghost text-sm"
                                    disabled={extendTrial.isPending}
                                    onClick={() => extendTrial.mutate()}
                                >
                                    Extend
                                </button>
                            </div>
                        </div>

                        {/* Change plan */}
                        <div className="card space-y-3 p-4">
                            <h3 className="text-sm font-semibold">Plan</h3>
                            <div className="flex gap-2">
                                <select
                                    className="field-input flex-1 text-sm"
                                    value={planCode}
                                    onChange={(e) => setPlanCode(e.target.value)}
                                >
                                    <option value="">Select plan…</option>
                                    {plansData?.data.map((p) => (
                                        <option key={p.code} value={p.code}>{p.name}</option>
                                    ))}
                                </select>
                                <button
                                    className="btn-ghost text-sm"
                                    disabled={!planCode || changePlan.isPending}
                                    onClick={() => changePlan.mutate()}
                                >
                                    Change
                                </button>
                            </div>
                        </div>

                        {/* Entitlement overrides */}
                        <div className="card space-y-3 p-4">
                            <h3 className="text-sm font-semibold">Entitlement overrides</h3>
                            {account.workspaces.map((ws) => (
                                <div key={ws.id} className="space-y-2">
                                    <button
                                        className="text-xs font-medium text-[var(--color-text-muted)]"
                                        onClick={() => setActiveWorkspace(activeWorkspace === ws.id ? null : ws.id)}
                                    >
                                        {ws.name} ({ws.overrides.length} override{ws.overrides.length !== 1 ? 's' : ''})
                                    </button>

                                    {activeWorkspace === ws.id && (
                                        <div className="space-y-2 pl-3">
                                            {ws.overrides.map((o) => (
                                                <div key={o.module_key} className="flex items-center gap-2 text-xs">
                                                    <span className={cn('rounded px-1 py-0.5 font-medium',
                                                        o.kind === 'grant' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                                                    )}>
                                                        {o.kind}
                                                    </span>
                                                    <span className="font-mono">{o.module_key}</span>
                                                    <span className="flex-1 text-[var(--color-text-muted)]">{o.reason}</span>
                                                    <button
                                                        className="text-red-500 hover:text-red-700"
                                                        onClick={() => removeOverride.mutate({ wsId: ws.id, key: o.module_key })}
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            ))}

                                            <div className="flex gap-2 pt-1">
                                                <input
                                                    className="field-input flex-1 text-xs"
                                                    placeholder="module.key"
                                                    value={grantKey}
                                                    onChange={(e) => setGrantKey(e.target.value)}
                                                />
                                                <input
                                                    className="field-input flex-1 text-xs"
                                                    placeholder="Reason"
                                                    value={grantReason}
                                                    onChange={(e) => setGrantReason(e.target.value)}
                                                />
                                                <button
                                                    className="btn-ghost text-xs"
                                                    disabled={!grantKey || !grantReason || grantModule.isPending}
                                                    onClick={() => grantModule.mutate(ws.id)}
                                                >
                                                    Grant
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export default function Operator() {
    useDocumentTitle('Operator Panel');

    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<string | null>(null);

    const { data, isPending } = useQuery({
        queryKey: ['operator', 'accounts', search],
        queryFn: ({ signal }) =>
            api.get<{ data: AccountRow[]; meta: { has_more: boolean } }>(
                `/operator/accounts?search=${encodeURIComponent(search)}`,
                { signal },
            ),
    });

    const accounts = data?.data ?? [];

    return (
        <div className="mx-auto max-w-4xl">
            <PageHeader
                title="Operator Panel"
                description="Platform-level account management — Angisflow staff only"
                actions={
                    <input
                        type="search"
                        className="field-input w-56 text-sm"
                        placeholder="Search accounts…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                }
            />

            <div className="mt-6 card overflow-hidden">
                {isPending ? (
                    <div className="p-8 text-center text-sm text-[var(--color-text-muted)]">Loading…</div>
                ) : accounts.length === 0 ? (
                    <div className="p-8 text-center text-sm text-[var(--color-text-muted)]">No accounts found.</div>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-[var(--color-border-light)] text-xs text-[var(--color-text-muted)]">
                                <th className="px-4 py-2.5 text-left font-medium">Account</th>
                                <th className="px-4 py-2.5 text-left font-medium">Status</th>
                                <th className="px-4 py-2.5 text-left font-medium">Plan</th>
                                <th className="px-4 py-2.5 text-left font-medium">Joined</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--color-border-light)]">
                            {accounts.map((a) => (
                                <tr
                                    key={a.id}
                                    className="cursor-pointer hover:bg-[var(--color-card-raised)]"
                                    onClick={() => setSelected(a.id)}
                                >
                                    <td className="px-4 py-2.5">
                                        <span className="font-medium">{a.name}</span>
                                        <span className="ml-2 text-xs text-[var(--color-text-muted)]">{a.slug}</span>
                                    </td>
                                    <td className="px-4 py-2.5"><StatusBadge status={a.status} /></td>
                                    <td className="px-4 py-2.5 text-[var(--color-text-muted)]">{a.plan ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-[var(--color-text-muted)]">
                                        {a.created_at ? new Date(a.created_at).toLocaleDateString() : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {selected && (
                <AccountDetailPanel id={selected} onClose={() => setSelected(null)} />
            )}
        </div>
    );
}
