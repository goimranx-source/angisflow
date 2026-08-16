import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

import { Icon } from '@/components/ui/Icon';
import { useOrganiser, useOrganiserActions } from '@/hooks/useOrganiser';
import { toast } from '@/lib/toast';

/**
 * The tags this account has, and what can be done to them.
 *
 * Renaming and deleting are here rather than on the row menus: a tag belongs to
 * the account, not to the thing it happens to be on, and offering "delete tag"
 * from a business makes it look like it only affects that business.
 */
export function ManageTagsModal({ onClose }: { onClose: () => void }) {
    const { data } = useOrganiser();
    const { createTag, renameTag, deleteTag } = useOrganiserActions();
    const [name, setName] = useState('');
    const [editing, setEditing] = useState<string | null>(null);
    const [draft, setDraft] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    const tags = data?.data ?? [];

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const add = () => {
        const trimmed = name.trim();

        if (trimmed.length === 0) {
            return;
        }

        setProblem(null);

        createTag.mutate(trimmed, {
            onSuccess: () => setName(''),
            // The server refuses a duplicate by name; showing its own words
            // means the reason is the real one rather than a guess.
            onError: (error: unknown) =>
                setProblem((error as { message?: string })?.message ?? 'That could not be added.'),
        });
    };

    return createPortal(
        <div className="modal-backdrop" role="dialog" aria-modal="true" aria-label="Manage tags">
            <div className="absolute inset-0" onClick={onClose} aria-hidden />

            <div className="modal">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-base font-semibold text-[var(--color-text-main)]">Tags</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="row-menu-trigger"
                        aria-label="Close"
                    >
                        <Icon name="x" size={16} />
                    </button>
                </div>

                <p className="mt-1 text-[0.8125rem] text-[var(--color-text-muted)]">
                    Shared across everybody on this account.
                </p>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        add();
                    }}
                    className="mt-4 flex gap-2"
                >
                    <input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="New tag name"
                        maxLength={40}
                        className="field"
                    />
                    <button
                        type="submit"
                        disabled={name.trim().length === 0 || createTag.isPending}
                        className="modal-primary-action"
                    >
                        Add
                    </button>
                </form>

                {problem && <p className="mt-2 text-xs text-[var(--color-warning)]">{problem}</p>}

                <div className="mt-4 max-h-64 overflow-y-auto">
                    {tags.length === 0 ? (
                        <p className="py-6 text-center text-sm text-[var(--color-text-muted)]">
                            No tags yet. Add one above.
                        </p>
                    ) : (
                        <div className="divide-y divide-[var(--shell-border)]">
                            {tags.map((tag) => (
                                <div key={tag.id} className="flex items-center gap-2 py-2">
                                    {editing === tag.id ? (
                                        <>
                                            <input
                                                autoFocus
                                                value={draft}
                                                onChange={(event) => setDraft(event.target.value)}
                                                maxLength={40}
                                                className="field"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    renameTag.mutate(
                                                        { id: tag.id, name: draft.trim() },
                                                        {
                                                            onSuccess: () => setEditing(null),
                                                            onError: () =>
                                                                toast.error('That name is taken.'),
                                                        },
                                                    );
                                                }}
                                                disabled={draft.trim().length === 0}
                                                className="todo-action"
                                            >
                                                Save
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setEditing(null)}
                                                className="row-menu-trigger"
                                                aria-label="Cancel"
                                            >
                                                <Icon name="x" size={15} />
                                            </button>
                                        </>
                                    ) : (
                                        <>
                                            <Icon
                                                name="tag"
                                                size={15}
                                                weight="regular"
                                                className="flex-none text-[var(--color-text-muted)]"
                                            />
                                            <span className="min-w-0 flex-1 truncate text-sm">
                                                {tag.name}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setEditing(tag.id);
                                                    setDraft(tag.name);
                                                }}
                                                className="row-menu-trigger"
                                                aria-label={`Rename ${tag.name}`}
                                            >
                                                <Icon name="paint-brush" size={14} />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => deleteTag.mutate(tag.id)}
                                                className="row-menu-trigger"
                                                aria-label={`Delete ${tag.name}`}
                                            >
                                                <Icon name="trash" size={14} />
                                            </button>
                                        </>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}
