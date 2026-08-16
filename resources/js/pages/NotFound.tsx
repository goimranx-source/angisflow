import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { useSession } from '@/providers/SessionProvider';

export default function NotFound() {
    const { auth } = useSession();

    useDocumentTitle('Not found');

    return (
        <div className="flex min-h-screen flex-col items-center justify-center px-4 text-center">
            <Icon name="compass" size={40} className="text-[var(--color-text-subtle)]" />

            <h1 className="mt-4 font-[family-name:var(--font-heading)] text-2xl font-bold">
                That page is not here
            </h1>

            <p className="mt-2 max-w-sm text-sm text-[var(--color-text-body)]">
                The address may be mistyped, or the thing it pointed at may have been removed.
            </p>

            <Link to={auth ? '/dashboard' : '/login'} className="btn btn-primary mt-6">
                {auth ? 'Back to the dashboard' : 'Sign in'}
            </Link>
        </div>
    );
}
