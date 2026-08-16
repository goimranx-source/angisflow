import { Fragment } from 'react';
import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';

type BreadcrumbItem = {
    /** Label text */
    label: string;
    /** Link href (optional for current page) */
    href?: string;
    /** Icon name (optional) */
    icon?: string;
};

type BreadcrumbProps = {
    /** Array of breadcrumb items */
    items: BreadcrumbItem[];
    /** Separator icon */
    separator?: string;
    /** Additional class */
    className?: string;
};

/**
 * Breadcrumb navigation component.
 *
 * Features:
 * - Clickable links for parent pages
 * - Current page (no link)
 * - Optional icons per item
 * - Custom separator
 * - Accessible
 *
 * @example
 * ```tsx
 * <Breadcrumb
 *   items={[
 *     { label: 'Home', href: '/', icon: 'house' },
 *     { label: 'Products', href: '/products' },
 *     { label: 'Shoes', href: '/products/shoes' },
 *     { label: 'Nike Air Max', // Current page, no href
 *   ]}
 * />
 * ```
 */
export function Breadcrumb({ items, separator = 'caret-right', className }: BreadcrumbProps) {
    return (
        <nav aria-label="Breadcrumb" className={className}>
            <ol className="flex items-center gap-2 text-sm">
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;
                    const isCurrent = !item.href || isLast;

                    return (
                        <Fragment key={index}>
                            <li className="flex items-center gap-2">
                                {isCurrent ? (
                                    <span
                                        className="flex items-center gap-1.5 font-medium text-[var(--color-text-main)]"
                                        aria-current="page"
                                    >
                                        {item.icon && <Icon name={item.icon} size={14} />}
                                        <span>{item.label}</span>
                                    </span>
                                ) : (
                                    <Link
                                        to={item.href!}
                                        className="flex items-center gap-1.5 text-[var(--color-text-muted)] transition-colors hover:text-[var(--color-text-main)]"
                                    >
                                        {item.icon && <Icon name={item.icon} size={14} />}
                                        <span>{item.label}</span>
                                    </Link>
                                )}
                            </li>

                            {/* Separator */}
                            {!isLast && (
                                <li aria-hidden="true">
                                    <Icon
                                        name={separator}
                                        size={14}
                                        className="text-[var(--color-text-subtle)]"
                                    />
                                </li>
                            )}
                        </Fragment>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * Simpler breadcrumb for common use case (array of strings).
 *
 * @example
 * ```tsx
 * <SimpleBreadcrumb items={['Home', 'Products', 'Shoes']} />
 * ```
 */
export function SimpleBreadcrumb({ items, className }: { items: string[]; className?: string }) {
    return (
        <nav aria-label="Breadcrumb" className={className}>
            <ol className="flex items-center gap-2 text-sm">
                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <Fragment key={index}>
                            <li>
                                {isLast ? (
                                    <span
                                        className="font-medium text-[var(--color-text-main)]"
                                        aria-current="page"
                                    >
                                        {item}
                                    </span>
                                ) : (
                                    <span className="text-[var(--color-text-muted)]">{item}</span>
                                )}
                            </li>

                            {!isLast && (
                                <li aria-hidden="true">
                                    <Icon name="caret-right" size={14} className="text-[var(--color-text-subtle)]" />
                                </li>
                            )}
                        </Fragment>
                    );
                })}
            </ol>
        </nav>
    );
}

