import { useState } from 'react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Form/Input';
import { Icon } from '@/components/ui/Icon';
import { Modal } from '@/components/ui/Modal';
import { api } from '@/lib/api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { WORKSPACE_ICONS, type WorkspaceIcon } from '@/lib/workspaceIcons';

export function EditWorkspaceModal({
    workspace,
    onClose,
    onUpdated,
}: {
    workspace: { id: string; name: string; slug: string; icon?: string | null };
    onClose: () => void;
    onUpdated: () => void;
}) {
    const [name, setName] = useState(workspace.name);
    const [slug, setSlug] = useState(workspace.slug);
    const [icon, setIcon] = useState<WorkspaceIcon>((workspace.icon as WorkspaceIcon) ?? null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const update = async () => {
        const trimmedName = name.trim();
        const trimmedSlug = slug.trim();

        if (trimmedName.length === 0 || busy) {
            return;
        }

        setBusy(true);
        setErrors({});

        try {
            const result = await api.put<{ message: string }>(`/workspaces/${workspace.id}`, {
                name: trimmedName,
                slug: trimmedSlug,
                icon,
            });

            toast.success(result.message);
            onClose();
            onUpdated();
        } catch (problem) {
            const error = problem as { message?: string; errors?: Record<string, string[]> };

            if (error.errors) {
                const formattedErrors: Record<string, string> = {};
                Object.entries(error.errors).forEach(([key, messages]) => {
                    if (messages[0]) formattedErrors[key] = messages[0];
                });
                setErrors(formattedErrors);
            } else {
                toast.error(error.message ?? 'That could not be updated.');
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open={true}
            onClose={onClose}
            title="Edit workspace"
            description="Update workspace details and choose a display icon."
            size="lg"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button busy={busy} disabled={name.trim().length === 0} onClick={() => void update()}>
                        Save changes
                    </Button>
                </>
            }
        >
            {/* Icon Selection */}
            <div className="space-y-2">
                <label className="block text-sm font-semibold text-[var(--color-text-main)]">
                    Workspace icon
                </label>
                <div className="icon-pick-grid">
                    <button
                        type="button"
                        onClick={() => setIcon(null)}
                        className={cn('icon-pick', icon === null && 'is-selected')}
                        title="Use initials"
                    >
                        {workspace.name.slice(0, 2).toUpperCase()}
                    </button>

                    {WORKSPACE_ICONS.map((iconName) => (
                        <button
                            key={iconName}
                            type="button"
                            onClick={() => setIcon(iconName)}
                            className={cn('icon-pick', icon === iconName && 'is-selected')}
                            title={iconName}
                        >
                            <Icon name={iconName} size={16} weight="duotone" />
                        </button>
                    ))}
                </div>
                <p className="text-xs text-[var(--color-text-muted)]">
                    Choose an icon or use default initials
                </p>
            </div>

            <Input
                label="Workspace name"
                error={errors.name}
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Retail group"
                maxLength={120}
                required
            />

            <Input
                label="Slug"
                error={errors.slug}
                helperText="Used in URLs (letters, numbers, hyphens only)"
                type="text"
                value={slug}
                onChange={(e) => setSlug(e.target.value.toLowerCase())}
                placeholder="retail-group"
                maxLength={60}
                required
            />
        </Modal>
    );
}
