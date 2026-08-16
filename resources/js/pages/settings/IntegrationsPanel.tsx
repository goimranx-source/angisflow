import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';

/**
 * Integrations.
 *
 * Deliberately a signpost rather than a half-built panel. An integration is a
 * connection *to a storefront*, and storefronts are not built yet — so a form
 * here would collect credentials for something that has nowhere to put them.
 *
 * Saying so plainly beats showing a disabled form, which reads as broken.
 */
export default function IntegrationsPanel() {
    return (
        <div className="card px-6 py-12 text-center">
            <Icon name="plug" size={30} className="mx-auto text-[var(--color-text-subtle)]" />

            <h3 className="mt-4 font-[family-name:var(--font-heading)] text-lg font-bold">
                Integrations arrive with Storefronts
            </h3>

            <p className="mx-auto mt-2 max-w-md text-sm text-[var(--color-text-body)]">
                An integration is a connection to a shop — its API keys, the webhook it posts to, and how
                its fields map to yours. All of that belongs to a storefront, so it lands when they do.
            </p>

            <Link to="/soon/stores" className="btn btn-secondary mt-5">
                What Storefronts will do
                <Icon name="arrow-left" size={14} className="rotate-180" />
            </Link>
        </div>
    );
}
