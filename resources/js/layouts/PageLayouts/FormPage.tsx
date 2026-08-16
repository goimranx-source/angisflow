import { type FormEvent, type ReactNode } from 'react';

import { Alert } from '@/components/ui/Alert';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/utils';

type FormPageProps = {
    /** Page title */
    title: string;
    /** Page description */
    description?: string;
    /** Breadcrumb items */
    breadcrumbs?: Array<{ label: string; href?: string; icon?: string }>;
    /** Back button href */
    backHref?: string;
    /** Back button label */
    backLabel?: string;
    /** Form content */
    children: ReactNode;
    /** Submit handler */
    onSubmit?: (e: FormEvent<HTMLFormElement>) => void;
    /** Cancel handler */
    onCancel?: () => void;
    /** Submit button text */
    submitText?: string;
    /** Cancel button text */
    cancelText?: string;
    /** Is form submitting */
    isSubmitting?: boolean;
    /** Is submit disabled */
    isSubmitDisabled?: boolean;
    /** Success message */
    successMessage?: string;
    /** Error message */
    errorMessage?: string;
    /** Form width */
    width?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
    /** Additional class */
    className?: string;
};

/**
 * Standard form page layout template.
 *
 * Features:
 * - Page header with title and description
 * - Optional breadcrumb navigation
 * - Back button
 * - Form container with consistent width
 * - Submit and cancel buttons
 * - Success/error messages
 * - Loading states
 *
 * @example
 * ```tsx
 * <FormPage
 *   title="Create Product"
 *   description="Add a new product to your catalogue"
 *   breadcrumbs={[
 *     { label: 'Home', href: '/' },
 *     { label: 'Products', href: '/products' },
 *     { label: 'Create' }
 *   ]}
 *   backHref="/products"
 *   onSubmit={handleSubmit}
 *   onCancel={() => navigate('/products')}
 *   submitText="Create Product"
 *   isSubmitting={isLoading}
 *   errorMessage={error}
 *   width="lg"
 * >
 *   <Input label="Product Name" ... />
 *   <Textarea label="Description" ... />
 *   <MoneyInput label="Price" ... />
 * </FormPage>
 * ```
 */
export function FormPage({
    title,
    description,
    breadcrumbs,
    backHref,
    backLabel = 'Back',
    children,
    onSubmit,
    onCancel,
    submitText = 'Save',
    cancelText = 'Cancel',
    isSubmitting = false,
    isSubmitDisabled = false,
    successMessage,
    errorMessage,
    width = 'lg',
    className,
}: FormPageProps) {
    const maxWidthClasses = {
        sm: 'max-w-md',
        md: 'max-w-2xl',
        lg: 'max-w-4xl',
        xl: 'max-w-6xl',
        full: 'max-w-full',
    };

    return (
        <div className={cn('mx-auto', maxWidthClasses[width], className)}>
            {/* Breadcrumbs */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <div className="mb-4">
                    <Breadcrumb items={breadcrumbs} />
                </div>
            )}

            {/* Back Button */}
            {backHref && (
                <div className="mb-4">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => window.location.href = backHref}
                    >
                        <Icon name="caret-left" size={14} />
                        {backLabel}
                    </Button>
                </div>
            )}

            {/* Header */}
            <div className="mb-8">
                <h1 className="text-2xl font-bold text-[var(--color-text-main)]">
                    {title}
                </h1>
                {description && (
                    <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                        {description}
                    </p>
                )}
            </div>

            {/* Success Message */}
            {successMessage && (
                <div className="mb-6">
                    <Alert variant="success" dismissible>
                        {successMessage}
                    </Alert>
                </div>
            )}

            {/* Error Message */}
            {errorMessage && (
                <div className="mb-6">
                    <Alert variant="error" dismissible>
                        {errorMessage}
                    </Alert>
                </div>
            )}

            {/* Form */}
            <form
                onSubmit={onSubmit}
                className="card p-6"
            >
                {/* Form Fields */}
                <div className="space-y-6">
                    {children}
                </div>

                {/* Form Actions */}
                <div className="mt-8 flex flex-wrap gap-3 border-t border-[var(--color-border-light)] pt-6">
                    {onCancel && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onCancel}
                            disabled={isSubmitting}
                        >
                            {cancelText}
                        </Button>
                    )}
                    <Button
                        type="submit"
                        busy={isSubmitting}
                        disabled={isSubmitDisabled || isSubmitting}
                    >
                        {submitText}
                    </Button>
                </div>
            </form>
        </div>
    );
}
