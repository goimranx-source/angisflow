import { useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Form/Input';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { WORKSPACE_ICONS, type WorkspaceIcon } from '@/lib/workspaceIcons';
import { useSession } from '@/providers/SessionProvider';

/**
 * Creating a workspace.
 *
 * Simplified - categories are now on businesses, not workspaces.
 * A workspace is just a container for businesses and users.
 */
export function CreateWorkspaceWizard({
    onClose,
    onCreated,
}: {
    onClose: () => void;
    onCreated: () => void;
}) {
    const { refresh } = useSession();
    const [name, setName] = useState('');
    const [icon, setIcon] = useState<WorkspaceIcon>(null);
    const [busy, setBusy] = useState(false);
    const [refused, setRefused] = useState<string | null>(null);

    const create = async () => {
        const trimmed = name.trim();

        if (trimmed.length === 0 || busy) {
            return;
        }

        setBusy(true);
        setRefused(null);

        try {
            const result = await api.post<{ message: string }>('/workspaces', {
                name: trimmed,
                icon,
            });

            toast.success(result.message);
            onClose();
            onCreated();
            // The shell carries the workspace list the sidebar picker reads, so
            // it has to learn about the new one too.
            void refresh();
        } catch (problem) {
            setRefused((problem as { message?: string })?.message ?? 'That could not be created.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open={true}
            onClose={onClose}
            title="Create workspace"
            description="A workspace organizes your businesses and teams together."
            size="lg"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        busy={busy}
                        disabled={name.trim().length === 0}
                        onClick={() => void create()}
                    >
                        Create workspace
                    </Button>
                </>
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    void create();
                }}
                className="space-y-4"
            >
                <Input
                    label="Workspace name"
                    id="workspace-name"
                    autoFocus
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="Retail group"
                    maxLength={120}
                    required
                    error={refused ?? undefined}
                />

                <div className="space-y-2">
                    <label className="block text-sm font-medium text-[var(--color-text-main)]">
                        Icon <span className="font-normal text-[var(--color-text-muted)]">(optional)</span>
                    </label>
                    <div className="icon-pick-grid">
                        <button
                            type="button"
                            onClick={() => setIcon(null)}
                            className={cn('icon-pick', icon === null && 'is-selected')}
                            title="Use initials"
                        >
                            {name.slice(0, 2).toUpperCase() || 'WS'}
                        </button>

                        {WORKSPACE_ICONS.map((entry) => (
                            <button
                                key={entry}
                                type="button"
                                onClick={() => setIcon(entry)}
                                className={cn('icon-pick', icon === entry && 'is-selected')}
                                title={entry}
                            >
                                <Icon name={entry} size={16} weight="duotone" />
                            </button>
                        ))}
                    </div>
                    <p className="text-xs text-[var(--color-text-muted)]">
                        Choose an icon or use default initials
                    </p>
                </div>

                <div className="rounded-lg border border-[var(--color-border-light)] bg-[var(--color-card-bg)] p-4">
                    <div className="flex items-start gap-3">
                        <Icon
                            name="info"
                            size={18}
                            weight="duotone"
                            className="mt-0.5 shrink-0 text-[var(--color-brand-text)]"
                        />
                        <div className="min-w-0 space-y-0.5">
                            <p className="text-sm font-semibold text-[var(--color-text-main)]">
                                Business categories are set per business
                            </p>
                            <p className="text-xs leading-relaxed text-[var(--color-text-muted)]">
                                After creating the workspace, add businesses and assign each one a category.
                                This determines which modules are available for that business.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Hidden submit button for Enter key */}
                <button type="submit" className="sr-only" tabIndex={-1}>
                    Create workspace
                </button>
            </form>
        </Modal>
    );
}
