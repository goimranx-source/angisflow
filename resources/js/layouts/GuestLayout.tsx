import { useEffect, type ReactNode } from 'react';
import { Link } from 'react-router';

import { Toasts } from '@/components/shell/Toasts';
import { Icon } from '@/components/ui/Icon';
import { useSession } from '@/providers/SessionProvider';

type GuestLayoutProps = {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
};

/**
 * The frame around signing in, signing up and everything adjacent.
 *
 * No sidebar and no business switcher — a menu for books you are not in yet is
 * furniture pretending to be an application.
 *
 * The document title is set here rather than by a helmet library: it is one
 * assignment on mount, and a dependency to do it would cost more bytes on the
 * sign-in screen than the whole of this file.
 */
export function GuestLayout({ title, description, children, footer }: GuestLayoutProps) {
    const { app } = useSession();

    useEffect(() => {
        document.title = `${title} — ${app.name}`;
    }, [title, app.name]);

    return (
        <div className="flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <div className="w-full max-w-[26rem]">
                <Link to="/login" className="mb-8 flex items-center justify-center gap-2.5">
                    <span className="grid size-9 place-items-center rounded-xl bg-[var(--color-brand)] text-[var(--color-ink)]">
                        <Icon name="sparkle" size={20} weight="fill" />
                    </span>
                    <span className="font-[family-name:var(--font-heading)] text-xl font-bold text-[var(--color-text-main)]">
                        {app.name}
                    </span>
                </Link>

                <div className="card p-6 sm:p-8">
                    <h1 className="text-xl font-bold">{title}</h1>
                    {description && (
                        <p className="mt-1.5 text-sm text-[var(--color-text-muted)]">{description}</p>
                    )}

                    <div className="mt-6">{children}</div>
                </div>

                {footer && (
                    <div className="mt-5 text-center text-sm text-[var(--color-text-muted)]">{footer}</div>
                )}
            </div>

            <Toasts />
        </div>
    );
}
