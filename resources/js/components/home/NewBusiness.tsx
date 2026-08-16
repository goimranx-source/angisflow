import { useState } from 'react';

import { CreateBusinessWizard } from '@/components/home/CreateBusinessWizard';
import { Icon } from '@/components/ui/Icon';
import type { PlanQuota } from '@/types';

/**
 * The button that opens a new set of books in a workspace.
 *
 * The ceiling drawn here is per workspace, not per account — a plan sells so
 * many workspaces of so many businesses each, so one being full says nothing
 * about another. As everywhere else, the button is a courtesy and the check is
 * on the server.
 */
export function NewBusiness({
    workspaceId,
    workspaceName,
    allowance,
    onCreated,
}: {
    workspaceId: string;
    workspaceName: string;
    allowance: PlanQuota | null | undefined;
    onCreated: () => void;
}) {
    const [open, setOpen] = useState(false);

    if (!allowance) {
        return null;
    }

    if (!allowance.can_add) {
        return (
            <button type="button" disabled className="add-inline is-spent" title="Your plan has no room for another business here">
                <Icon name="plus" size={13} weight="bold" />
                Add business
            </button>
        );
    }

    return (
        <>
            <button type="button" onClick={() => setOpen(true)} className="add-inline">
                <Icon name="plus" size={13} weight="bold" />
                Add business
            </button>

            {open && (
                <CreateBusinessWizard
                    workspaceId={workspaceId}
                    workspaceName={workspaceName}
                    onClose={() => setOpen(false)}
                    onCreated={onCreated}
                />
            )}
        </>
    );
}
