import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type BadgeSize = 'sm' | 'md' | 'lg';

type BadgeProps = {
    text: string;
    icon?: string | null;
    image?: string | null;
    size?: BadgeSize;
    className?: string;
    showFavorite?: boolean;
};

const ICON_SIZE: Record<BadgeSize, number> = { sm: 16, md: 18, lg: 20 };

/**
 * The square that stands for a workspace or a business.
 *
 * ── A rounded square, never a circle ─────────────────────────────────────────
 *
 * It carried Tailwind's `rounded-lg` and its overlays were `rounded-full`, so
 * the same mark was a squircle in one place and a disc in another — and at
 * 24px a soft corner plus a 2px ring reads as a circle whatever the number
 * says. It now takes --shell-radius-sm, the same corner every other small
 * surface in the shell uses, and the overlay takes it too.
 *
 * ── The background is the point ──────────────────────────────────────────────
 *
 * A logo is usually a transparent PNG. Dropped straight onto the page it is
 * invisible wherever it happens to be white, so the tile keeps its own
 * background and the image sits inside it at `contain` — full width and
 * height, nothing cropped, and whatever the artwork does not cover is filled
 * rather than see-through. `cover` was the old behaviour and it cropped the
 * ends off wide logos.
 */
export function Badge({ text, icon, image, size = 'md', className, showFavorite = false }: BadgeProps) {
    const getInitials = (name: string) => {
        const words = name.trim().split(/\s+/).filter(Boolean);

        if (words.length > 1) {
            return ((words[0]?.[0] ?? '') + (words[1]?.[0] ?? '')).toUpperCase();
        }

        return name.slice(0, 2).toUpperCase();
    };

    return (
        <div className={cn('badge-wrap', className)}>
            <span className={cn('badge', `badge-${size}`)}>
                {image ? (
                    <img src={image} alt="" className="badge-img" />
                ) : icon ? (
                    <Icon name={icon} size={ICON_SIZE[size]} weight="duotone" />
                ) : (
                    getInitials(text)
                )}
            </span>

            {showFavorite && (
                <span className="badge-star">
                    <Icon name="star" size={11} weight="fill" />
                </span>
            )}
        </div>
    );
}

type BusinessBadgeProps = {
    business: {
        name: string;
        short_code?: string | null;
        logo_url?: string | null;
    };
    workspace?: {
        name: string;
        icon?: string | null;
    };
    size?: BadgeSize;
    showFavorite?: boolean;
    /** Show the workspace icon as a corner overlay. */
    showOverlay?: boolean;
    /** Workspace as the main tile, business logo as the corner overlay. */
    invertOverlay?: boolean;
    className?: string;
};

export function BusinessBadge({
    business,
    workspace,
    size = 'md',
    showFavorite = false,
    showOverlay = false,
    invertOverlay = false,
    className,
}: BusinessBadgeProps) {
    if (invertOverlay && showOverlay && workspace) {
        return (
            <div className={cn('badge-wrap', className)}>
                <Badge
                    text={workspace.name}
                    icon={workspace.icon || 'buildings'}
                    size={size}
                    showFavorite={showFavorite}
                />

                {business.logo_url && (
                    <span className="badge-overlay">
                        <img src={business.logo_url} alt="" className="badge-img" />
                    </span>
                )}
            </div>
        );
    }

    return (
        <div className={cn('badge-wrap', className)}>
            <Badge
                text={business.short_code ?? business.name}
                image={business.logo_url}
                size={size}
                showFavorite={showFavorite}
            />

            {showOverlay && workspace && (
                <span className="badge-overlay">
                    <Icon name={workspace.icon || 'buildings'} size={10} weight="duotone" />
                </span>
            )}
        </div>
    );
}

type WorkspaceBadgeProps = {
    workspace: {
        name: string;
        icon?: string | null;
    };
    size?: BadgeSize;
    showFavorite?: boolean;
    className?: string;
};

export function WorkspaceBadge({ workspace, size = 'md', showFavorite = false, className }: WorkspaceBadgeProps) {
    return (
        <Badge
            text={workspace.name}
            icon={workspace.icon || 'buildings'}
            size={size}
            showFavorite={showFavorite}
            className={className}
        />
    );
}
