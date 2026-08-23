import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import {
    FilterBar,
    FilterSelect,
    ViewToggleButton,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    BulkActions,
    BulkActionButton,
    SelectCheckbox,
    QuickCreateModal,
    QuickActionButton,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { useMoney } from '@/hooks/useMoney';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

import { ProductEditor } from './catalogue/ProductEditor';

type Product = {
    id: string;
    sku: string;
    name: string;
    description?: string;
    category?: {
        id: string;
        name: string;
    };
    price: number;
    cost?: number;
    stock_quantity: number;
    stock_status: 'in_stock' | 'low_stock' | 'out_of_stock';
    reorder_point?: number;
    unit?: string;
    barcode?: string;
    is_active: boolean;
    is_featured: boolean;
    image_url?: string;
    tags: string[];
    created_at: string;
};

type ProductsResponse = {
    data: Product[];
    summary: {
        total_products: number;
        active_products: number;
        total_value: number;
        low_stock_count: number;
    };
    meta: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
};

export default function Products() {
    useDocumentTitle('Products');

    // State
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('active');
    const [stockFilter, setStockFilter] = useState('');
    const [view, setView] = useState<'list' | 'grid'>('list');
    const [selectedProducts, setSelectedProducts] = useState<string[]>([]);
    const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);
    const [drawerTab, setDrawerTab] = useState('overview');
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [sortBy, setSortBy] = useState<string | null>('name');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc' | null>('asc');

    // Form state
    const [form, setForm] = useState({
        sku: '',
        name: '',
        description: '',
        price: '',
        cost: '',
        stock_quantity: '',
        reorder_point: '',
        unit: 'piece',
    });

    // Fetch products
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['products', { search, categoryFilter, statusFilter, stockFilter, sortBy, sortDirection }],
        queryFn: ({ signal }) =>
            api.get<ProductsResponse>('/products', {
                params: {
                    search,
                    category: categoryFilter || undefined,
                    status: statusFilter || undefined,
                    stock_status: stockFilter || undefined,
                    sort_by: sortBy,
                    sort_direction: sortDirection,
                },
                signal,
            }),
    });

    const products = data?.data ?? [];
    const summary = data?.summary;

    // Selection handlers
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedProducts(products.map((p) => p.id));
        } else {
            setSelectedProducts([]);
        }
    };

    const handleSelectProduct = (id: string, checked: boolean) => {
        if (checked) {
            setSelectedProducts([...selectedProducts, id]);
        } else {
            setSelectedProducts(selectedProducts.filter((pid) => pid !== id));
        }
    };

    const isAllSelected = products.length > 0 && selectedProducts.length === products.length;
    const isSomeSelected = selectedProducts.length > 0 && selectedProducts.length < products.length;

    // Sort handler
    const handleSort = (key: string) => {
        if (sortBy === key) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(key);
            setSortDirection('asc');
        }
    };

    // Clear filters
    const handleClearFilters = () => {
        setSearch('');
        setCategoryFilter('');
        setStatusFilter('active');
        setStockFilter('');
    };

    const hasFilters = search || categoryFilter || statusFilter !== 'active' || stockFilter;

    // Bulk actions
    const handleBulkExport = () => {
        console.log('Exporting products:', selectedProducts);
        // TODO: Implement export
    };

    const handleBulkActivate = () => {
        if (confirm(`Activate ${selectedProducts.length} products?`)) {
            console.log('Activating products:', selectedProducts);
            // TODO: Implement activate
            setSelectedProducts([]);
        }
    };

    const handleBulkDeactivate = () => {
        if (confirm(`Deactivate ${selectedProducts.length} products?`)) {
            console.log('Deactivating products:', selectedProducts);
            // TODO: Implement deactivate
            setSelectedProducts([]);
        }
    };

    const handleBulkDelete = () => {
        if (confirm(`Delete ${selectedProducts.length} products? This cannot be undone.`)) {
            console.log('Deleting products:', selectedProducts);
            // TODO: Implement delete
            setSelectedProducts([]);
        }
    };

    // Create product
    const handleCreateProduct = () => {
        console.log('Creating product:', form);
        // TODO: Implement create
        setShowCreateModal(false);
    };

    // Money in the business currency, and inside the money scope.
    const { format: formatMoney } = useMoney();

    // Calculate margin
    const calculateMargin = (price: number, cost?: number) => {
        if (!cost || cost === 0) return null;
        const margin = ((price - cost) / price) * 100;
        return `${margin.toFixed(1)}%`;
    };

    // Stock status variants
    const stockVariants: Record<string, 'success' | 'warning' | 'danger'> = {
        in_stock: 'success',
        low_stock: 'warning',
        out_of_stock: 'danger',
    };

    const stockLabels = {
        in_stock: 'In Stock',
        low_stock: 'Low Stock',
        out_of_stock: 'Out of Stock',
    };

    return (
        <div className="flex h-full flex-col">
            {/* Page Header */}
            <div className="px-6 pt-6">
                <PageHeader
                    title="Products"
                    description="Manage your product catalog and inventory"
                    icon="squares-four"
                    actions={
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Import')}
                            >
                                <Icon name="upload" size={16} />
                                <span>Import</span>
                            </button>
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => console.log('Export')}
                            >
                                <Icon name="download-simple" size={16} />
                                <span>Export</span>
                            </button>
                            <QuickActionButton
                                icon="plus"
                                label="Add Product"
                                onClick={() => setShowCreateModal(true)}
                            />
                        </>
                    }
                />
            </div>

            {/* KPI Cards */}
            {summary && (
                <div className="mt-6 grid gap-4 px-6 sm:grid-cols-2 lg:grid-cols-4">
                    <KPICard
                        label="Total Products"
                        value={summary.total_products.toLocaleString()}
                        icon="squares-four"
                        variant="brand"
                    />
                    <KPICard
                        label="Active Products"
                        value={summary.active_products.toLocaleString()}
                        icon="check-circle"
                        variant="success"
                    />
                    <KPICard
                        label="Inventory Value"
                        value={formatMoney(summary.total_value)}
                        icon="currency-dollar"
                        variant="info"
                    />
                    <KPICard
                        label="Low Stock"
                        value={summary.low_stock_count.toLocaleString()}
                        icon="warning"
                        variant="warning"
                    />
                </div>
            )}

            {/* Filter Bar */}
            <div className="mt-4">
                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by name, SKU, or barcode..."
                    filters={
                        <>
                            <FilterSelect
                                label="Status"
                                value={statusFilter}
                                onChange={setStatusFilter}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                                placeholder="All statuses"
                            />
                            <FilterSelect
                                label="Stock"
                                value={stockFilter}
                                onChange={setStockFilter}
                                options={[
                                    { value: 'in_stock', label: 'In Stock' },
                                    { value: 'low_stock', label: 'Low Stock' },
                                    { value: 'out_of_stock', label: 'Out of Stock' },
                                ]}
                                placeholder="All stock levels"
                            />
                            {hasFilters && (
                                <button
                                    type="button"
                                    onClick={handleClearFilters}
                                    className="flex items-center gap-1.5 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text-main)]"
                                >
                                    <Icon name="x" size={14} />
                                    <span>Clear</span>
                                </button>
                            )}
                        </>
                    }
                    viewControls={
                        <>
                            <ViewToggleButton
                                icon="list"
                                label="List"
                                active={view === 'list'}
                                onClick={() => setView('list')}
                            />
                            <ViewToggleButton
                                icon="squares-four"
                                label="Grid"
                                active={view === 'grid'}
                                onClick={() => setView('grid')}
                            />
                        </>
                    }
                />
            </div>

            {/* Content */}
            <div className="flex-1 overflow-auto px-6 pb-6">
                {isError ? (
                    <div className="card mt-6 p-6 text-center">
                        <p className="text-sm text-[var(--color-text-body)]">
                            Failed to load products.
                        </p>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            className="btn btn-secondary mt-4"
                        >
                            Try again
                        </button>
                    </div>
                ) : (
                    <div className="card mt-6 overflow-hidden">
                        {view === 'list' ? (
                            <Table
                                data={products}
                                loading={isLoading}
                                skeletonRows={10}
                                columns={[
                                    {
                                        key: 'select',
                                        label: '',
                                        width: 'w-12',
                                        render: (product) => (
                                            <SelectCheckbox
                                                checked={selectedProducts.includes(product.id)}
                                                onChange={(checked) =>
                                                    handleSelectProduct(product.id, checked)
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        key: 'image',
                                        label: '',
                                        width: 'w-16',
                                        render: (product) => (
                                            <div
                                                className="flex size-12 items-center justify-center overflow-hidden bg-[var(--shell-hover)]"
                                                style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                            >
                                                {product.image_url ? (
                                                    <img
                                                        src={product.image_url}
                                                        alt={product.name}
                                                        className="size-full object-cover"
                                                    />
                                                ) : (
                                                    <Icon
                                                        name="image"
                                                        size={20}
                                                        className="text-[var(--color-text-subtle)]"
                                                    />
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'name',
                                        label: 'Product',
                                        sortable: true,
                                        render: (product) => (
                                            <div>
                                                <p className="font-medium text-[var(--color-text-main)]">
                                                    {product.name}
                                                    {product.is_featured && (
                                                        <Icon
                                                            name="star"
                                                            size={14}
                                                            weight="fill"
                                                            className="ml-1.5 inline text-[var(--color-warning)]"
                                                        />
                                                    )}
                                                </p>
                                                <p className="mt-0.5 flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
                                                    <span>SKU: {product.sku}</span>
                                                    {product.category && (
                                                        <>
                                                            <span>·</span>
                                                            <span>{product.category.name}</span>
                                                        </>
                                                    )}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'price',
                                        label: 'Price',
                                        sortable: true,
                                        align: 'right',
                                        render: (p) => (
                                            <div>
                                                <p className="font-semibold tabular-nums text-[var(--color-text-main)]">
                                                    {formatMoney(p.price)}
                                                </p>
                                                {p.cost && (
                                                    <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">
                                                        Cost: {formatMoney(p.cost)}
                                                    </p>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'margin',
                                        label: 'Margin',
                                        align: 'right',
                                        render: (p) => {
                                            const margin = calculateMargin(p.price, p.cost);
                                            return margin ? (
                                                <span className="text-sm text-[var(--color-text-body)]">
                                                    {margin}
                                                </span>
                                            ) : (
                                                <span className="text-sm text-[var(--color-text-subtle)]">—</span>
                                            );
                                        },
                                    },
                                    {
                                        key: 'stock_quantity',
                                        label: 'Stock',
                                        sortable: true,
                                        align: 'center',
                                        render: (p) => (
                                            <div>
                                                <p className="font-medium tabular-nums text-[var(--color-text-main)]">
                                                    {p.stock_quantity}
                                                    {p.unit && (
                                                        <span className="ml-1 text-xs font-normal text-[var(--color-text-muted)]">
                                                            {p.unit}
                                                        </span>
                                                    )}
                                                </p>
                                                {p.reorder_point && p.stock_quantity <= p.reorder_point && (
                                                    <p className="mt-0.5 text-xs text-[var(--color-warning)]">
                                                        Reorder at {p.reorder_point}
                                                    </p>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'stock_status',
                                        label: 'Status',
                                        render: (p) => (
                                            <StatusBadge
                                                label={stockLabels[p.stock_status]}
                                                variant={stockVariants[p.stock_status]}
                                                dot
                                            />
                                        ),
                                    },
                                    {
                                        key: 'is_active',
                                        label: 'Active',
                                        width: 'w-24',
                                        render: (p) =>
                                            p.is_active ? (
                                                <Icon
                                                    name="check-circle"
                                                    size={18}
                                                    weight="fill"
                                                    className="text-[var(--color-success)]"
                                                />
                                            ) : (
                                                <Icon
                                                    name="x-circle"
                                                    size={18}
                                                    weight="fill"
                                                    className="text-[var(--color-text-subtle)]"
                                                />
                                            ),
                                    },
                                ]}
                                sortBy={sortBy}
                                sortDirection={sortDirection}
                                onSort={handleSort}
                                onRowClick={(product) => setSelectedProduct(product)}
                                clickable
                                getRowKey={(product) => product.id}
                                emptyState={
                                    <EmptyState
                                        icon="squares-four"
                                        title={hasFilters ? 'No products match' : 'No products yet'}
                                        body={
                                            hasFilters
                                                ? 'Try adjusting your filters to see more results.'
                                                : 'Add your first product to start building your catalog.'
                                        }
                                        action={
                                            hasFilters ? (
                                                <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                    Clear filters
                                                </button>
                                            ) : (
                                                <button
                                                    className="btn btn-primary"
                                                    onClick={() => setShowCreateModal(true)}
                                                >
                                                    Add Product
                                                </button>
                                            )
                                        }
                                    />
                                }
                            />
                        ) : (
                            <div className="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {isLoading ? (
                                    Array.from({ length: 8 }, (_, i) => (
                                        <div
                                            key={i}
                                            className="card h-64 animate-pulse"
                                            style={{ background: 'var(--shell-hover)' }}
                                        />
                                    ))
                                ) : products.length === 0 ? (
                                    <div className="col-span-full">
                                        <EmptyState
                                            icon="squares-four"
                                            title={hasFilters ? 'No products match' : 'No products yet'}
                                            body={
                                                hasFilters
                                                    ? 'Try adjusting your filters to see more results.'
                                                    : 'Add your first product to start building your catalog.'
                                            }
                                            action={
                                                hasFilters ? (
                                                    <button className="btn btn-secondary" onClick={handleClearFilters}>
                                                        Clear filters
                                                    </button>
                                                ) : (
                                                    <button
                                                        className="btn btn-primary"
                                                        onClick={() => setShowCreateModal(true)}
                                                    >
                                                        Add Product
                                                    </button>
                                                )
                                            }
                                        />
                                    </div>
                                ) : (
                                    products.map((product) => (
                                        <button
                                            key={product.id}
                                            type="button"
                                            onClick={() => setSelectedProduct(product)}
                                            className="group card overflow-hidden p-0 text-left transition-shadow hover:shadow-md"
                                        >
                                            {/* Product Image */}
                                            <div className="relative aspect-square w-full overflow-hidden bg-[var(--shell-hover)]">
                                                {product.image_url ? (
                                                    <img
                                                        src={product.image_url}
                                                        alt={product.name}
                                                        className="size-full object-cover transition-transform group-hover:scale-105"
                                                    />
                                                ) : (
                                                    <div className="flex size-full items-center justify-center">
                                                        <Icon
                                                            name="image"
                                                            size={48}
                                                            className="text-[var(--color-text-subtle)]"
                                                        />
                                                    </div>
                                                )}
                                                {/* Badges */}
                                                <div className="absolute left-2 top-2 flex flex-col gap-1">
                                                    {!product.is_active && (
                                                        <span
                                                            className="bg-gray-900/80 px-2 py-0.5 text-xs font-medium text-white backdrop-blur-sm"
                                                            style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                                        >
                                                            Inactive
                                                        </span>
                                                    )}
                                                    {product.is_featured && (
                                                        <span
                                                            className="flex items-center gap-1 bg-[var(--color-warning)]/90 px-2 py-0.5 text-xs font-medium text-white backdrop-blur-sm"
                                                            style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                                        >
                                                            <Icon name="star" size={12} weight="fill" />
                                                            Featured
                                                        </span>
                                                    )}
                                                </div>
                                                {/* Stock Badge */}
                                                <div className="absolute bottom-2 right-2">
                                                    <StatusBadge
                                                        label={stockLabels[product.stock_status]}
                                                        variant={stockVariants[product.stock_status]}
                                                        size="sm"
                                                        dot
                                                    />
                                                </div>
                                            </div>

                                            {/* Product Info */}
                                            <div className="p-4">
                                                <p className="font-medium text-[var(--color-text-main)] line-clamp-2">
                                                    {product.name}
                                                </p>
                                                <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                                    SKU: {product.sku}
                                                </p>
                                                <div className="mt-3 flex items-center justify-between">
                                                    <p className="text-lg font-semibold text-[var(--color-text-main)]">
                                                        {formatMoney(product.price)}
                                                    </p>
                                                    <p className="text-sm text-[var(--color-text-muted)]">
                                                        Stock: {product.stock_quantity}
                                                    </p>
                                                </div>
                                            </div>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                    </div>
                )}

                {/* Select all checkbox */}
                {!isLoading && products.length > 0 && view === 'list' && (
                    <div className="mt-3 px-6">
                        <SelectCheckbox
                            checked={isAllSelected}
                            indeterminate={isSomeSelected}
                            onChange={handleSelectAll}
                            label={
                                isAllSelected
                                    ? 'Deselect all'
                                    : isSomeSelected
                                      ? `${selectedProducts.length} selected`
                                      : 'Select all'
                            }
                        />
                    </div>
                )}
            </div>

            {/* Bulk Actions */}
            <BulkActions
                selectedCount={selectedProducts.length}
                onClearSelection={() => setSelectedProducts([])}
            >
                <BulkActionButton
                    icon="download-simple"
                    label="Export"
                    onClick={handleBulkExport}
                />
                <BulkActionButton
                    icon="check-circle"
                    label="Activate"
                    onClick={handleBulkActivate}
                />
                <BulkActionButton
                    icon="x-circle"
                    label="Deactivate"
                    onClick={handleBulkDeactivate}
                />
                <BulkActionButton
                    icon="trash"
                    label="Delete"
                    onClick={handleBulkDelete}
                    variant="danger"
                />
            </BulkActions>

            {/* Detail Drawer */}
            <DetailDrawer
                open={!!selectedProduct}
                onClose={() => setSelectedProduct(null)}
                title={selectedProduct?.name ?? ''}
                subtitle={selectedProduct ? `SKU: ${selectedProduct.sku}` : ''}
                tabs={[
                    {
                        /*
                          Editing first, because that is what this screen could
                          not do.

                          Every action on the catalogue was a console.log and a
                          TODO, which left the two-way product sync untestable
                          from the application it belongs to — the only way to
                          prove a price change reached the shop was to write one
                          in a console.
                        */
                        key: 'edit',
                        label: 'Edit',
                        content: selectedProduct && (
                            <ProductEditor
                                product={selectedProduct}
                                onSaved={() => setSelectedProduct(null)}
                            />
                        ),
                    },
                    {
                        key: 'overview',
                        label: 'Overview',
                        content: selectedProduct && (
                            <div className="space-y-6">
                                <DrawerSection title="Product Details">
                                    <DrawerField
                                        label="Product Name"
                                        value={selectedProduct.name}
                                        icon="tag"
                                    />
                                    <DrawerField
                                        label="SKU"
                                        value={selectedProduct.sku}
                                        icon="hash"
                                    />
                                    {selectedProduct.barcode && (
                                        <DrawerField
                                            label="Barcode"
                                            value={selectedProduct.barcode}
                                            icon="barcode"
                                        />
                                    )}
                                    {selectedProduct.category && (
                                        <DrawerField
                                            label="Category"
                                            value={selectedProduct.category.name}
                                            icon="folder"
                                        />
                                    )}
                                    {selectedProduct.description && (
                                        <div>
                                            <p className="text-xs font-medium text-[var(--color-text-muted)] mb-1">
                                                Description
                                            </p>
                                            <p className="text-sm text-[var(--color-text-body)]">
                                                {selectedProduct.description}
                                            </p>
                                        </div>
                                    )}
                                </DrawerSection>

                                <DrawerSection title="Pricing">
                                    <DrawerField
                                        label="Sale Price"
                                        value={formatMoney(selectedProduct.price)}
                                        icon="currency-dollar"
                                    />
                                    {selectedProduct.cost && (
                                        <>
                                            <DrawerField
                                                label="Cost"
                                                value={formatMoney(selectedProduct.cost)}
                                                icon="receipt"
                                            />
                                            <DrawerField
                                                label="Gross Margin"
                                                value={calculateMargin(selectedProduct.price, selectedProduct.cost)}
                                                icon="percent"
                                            />
                                        </>
                                    )}
                                </DrawerSection>

                                <DrawerSection title="Inventory">
                                    <DrawerField
                                        label="Stock Quantity"
                                        value={`${selectedProduct.stock_quantity} ${selectedProduct.unit || 'units'}`}
                                        icon="package"
                                    />
                                    <DrawerField
                                        label="Stock Status"
                                        value={
                                            <StatusBadge
                                                label={stockLabels[selectedProduct.stock_status]}
                                                variant={stockVariants[selectedProduct.stock_status]}
                                                dot
                                            />
                                        }
                                        icon="circle-notch"
                                    />
                                    {selectedProduct.reorder_point && (
                                        <DrawerField
                                            label="Reorder Point"
                                            value={selectedProduct.reorder_point}
                                            icon="warning"
                                        />
                                    )}
                                </DrawerSection>

                                <DrawerSection title="Status">
                                    <DrawerField
                                        label="Active Status"
                                        value={
                                            <StatusBadge
                                                label={selectedProduct.is_active ? 'Active' : 'Inactive'}
                                                variant={selectedProduct.is_active ? 'success' : 'neutral'}
                                                dot
                                            />
                                        }
                                        icon="check-circle"
                                    />
                                    <DrawerField
                                        label="Featured"
                                        value={selectedProduct.is_featured ? 'Yes' : 'No'}
                                        icon="star"
                                    />
                                </DrawerSection>

                                {selectedProduct.tags.length > 0 && (
                                    <DrawerSection title="Tags">
                                        <div className="flex flex-wrap gap-2">
                                            {selectedProduct.tags.map((tag) => (
                                                <span
                                                    key={tag}
                                                    className="inline-flex items-center gap-1 border border-[var(--color-border-light)] bg-[var(--shell-hover)] px-2 py-1 text-xs text-[var(--color-text-body)]"
                                                    style={{ borderRadius: 'var(--shell-radius-sm)' }}
                                                >
                                                    <Icon name="tag" size={12} />
                                                    {tag}
                                                </span>
                                            ))}
                                        </div>
                                    </DrawerSection>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'stock',
                        label: 'Stock History',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Stock history coming soon</p>,
                    },
                    {
                        key: 'sales',
                        label: 'Sales',
                        content: <p className="text-sm text-[var(--color-text-muted)]">Sales analytics coming soon</p>,
                    },
                ]}
                activeTab={drawerTab}
                onTabChange={setDrawerTab}
                /*
                  No header button.

                  There was an Edit button here with no onClick — it looked like
                  the feature and did nothing, which is worse than not offering
                  it. Editing is the first tab now, so a button beside the tab
                  of the same name would be two doors into one room.
                */
            >
                <div />
            </DetailDrawer>

            {/* Create Product Modal */}
            <QuickCreateModal
                open={showCreateModal}
                onClose={() => setShowCreateModal(false)}
                title="Add Product"
                onSubmit={handleCreateProduct}
                submitLabel="Create Product"
                size="lg"
            >
                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Product Name <span className="text-[var(--color-danger)]">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            placeholder="e.g., Organic Cotton T-Shirt"
                            className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                SKU <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="text"
                                value={form.sku}
                                onChange={(e) => setForm({ ...form, sku: e.target.value })}
                                placeholder="e.g., TEE-001"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm font-mono focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Unit
                            </label>
                            <select
                                value={form.unit}
                                onChange={(e) => setForm({ ...form, unit: e.target.value })}
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            >
                                <option value="piece">Piece</option>
                                <option value="box">Box</option>
                                <option value="kg">Kilogram</option>
                                <option value="liter">Liter</option>
                                <option value="meter">Meter</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                            Description
                        </label>
                        <textarea
                            value={form.description}
                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                            rows={3}
                            placeholder="Product description..."
                            className="w-full resize-none border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                            style={{ borderRadius: 'var(--shell-radius)' }}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Sale Price <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                value={form.price}
                                onChange={(e) => setForm({ ...form, price: e.target.value })}
                                placeholder="0.00"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Cost
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                value={form.cost}
                                onChange={(e) => setForm({ ...form, cost: e.target.value })}
                                placeholder="0.00"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Initial Stock <span className="text-[var(--color-danger)]">*</span>
                            </label>
                            <input
                                type="number"
                                value={form.stock_quantity}
                                onChange={(e) => setForm({ ...form, stock_quantity: e.target.value })}
                                placeholder="0"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                                Reorder Point
                            </label>
                            <input
                                type="number"
                                value={form.reorder_point}
                                onChange={(e) => setForm({ ...form, reorder_point: e.target.value })}
                                placeholder="0"
                                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm focus:border-[var(--color-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--color-brand)]"
                                style={{ borderRadius: 'var(--shell-radius)' }}
                            />
                        </div>
                    </div>
                </div>
            </QuickCreateModal>
        </div>
    );
}
