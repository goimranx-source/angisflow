import { useState } from 'react';
import { Link } from 'react-router';

import { Icon } from '@/components/ui/Icon';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';

/**
 * Landing page for guest users.
 *
 * Showcases the platform's features, pricing plans, and guides visitors
 * toward signing up with a 15-day trial. This is what non-authenticated
 * users see when they visit the root URL.
 */
export default function Landing() {
    const [billingInterval, setBillingInterval] = useState<'monthly' | 'yearly'>('monthly');

    useDocumentTitle('Welcome to Prism');

    return (
        <div className="min-h-screen bg-gradient-to-b from-[#f8fafc] to-white">
            {/* Header */}
            <header className="border-b border-gray-200 bg-white/80 backdrop-blur-sm">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--color-ink)]">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" className="h-5 w-5">
                                    <path d="M16 7l7 12H9l7-12z" fill="#00d4e8"/>
                                    <circle cx="16" cy="23" r="2.2" fill="#00d4e8"/>
                                </svg>
                            </div>
                            <span className="text-xl font-bold text-[var(--color-ink)]">Prism</span>
                        </div>

                        <div className="flex items-center gap-4">
                            <Link
                                to="/login"
                                className="text-sm font-medium text-gray-700 hover:text-gray-900"
                            >
                                Sign in
                            </Link>
                            <Link
                                to="/register"
                                className="rounded-lg bg-[var(--color-brand)] px-4 py-2 text-sm font-semibold text-white hover:bg-[var(--color-brand-hover)] transition-colors"
                            >
                                Start free trial
                            </Link>
                        </div>
                    </div>
                </div>
            </header>

            {/* Hero Section */}
            <section className="py-20">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="text-center">
                        <h1 className="font-[family-name:var(--font-heading)] text-5xl font-bold tracking-tight text-gray-900 sm:text-6xl">
                            Business management,
                            <br />
                            <span className="text-[var(--color-brand)]">simplified</span>
                        </h1>
                        <p className="mx-auto mt-6 max-w-2xl text-lg leading-8 text-gray-600">
                            Manage multiple businesses, track finances, organize operations, and make data-driven
                            decisions — all in one beautiful platform.
                        </p>
                        <div className="mt-10 flex items-center justify-center gap-x-6">
                            <Link
                                to="/register"
                                className="rounded-lg bg-[var(--color-brand)] px-6 py-3 text-base font-semibold text-white shadow-sm hover:bg-[var(--color-brand-hover)] transition-colors"
                            >
                                Start 15-day free trial
                            </Link>
                            <a href="#features" className="text-base font-semibold text-gray-900">
                                Learn more <span aria-hidden="true">→</span>
                            </a>
                        </div>
                        <p className="mt-4 text-sm text-gray-500">
                            No credit card required · Cancel anytime
                        </p>
                    </div>
                </div>
            </section>

            {/* Features Section */}
            <section id="features" className="py-20 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="text-center">
                        <h2 className="font-[family-name:var(--font-heading)] text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                            Everything you need to run your business
                        </h2>
                        <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                            Powerful features designed to help you work smarter, not harder
                        </p>
                    </div>

                    <div className="mt-16 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        <FeatureCard
                            icon="squares-four"
                            title="Multi-Workspace Management"
                            description="Organize multiple businesses under separate workspaces with independent settings and teams."
                        />
                        <FeatureCard
                            icon="users-three"
                            title="Team Collaboration"
                            description="Invite team members with role-based permissions and collaborate in real-time."
                        />
                        <FeatureCard
                            icon="chart-line"
                            title="Advanced Reporting"
                            description="Get insights with comprehensive reports and analytics to make informed decisions."
                        />
                        <FeatureCard
                            icon="currency-circle-dollar"
                            title="Multi-Currency Support"
                            description="Handle transactions in multiple currencies with automatic exchange rate updates."
                        />
                        <FeatureCard
                            icon="shield-check"
                            title="Enterprise Security"
                            description="Bank-level security with two-factor authentication, audit logs, and SSO."
                        />
                        <FeatureCard
                            icon="app-window"
                            title="API Access"
                            description="Integrate with your existing tools using our comprehensive REST API."
                        />
                    </div>
                </div>
            </section>

            {/* Pricing Section */}
            <section id="pricing" className="py-20 bg-gradient-to-b from-gray-50 to-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="text-center">
                        <h2 className="font-[family-name:var(--font-heading)] text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                            Simple, transparent pricing
                        </h2>
                        <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-600">
                            Choose the plan that's right for you. All plans include a 15-day free trial.
                        </p>
                    </div>

                    {/* Billing Toggle */}
                    <div className="mt-8 flex justify-center">
                        <div className="inline-flex rounded-lg border border-gray-200 bg-white p-1">
                            <button
                                type="button"
                                onClick={() => setBillingInterval('monthly')}
                                className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                    billingInterval === 'monthly'
                                        ? 'bg-[var(--color-brand)] text-white'
                                        : 'text-gray-700 hover:text-gray-900'
                                }`}
                            >
                                Monthly
                            </button>
                            <button
                                type="button"
                                onClick={() => setBillingInterval('yearly')}
                                className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                    billingInterval === 'yearly'
                                        ? 'bg-[var(--color-brand)] text-white'
                                        : 'text-gray-700 hover:text-gray-900'
                                }`}
                            >
                                Yearly <span className="ml-1 text-xs">(Save 20%)</span>
                            </button>
                        </div>
                    </div>

                    <div className="mt-12 grid gap-8 lg:grid-cols-4">
                        <PricingCard
                            name="Starter"
                            price={billingInterval === 'monthly' ? 19 : 182}
                            interval={billingInterval}
                            description="Perfect for solo entrepreneurs"
                            features={[
                                '1 Workspace',
                                '2 Businesses',
                                'Up to 3 team members',
                                '5 GB storage',
                                '1,000 monthly transactions',
                                'Basic reporting',
                                'Email support',
                                'Mobile app',
                            ]}
                            unavailableFeatures={[
                                'Advanced reporting',
                                'API access',
                                'Custom roles',
                            ]}
                        />
                        <PricingCard
                            name="Professional"
                            price={billingInterval === 'monthly' ? 49 : 470}
                            interval={billingInterval}
                            description="For growing businesses"
                            features={[
                                '3 Workspaces',
                                '10 Businesses',
                                'Up to 15 team members',
                                '50 GB storage',
                                '10,000 monthly transactions',
                                'Advanced reporting',
                                'Priority support',
                                'Mobile app',
                                'API access',
                                'Custom roles',
                                'Audit logs',
                            ]}
                            popular
                        />
                        <PricingCard
                            name="Business"
                            price={billingInterval === 'monthly' ? 99 : 950}
                            interval={billingInterval}
                            description="For established businesses"
                            features={[
                                '10 Workspaces',
                                'Unlimited businesses',
                                'Up to 50 team members',
                                '200 GB storage',
                                'Unlimited transactions',
                                'Custom reports',
                                'Phone support',
                                'Mobile app',
                                'API access',
                                'Custom roles',
                                'Audit logs',
                                'White label',
                                'Advanced integrations',
                            ]}
                        />
                        <PricingCard
                            name="Enterprise"
                            price={billingInterval === 'monthly' ? 299 : 2870}
                            interval={billingInterval}
                            description="Unlimited everything"
                            features={[
                                'Unlimited workspaces',
                                'Unlimited businesses',
                                'Unlimited team members',
                                'Unlimited storage',
                                'Unlimited transactions',
                                'Dedicated account manager',
                                '24/7 phone support',
                                'SSO',
                                'Custom SLA',
                                'Onboarding support',
                                'Everything in Business',
                            ]}
                        />
                    </div>
                </div>
            </section>

            {/* CTA Section */}
            <section className="py-20 bg-[var(--color-ink)]">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="text-center">
                        <h2 className="font-[family-name:var(--font-heading)] text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Ready to get started?
                        </h2>
                        <p className="mx-auto mt-4 max-w-2xl text-lg text-gray-300">
                            Join thousands of businesses already using Prism to streamline their operations
                        </p>
                        <div className="mt-10">
                            <Link
                                to="/register"
                                className="inline-block rounded-lg bg-[var(--color-brand)] px-8 py-3 text-base font-semibold text-white shadow-sm hover:bg-[var(--color-brand-hover)] transition-colors"
                            >
                                Start your free trial
                            </Link>
                        </div>
                        <p className="mt-4 text-sm text-gray-400">
                            15 days free · No credit card required
                        </p>
                    </div>
                </div>
            </section>

            {/* Footer */}
            <footer className="border-t border-gray-200 bg-white py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="text-center text-sm text-gray-500">
                        © 2026 Prism. All rights reserved.
                    </div>
                </div>
            </footer>
        </div>
    );
}

function FeatureCard({ icon, title, description }: { icon: string; title: string; description: string }) {
    return (
        <div className="relative rounded-2xl border border-gray-200 bg-white p-8 shadow-sm hover:shadow-md transition-shadow">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--color-brand-subtle)]">
                <Icon name={icon} size={24} weight="bold" className="text-[var(--color-brand)]" />
            </div>
            <h3 className="mt-6 text-lg font-semibold text-gray-900">{title}</h3>
            <p className="mt-2 text-sm text-gray-600">{description}</p>
        </div>
    );
}

function PricingCard({
    name,
    price,
    interval,
    description,
    features,
    unavailableFeatures = [],
    popular = false,
}: {
    name: string;
    price: number;
    interval: 'monthly' | 'yearly';
    description: string;
    features: string[];
    unavailableFeatures?: string[];
    popular?: boolean;
}) {
    return (
        <div
            className={`relative rounded-2xl border p-8 ${
                popular
                    ? 'border-[var(--color-brand)] bg-white shadow-xl ring-2 ring-[var(--color-brand)]'
                    : 'border-gray-200 bg-white shadow-sm'
            }`}
        >
            {popular && (
                <div className="absolute -top-4 left-0 right-0 mx-auto w-32">
                    <div className="rounded-full bg-[var(--color-brand)] px-3 py-1 text-center text-sm font-semibold text-white">
                        Most Popular
                    </div>
                </div>
            )}

            <div>
                <h3 className="text-lg font-semibold text-gray-900">{name}</h3>
                <p className="mt-2 text-sm text-gray-600">{description}</p>
                <p className="mt-6">
                    <span className="text-4xl font-bold tracking-tight text-gray-900">${price}</span>
                    <span className="text-base font-medium text-gray-600">
                        /{interval === 'monthly' ? 'month' : 'year'}
                    </span>
                </p>
                <Link
                    to="/register"
                    className={`mt-6 block w-full rounded-lg px-4 py-3 text-center text-sm font-semibold transition-colors ${
                        popular
                            ? 'bg-[var(--color-brand)] text-white hover:bg-[var(--color-brand-hover)]'
                            : 'bg-gray-100 text-gray-900 hover:bg-gray-200'
                    }`}
                >
                    Start free trial
                </Link>
            </div>

            <ul className="mt-8 space-y-3">
                {features.map((feature) => (
                    <li key={feature} className="flex items-start gap-3">
                        <Icon
                            name="check"
                            size={16}
                            weight="bold"
                            className="mt-0.5 flex-none text-[var(--color-brand)]"
                        />
                        <span className="text-sm text-gray-700">{feature}</span>
                    </li>
                ))}
                {unavailableFeatures.map((feature) => (
                    <li key={feature} className="flex items-start gap-3 opacity-50">
                        <Icon
                            name="x"
                            size={16}
                            weight="bold"
                            className="mt-0.5 flex-none text-gray-400"
                        />
                        <span className="text-sm text-gray-500 line-through">{feature}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
