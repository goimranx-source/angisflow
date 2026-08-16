/**
 * The marks a workspace may wear.
 *
 * One list, read by both the create wizard and the edit dialog. Held apart they
 * drifted — the edit dialog offered eleven where creation offered twelve, so a
 * workspace made with the odd one out opened its own settings with nothing
 * selected and quietly lost its icon on the next save.
 *
 * Thirteen, so that with the "use initials" tile the grid is two full rows of
 * seven.
 */
export const WORKSPACE_ICONS = [
    'buildings',
    'building',
    'building-office',
    'storefront',
    'warehouse',
    'factory',
    'bank',
    'hospital',
    'church',
    'house',
    'apartment',
    'briefcase',
    'compass',
] as const;

export type WorkspaceIcon = (typeof WORKSPACE_ICONS)[number] | null;
