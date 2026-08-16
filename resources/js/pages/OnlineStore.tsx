import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { KPICard } from '@/components/modules';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type StoreSummary = {
    total_products: number;
    published_products: number;
    total_orders: number;
    total_revenue: number;
    store_status: 'online' | 'offline' | 'maintenance';
    store_url: string;
};

type StoreSettings = {
    name: string;
    description: string;
    logo?: string;
    theme: string;
    currency: string;
    language: string;
};

export default function OnlineStore() {
    useDocumentTitle('Online Store');

    const [activeTab, setActiveTab] = useState<'overview' | 'appearance' | 'settings' | 'analytics'>('overview');

    // Fetch store summary
    const { data: summary } = useQuery({
        queryKey: ['store-summary'],
        queryFn: ({ signal }) => api.get<StoreSummary>('/store/summary', { signal }),
    });

    // Fetch store settings
    const { data: settings } = useQuery({
        queryKey: ['store-settings'],
        queryFn: ({ signal }) => api.get<StoreSettings>('/store/settings', { signal }),
    });

    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    const statusColors = {
        online: 'text-[var(--color-success)]',
        offline: 'text-[var(--color-danger)]',
        maintenance: 'text-[var(--color-warning)]',
    };

    const statusLabels = {
        online: 'Online',
        offline: 'Offline',
        maintenance: 'Maintenance Mode',
    };

    const tabs = [
        { key: 'overview', label: 'Overview', icon: 'house' },
        { key: 'appearance', label: 'Appearance', icon: 'paint-brush' },
        { key: 'settings', label: 'Settings', icon: 'gear' },
        { key: 'analytics', label: 'Analytics', icon: 'chart-line' },
    ] as const;

    return (
        <div className="flex h-full flex-col">
            {/* Header */}
            <div className="border-b border-[var(--color-border-light)] px-6 pt-6">
                <PageHeader
                    title="Online Store"
                    description="Manage your e-commerce storefront and online presence"
                    icon="shopping-bag-open"
                    actions={
                        <div className="flex gap-3">
                            {summary?.store_url && (
                                <a
                                    href={summary.store_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="btn btn-secondary"
                                >
                                    <Icon name="arrow-square-out" size={16} />
                                    <span>Visit store</span>
                                </a>
                            )}
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => console.log('Store builder')}
                            >
                                <Icon name="paint-brush" size={16} />
                                <span>Customize store</span>
                            </button>
                        </div>
                    }
                />

                {/* Status Indicator */}
                {summary && (
                    <div className="mt-4 flex items-center gap-2 pb-4">
                        <div className={`flex items-center gap-2 ${statusColors[summary.store_status]}`}>
                            <div className="h-2 w-2 animate-pulse rounded-full bg-current" />
                            <span className="text-sm font-medium">{statusLabels[summary.store_status]}</span>
                        </div>
                        {summary.store_url && (
                            <>
                                <span className="text-[var(--color-text-muted)]">•</span>
                                <a
                                    href={summary.store_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-sm text-[var(--color-brand)] hover:underline"
                                >
                                    {summary.store_url}
                                </a>
                            </>
                        )}
                    </div>
                )}

                {/* Tabs */}
                <div className="flex gap-1 overflow-x-auto">
                    {tabs.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key)}
                            className={`flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors ${
                                activeTab === tab.key
                                    ? 'border-[var(--color-brand)] text-[var(--color-brand)]'
                                    : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]'
                            }`}
                        >
                            <Icon name={tab.icon} size={16} />
                            <span>{tab.label}</span>
                        </button>
                    ))}
                </div>
            </div>

            {/* Content */}
            <div className="flex-1 overflow-auto p-6">
                {activeTab === 'overview' && summary && (
                    <div className="space-y-6">
                        {/* KPIs */}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KPICard
                                label="Total Products"
                                value={summary.total_products.toLocaleString()}
                                icon="package"
                                variant="brand"
                            />
                            <KPICard
                                label="Published"
                                value={summary.published_products.toLocaleString()}
                                icon="check-circle"
                                variant="success"
                            />
                            <KPICard
                                label="Total Orders"
                                value={summary.total_orders.toLocaleString()}
                                icon="shopping-cart"
                                variant="info"
                            />
                            <KPICard
                                label="Revenue"
                                value={formatMoney(summary.total_revenue)}
                                icon="currency-dollar"
                                variant="success"
                            />
                        </div>

                        {/* Quick Actions */}
                        <div className="card">
                            <div className="border-b border-[var(--color-border-light)] p-4">
                                <h3 className="font-semibold text-[var(--color-text-main)]">Quick Actions</h3>
                            </div>
                            <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                <button className="btn btn-secondary justify-start">
                                    <Icon name="plus" size={18} />
                                    <span>Add Product</span>
                                </button>
                                <button className="btn btn-secondary justify-start">
                                    <Icon name="tag" size={18} />
                                    <span>Create Offer</span>
                                </button>
                                <button className="btn btn-secondary justify-start">
                                    <Icon name="images" size={18} />
                                    <span>Add Banner</span>
                                </button>
                                <button className="btn btn-secondary justify-start">
                                    <Icon name="list-bullets" size={18} />
                                    <span>Manage Categories</span>
                                </button>
                                <button className="btn btn-secondary justify-start">
                                    <Icon name="truck" size={18} />
                                    <span>Shipping Settings</span>
                                </button>
                                <button className="btn btn-secondary justify-start">
                                    <Icon name="credit-card" size={18} />
                                    <span>Payment Methods</span>
                                </button>
                            </div>
                        </div>

                        {/* Store Features */}
                        <div className="card">
                            <div className="border-b border-[var(--color-border-light)] p-4">
                                <h3 className="font-semibold text-[var(--color-text-main)]">Store Features</h3>
                            </div>
                            <div className="divide-y divide-[var(--color-border-light)]">
                                <div className="flex items-center justify-between p-4">
                                    <div className="flex items-center gap-3">
                                        <Icon name="shopping-cart" size={20} className="text-[var(--color-text-muted)]" />
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">Shopping Cart</p>
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                Allow customers to add items to cart
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm text-[var(--color-success)]">Enabled</span>
                                        <Icon name="check-circle" size={16} className="text-[var(--color-success)]" />
                                    </div>
                                </div>
                                <div className="flex items-center justify-between p-4">
                                    <div className="flex items-center gap-3">
                                        <Icon name="star" size={20} className="text-[var(--color-text-muted)]" />
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">Product Reviews</p>
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                Let customers leave reviews
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm text-[var(--color-success)]">Enabled</span>
                                        <Icon name="check-circle" size={16} className="text-[var(--color-success)]" />
                                    </div>
                                </div>
                                <div className="flex items-center justify-between p-4">
                                    <div className="flex items-center gap-3">
                                        <Icon name="magnifying-glass" size={20} className="text-[var(--color-text-muted)]" />
                                        <div>
                                            <p className="font-medium text-[var(--color-text-main)]">Search & Filters</p>
                                            <p className="text-sm text-[var(--color-text-muted)]">
                                                Help customers find products
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm text-[var(--color-success)]">Enabled</span>
                                        <Icon name="check-circle" size={16} className="text-[var(--color-success)]" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'appearance' && (
                    <div className="card">
                        <div className="border-b border-[var(--color-border-light)] p-4">
                            <h3 className="font-semibold text-[var(--color-text-main)]">Store Appearance</h3>
                            <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                Customize how your store looks to customers
                            </p>
                        </div>
                        <div className="p-6">
                            <div className="flex items-center justify-center rounded-lg border-2 border-dashed border-[var(--color-border-light)] p-12">
                                <div className="text-center">
                                    <Icon name="paint-brush" size={48} className="mx-auto text-[var(--color-text-muted)]" />
                                    <p className="mt-4 font-medium text-[var(--color-text-main)]">
                                        Theme Customizer Coming Soon
                                    </p>
                                    <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                                        Customize colors, fonts, and layout of your store
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'settings' && settings && (
                    <div className="space-y-6">
                        <div className="card">
                            <div className="border-b border-[var(--color-border-light)] p-4">
                                <h3 className="font-semibold text-[var(--color-text-main)]">Store Information</h3>
                            </div>
                            <div className="space-y-4 p-4">
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                        Store Name
                                    </label>
                                    <input type="text" className="input" value={settings.name} readOnly />
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                        Description
                                    </label>
                                    <textarea className="input min-h-[100px]" value={settings.description} readOnly />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                            Currency
                                        </label>
                                        <input type="text" className="input" value={settings.currency} readOnly />
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                            Language
                                        </label>
                                        <input type="text" className="input" value={settings.language} readOnly />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="border-b border-[var(--color-border-light)] p-4">
                                <h3 className="font-semibold text-[var(--color-text-main)]">Store Status</h3>
                            </div>
                            <div className="p-4">
                                <button className="btn btn-primary">
                                    <Icon name="power" size={16} />
                                    <span>Change Status</span>
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'analytics' && (
                    <div className="card">
                        <div className="border-b border-[var(--color-border-light)] p-4">
                            <h3 className="font-semibold text-[var(--color-text-main)]">Store Analytics</h3>
                            <p className="mt-1 text-sm text-[var(--color-text-muted)]">
                                Track performance and customer behavior
                            </p>
                        </div>
                        <div className="p-6">
                            <div className="flex items-center justify-center rounded-lg border-2 border-dashed border-[var(--color-border-light)] p-12">
                                <div className="text-center">
                                    <Icon name="chart-line" size={48} className="mx-auto text-[var(--color-text-muted)]" />
                                    <p className="mt-4 font-medium text-[var(--color-text-main)]">
                                        Analytics Dashboard Coming Soon
                                    </p>
                                    <p className="mt-2 text-sm text-[var(--color-text-muted)]">
                                        View traffic, conversions, and revenue metrics
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
