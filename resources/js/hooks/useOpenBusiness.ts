import { useCallback } from 'react';
import { useNavigate } from 'react-router';

import { api } from '@/lib/api';
import { queryClient } from '@/lib/query';
import { toast } from '@/lib/toast';
import { useSession } from '@/providers/SessionProvider';
import type { BootPayload } from '@/types';

/**
 * Open a workspace — and a set of books inside it — and go to its dashboard.
 *
 * One place rather than repeated on each screen that offers a Dashboard button:
 * the cache has to be emptied before the switch, the shell replaced after it,
 * and the navigation done last. Any screen that got that order wrong would show
 * one business's figures under another's name for a frame.
 */
export function useOpenBusiness() {
    const { apply } = useSession();
    const navigate = useNavigate();

    return useCallback(
        async (workspaceId: string | null, businessId?: string) => {
            if (!workspaceId) {
                toast.error('That business is not in a workspace.');

                return;
            }

            // Everything held belongs to the business being left.
            queryClient.clear();

            try {
                const result = await api.post<{ message: string; boot: BootPayload }>(
                    '/workspaces/open',
                    { workspace: workspaceId, business: businessId ?? null },
                );

                apply(result.boot);
                navigate('/dashboard');
                toast.success(result.message);
            } catch {
                toast.error('That could not be opened.');
            }
        },
        [apply, navigate],
    );
}
