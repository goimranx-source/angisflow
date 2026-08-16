import { useState } from 'react';
import { Link } from 'react-router';

import { CreateWorkspaceWizard } from '@/components/home/CreateWorkspaceWizard';
import { Icon } from '@/components/ui/Icon';
import type { Allowance } from '@/types';

/**
 * The tile that starts a new workspace.
 *
 * ── Why the allowance is drawn here but enforced elsewhere ───────────────────
 *
 * The tile is hidden when the allowance says there is no room, but the
 * allowance the client holds can be stale — another window, another person on
 * the same account, a plan that changed a minute ago. The server decides, and a
 * 402 coming back is a normal outcome shown plainly by the dialog, not an
 * error. This is a courtesy; the check is in WorkspaceEndpoint.
 */
export function NewWorkspace({
    allowance,
    onCreated,
}: {
    allowance: Allowance | undefined;
    onCreated: () => void;
}) {
    const [open, setOpen] = useState(false);

    if (!allowance) {
        return null;
    }

    if (!allowance.workspaces.can_add) {
        return (
            <div className="card overflow-hidden">
                <div className="flex items-center gap-4 border-2 border-dashed border-[var(--color-border-light)] bg-[var(--color-bg-subtle)] p-6 transition-colors">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center bg-[var(--color-card-bg)]" style={{ borderRadius: 'var(--shell-radius)' }}>
                        <Icon name="pause-circle" size={24} className="text-[var(--color-text-subtle)]" />
                    </div>
                    
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-[var(--color-text-main)]">
                            No room for another workspace
                        </p>
                        <p className="text-xs text-[var(--color-text-muted)]">
                            Your plan includes {allowance.workspaces.limit} {allowance.workspaces.limit === 1 ? 'workspace' : 'workspaces'}.
                        </p>
                    </div>
                    
                    <Link
                        to="/billing"
                        className="shrink-0 rounded bg-[var(--color-brand)] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-brand-hover)]"
                        style={{ borderRadius: 'var(--shell-radius)' }}
                    >
                        Upgrade plan
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="card group overflow-hidden transition-all hover:shadow-md"
            >
                <div className="flex items-center gap-4 border-2 border-dashed border-[var(--color-border-light)] bg-[var(--color-bg-subtle)] p-6 transition-all group-hover:border-[var(--color-brand)] group-hover:bg-[var(--color-brand-subtle)]">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center bg-[var(--color-brand-subtle)] transition-colors group-hover:bg-[var(--color-brand)]" style={{ borderRadius: 'var(--shell-radius)' }}>
                        <Icon name="plus" size={24} className="text-[var(--color-brand-text)] transition-colors group-hover:text-white" />
                    </div>
                    
                    <div className="flex-1 min-w-0 text-left">
                        <p className="text-sm font-semibold text-[var(--color-text-main)]">
                            Create new workspace
                        </p>
                        <p className="text-xs text-[var(--color-text-muted)]">
                            {allowance.workspaces.used} of {allowance.workspaces.limit ?? 'unlimited'} workspaces used
                        </p>
                    </div>
                    
                    <div className="shrink-0">
                        <Icon name="arrow-right" size={20} className="text-[var(--color-text-subtle)] transition-transform group-hover:translate-x-1" />
                    </div>
                </div>
            </button>

            {open && (
                <CreateWorkspaceWizard onClose={() => setOpen(false)} onCreated={onCreated} />
            )}
        </>
    );
}
