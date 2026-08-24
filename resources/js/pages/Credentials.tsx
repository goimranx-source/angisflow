import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import { confirm } from '@/lib/confirm';

type Credential = {
    id: string;
    ref: string;
    label: string;
    kind: 'api_key' | 'oauth_token' | 'basic_auth' | 'generic';
    is_active: boolean;
    last_rotated_at: string | null;
    last_used_at: string | null;
    created_at: string | null;
};

const KIND_LABELS: Record<string, string> = {
    api_key: 'API Key',
    oauth_token: 'OAuth Token',
    basic_auth: 'Basic Auth',
    generic: 'Generic',
};

const KIND_COLORS: Record<string, string> = {
    api_key: 'bg-blue-100 text-blue-700',
    oauth_token: 'bg-purple-100 text-purple-700',
    basic_auth: 'bg-amber-100 text-amber-700',
    generic: 'bg-gray-100 text-gray-600',
};

function KindBadge({ kind }: { kind: string }) {
    return (
        <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium', KIND_COLORS[kind] ?? KIND_COLORS.generic)}>
            {KIND_LABELS[kind] ?? kind}
        </span>
    );
}

function AddCredentialForm({ onDone }: { onDone: () => void }) {
    const qc = useQueryClient();
    const [label, setLabel] = useState('');
    const [kind, setKind] = useState<string>('api_key');
    const [payloadText, setPayloadText] = useState('{\n  "key": ""\n}');
    const [error, setError] = useState<string | null>(null);

    const mutation = useMutation({
        mutationFn: (body: object) => api.post('/credentials', body),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['credentials'] });
            onDone();
        },
        onError: (e: any) => setError(e?.message ?? 'Failed to save credential.'),
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        setError(null);
        let payload: object;
        try {
            payload = JSON.parse(payloadText);
        } catch {
            setError('Payload must be valid JSON.');
            return;
        }
        mutation.mutate({ label, kind, payload });
    }

    return (
        <form onSubmit={submit} className="card space-y-4 p-4">
            <h3 className="text-sm font-semibold">New credential</h3>

            {error && <p className="text-sm text-red-600">{error}</p>}

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label className="field-label">Label</label>
                    <input
                        className="field-input w-full"
                        value={label}
                        onChange={(e) => setLabel(e.target.value)}
                        placeholder="Pathao API key"
                        required
                    />
                </div>
                <div>
                    <label className="field-label">Kind</label>
                    <select className="field-input w-full" value={kind} onChange={(e) => setKind(e.target.value)}>
                        {Object.entries(KIND_LABELS).map(([k, v]) => (
                            <option key={k} value={k}>{v}</option>
                        ))}
                    </select>
                </div>
            </div>

            <div>
                <label className="field-label">Payload (JSON)</label>
                <textarea
                    className="field-input w-full font-mono text-xs"
                    rows={5}
                    value={payloadText}
                    onChange={(e) => setPayloadText(e.target.value)}
                    spellCheck={false}
                />
                <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                    Stored encrypted. Never logged or returned in list responses.
                </p>
            </div>

            <div className="flex gap-2">
                <button type="submit" className="btn-primary text-sm" disabled={mutation.isPending}>
                    {mutation.isPending ? 'Saving…' : 'Save credential'}
                </button>
                <button type="button" className="btn-ghost text-sm" onClick={onDone}>
                    Cancel
                </button>
            </div>
        </form>
    );
}

function RevealModal({ id, label, onClose }: { id: string; label: string; onClose: () => void }) {
    const { data, isPending, isError } = useQuery({
        queryKey: ['credentials', id, 'reveal'],
        queryFn: ({ signal }) => api.get<{ data: { payload: Record<string, string> } }>(`/credentials/${id}/reveal`, { signal }),
        retry: false,
    });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div className="card w-full max-w-md space-y-4 p-5">
                <h3 className="font-semibold">{label}</h3>

                {isPending && <p className="text-sm text-[var(--color-text-muted)]">Decrypting…</p>}

                {isError && (
                    <p className="text-sm text-red-600">
                        Could not reveal this credential. Your session may need a password confirmation.
                    </p>
                )}

                {data && (
                    <pre className="overflow-x-auto rounded bg-[var(--color-card-raised)] p-3 text-xs">
                        {JSON.stringify(data.data.payload, null, 2)}
                    </pre>
                )}

                <button className="btn-ghost text-sm" onClick={onClose}>Close</button>
            </div>
        </div>
    );
}

export default function Credentials() {
    useDocumentTitle('Credential Vault');

    const qc = useQueryClient();
    const [adding, setAdding] = useState(false);
    const [revealing, setRevealing] = useState<Credential | null>(null);

    const { data, isPending } = useQuery({
        queryKey: ['credentials'],
        queryFn: ({ signal }) => api.get<{ data: Credential[] }>('/credentials', { signal }),
    });

    const revoke = useMutation({
        mutationFn: (id: string) => api.delete(`/credentials/${id}`),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['credentials'] }),
    });

    const credentials = data?.data ?? [];

    return (
        <div className="mx-auto max-w-3xl">
            <PageHeader
                title="Credential Vault"
                description="API keys and tokens for integrations — stored encrypted, never logged"
                actions={
                    !adding && (
                        <button className="btn-primary text-sm" onClick={() => setAdding(true)}>
                            Add credential
                        </button>
                    )
                }
            />

            <div className="mt-6 space-y-4">
                {adding && <AddCredentialForm onDone={() => setAdding(false)} />}

                {isPending
                    ? Array.from({ length: 3 }, (_, i) => (
                          <div key={i} className="card h-16 animate-pulse bg-[var(--color-card-raised)]" />
                      ))
                    : credentials.length === 0 && !adding
                    ? (
                          <div className="card p-8 text-center text-sm text-[var(--color-text-muted)]">
                              No credentials stored yet. Add one to connect an integration.
                          </div>
                      )
                    : credentials.map((c) => (
                          <div
                              key={c.id}
                              className={cn('card flex items-center gap-4 px-4 py-3', !c.is_active && 'opacity-50')}
                          >
                              <div className="min-w-0 flex-1">
                                  <div className="flex items-center gap-2">
                                      <span className="font-medium">{c.label}</span>
                                      <KindBadge kind={c.kind} />
                                      {!c.is_active && (
                                          <span className="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700">
                                              Revoked
                                          </span>
                                      )}
                                  </div>
                                  <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                      {c.last_rotated_at
                                          ? `Last rotated ${new Date(c.last_rotated_at).toLocaleDateString()}`
                                          : 'Never rotated'}
                                      {c.last_used_at && ` · Last used ${new Date(c.last_used_at).toLocaleDateString()}`}
                                  </p>
                              </div>

                              {c.is_active && (
                                  <div className="flex shrink-0 gap-2">
                                      <button
                                          className="btn-ghost text-xs"
                                          onClick={() => setRevealing(c)}
                                      >
                                          Reveal
                                      </button>
                                      <button
                                          className="btn-ghost text-xs text-red-600 hover:text-red-700"
                                          onClick={async () => {
                                              if (await confirm(`Revoke "${c.label}"? This cannot be undone.`)) {
                                                  revoke.mutate(c.id);
                                              }
                                          }}
                                      >
                                          Revoke
                                      </button>
                                  </div>
                              )}
                          </div>
                      ))}
            </div>

            {revealing && (
                <RevealModal
                    id={revealing.id}
                    label={revealing.label}
                    onClose={() => setRevealing(null)}
                />
            )}
        </div>
    );
}
