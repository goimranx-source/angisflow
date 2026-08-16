import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { KPICard } from '@/components/modules';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Product = {
    id: string;
    name: string;
    sku: string;
    price: number;
    stock: number;
    image?: string;
    category?: string;
};

type CartItem = {
    product: Product;
    quantity: number;
    subtotal: number;
};

type POSSummary = {
    today_sales: number;
    today_transactions: number;
    avg_transaction: number;
    active_session: boolean;
};

export default function PointOfSale() {
    useDocumentTitle('Point of Sale');

    // State
    const [cart, setCart] = useState<CartItem[]>([]);
    const [search, setSearch] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('all');
    // TODO: no customer picker wired up yet; see completeSale() below, which
    // also doesn't call the backend yet.
    const [customer] = useState<string | null>(null);
    const [paymentMethod, setPaymentMethod] = useState<'cash' | 'card' | 'upi'>('cash');

    // Fetch POS summary
    const { data: summary } = useQuery({
        queryKey: ['pos-summary'],
        queryFn: ({ signal }) => api.get<POSSummary>('/pos/summary', { signal }),
    });

    // Fetch products
    const { data: productsData } = useQuery({
        queryKey: ['pos-products', { search, selectedCategory }],
        queryFn: ({ signal }) =>
            api.get<{ data: Product[] }>('/pos/products', {
                params: { search, category: selectedCategory !== 'all' ? selectedCategory : undefined },
                signal,
            }),
    });

    const products = productsData?.data ?? [];

    // Cart calculations
    const cartTotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
    const cartItems = cart.reduce((sum, item) => sum + item.quantity, 0);
    const tax = cartTotal * 0.1; // 10% tax
    const grandTotal = cartTotal + tax;

    // Add to cart
    const addToCart = (product: Product) => {
        const existing = cart.find((item) => item.product.id === product.id);
        if (existing) {
            setCart(
                cart.map((item) =>
                    item.product.id === product.id
                        ? { ...item, quantity: item.quantity + 1, subtotal: (item.quantity + 1) * product.price }
                        : item,
                ),
            );
        } else {
            setCart([...cart, { product, quantity: 1, subtotal: product.price }]);
        }
    };

    // Update quantity
    const updateQuantity = (productId: string, quantity: number) => {
        if (quantity <= 0) {
            setCart(cart.filter((item) => item.product.id !== productId));
        } else {
            setCart(
                cart.map((item) =>
                    item.product.id === productId
                        ? { ...item, quantity, subtotal: quantity * item.product.price }
                        : item,
                ),
            );
        }
    };

    // Clear cart
    const clearCart = () => setCart([]);

    // Complete sale
    const completeSale = () => {
        console.log('Complete sale', { cart, paymentMethod, customer, total: grandTotal });
        // API call here
        clearCart();
    };

    // Format currency
    const formatMoney = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    const categories = ['all', 'electronics', 'clothing', 'food', 'accessories'];

    return (
        <div className="flex h-full flex-col bg-[var(--color-surface)]">
            {/* Header */}
            <div className="border-b border-[var(--color-border-light)] bg-white px-6 py-4">
                <PageHeader
                    title="Point of Sale"
                    description="Quick checkout for in-store purchases"
                    icon="cash-register"
                    actions={
                        <div className="flex gap-3">
                            <button className="btn btn-secondary" onClick={() => console.log('Open session')}>
                                <Icon name="clock" size={16} />
                                <span>Sessions</span>
                            </button>
                            <button className="btn btn-secondary" onClick={() => console.log('Hold sale')}>
                                <Icon name="pause" size={16} />
                                <span>Hold</span>
                            </button>
                        </div>
                    }
                />
            </div>

            {/* KPI Bar */}
            {summary && (
                <div className="border-b border-[var(--color-border-light)] bg-white px-6 py-4">
                    <div className="grid gap-4 sm:grid-cols-4">
                        <KPICard
                            label="Today's Sales"
                            value={formatMoney(summary.today_sales)}
                            icon="currency-dollar"
                            variant="success"
                        />
                        <KPICard
                            label="Transactions"
                            value={summary.today_transactions.toString()}
                            icon="receipt"
                            variant="info"
                        />
                        <KPICard
                            label="Avg Transaction"
                            value={formatMoney(summary.avg_transaction)}
                            icon="chart-bar"
                            variant="brand"
                        />
                        <KPICard
                            label="Session"
                            value={summary.active_session ? 'Active' : 'Closed'}
                            icon="circle-notch"
                            variant={summary.active_session ? 'success' : 'neutral'}
                        />
                    </div>
                </div>
            )}

            {/* Main POS Interface */}
            <div className="flex flex-1 gap-4 overflow-hidden p-6">
                {/* Products Section */}
                <div className="flex flex-1 flex-col overflow-hidden rounded-lg border border-[var(--color-border-light)] bg-white">
                    {/* Search & Categories */}
                    <div className="border-b border-[var(--color-border-light)] p-4">
                        <div className="relative">
                            <Icon
                                name="magnifying-glass"
                                size={18}
                                className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]"
                            />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search products or scan barcode..."
                                className="input pl-10"
                            />
                        </div>

                        <div className="mt-3 flex gap-2 overflow-x-auto pb-2">
                            {categories.map((cat) => (
                                <button
                                    key={cat}
                                    onClick={() => setSelectedCategory(cat)}
                                    className={`flex-none rounded-md px-4 py-2 text-sm font-medium capitalize transition-colors ${
                                        selectedCategory === cat
                                            ? 'bg-[var(--color-brand)] text-white'
                                            : 'bg-[var(--color-neutral-subtle)] text-[var(--color-text-main)] hover:bg-[var(--color-neutral-hover)]'
                                    }`}
                                >
                                    {cat}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Products Grid */}
                    <div className="flex-1 overflow-y-auto p-4">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {products.map((product) => (
                                <button
                                    key={product.id}
                                    onClick={() => addToCart(product)}
                                    disabled={product.stock === 0}
                                    className="group relative flex flex-col rounded-lg border border-[var(--color-border-light)] bg-white p-3 text-left transition-all hover:border-[var(--color-brand)] hover:shadow-md disabled:opacity-50"
                                >
                                    <div className="mb-2 aspect-square overflow-hidden rounded-md bg-[var(--color-neutral-subtle)]">
                                        {product.image ? (
                                            <img
                                                src={product.image}
                                                alt={product.name}
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-full items-center justify-center">
                                                <Icon name="package" size={32} className="text-[var(--color-text-muted)]" />
                                            </div>
                                        )}
                                    </div>
                                    <p className="mb-1 line-clamp-2 text-sm font-medium text-[var(--color-text-main)]">
                                        {product.name}
                                    </p>
                                    <p className="text-lg font-bold text-[var(--color-brand)]">
                                        {formatMoney(product.price)}
                                    </p>
                                    <p className="mt-1 text-xs text-[var(--color-text-muted)]">
                                        Stock: {product.stock}
                                    </p>
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Cart Section */}
                <div className="flex w-96 flex-col rounded-lg border border-[var(--color-border-light)] bg-white">
                    {/* Cart Header */}
                    <div className="border-b border-[var(--color-border-light)] p-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold">Cart ({cartItems})</h3>
                            {cart.length > 0 && (
                                <button
                                    onClick={clearCart}
                                    className="text-sm text-[var(--color-danger)] hover:underline"
                                >
                                    Clear
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Cart Items */}
                    <div className="flex-1 overflow-y-auto p-4">
                        {cart.length === 0 ? (
                            <div className="flex h-full flex-col items-center justify-center text-center">
                                <Icon name="shopping-cart" size={48} className="text-[var(--color-text-muted)]" />
                                <p className="mt-4 text-sm text-[var(--color-text-muted)]">
                                    Cart is empty. Add products to start a sale.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {cart.map((item) => (
                                    <div
                                        key={item.product.id}
                                        className="flex gap-3 rounded-lg border border-[var(--color-border-light)] p-3"
                                    >
                                        <div className="flex-1">
                                            <p className="font-medium text-[var(--color-text-main)]">
                                                {item.product.name}
                                            </p>
                                            <p className="mt-0.5 text-sm text-[var(--color-text-muted)]">
                                                {formatMoney(item.product.price)} each
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() => updateQuantity(item.product.id, item.quantity - 1)}
                                                className="flex h-7 w-7 items-center justify-center rounded bg-[var(--color-neutral-subtle)] text-[var(--color-text-main)] hover:bg-[var(--color-neutral-hover)]"
                                            >
                                                <Icon name="minus" size={14} />
                                            </button>
                                            <span className="w-8 text-center font-semibold tabular-nums">
                                                {item.quantity}
                                            </span>
                                            <button
                                                onClick={() => updateQuantity(item.product.id, item.quantity + 1)}
                                                className="flex h-7 w-7 items-center justify-center rounded bg-[var(--color-neutral-subtle)] text-[var(--color-text-main)] hover:bg-[var(--color-neutral-hover)]"
                                            >
                                                <Icon name="plus" size={14} />
                                            </button>
                                        </div>
                                        <p className="w-20 text-right font-semibold tabular-nums">
                                            {formatMoney(item.subtotal)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Cart Footer */}
                    {cart.length > 0 && (
                        <div className="border-t border-[var(--color-border-light)] p-4">
                            {/* Totals */}
                            <div className="space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span>Subtotal</span>
                                    <span className="font-medium tabular-nums">{formatMoney(cartTotal)}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span>Tax (10%)</span>
                                    <span className="font-medium tabular-nums">{formatMoney(tax)}</span>
                                </div>
                                <div className="flex justify-between border-t border-[var(--color-border-light)] pt-2 text-lg font-bold">
                                    <span>Total</span>
                                    <span className="tabular-nums">{formatMoney(grandTotal)}</span>
                                </div>
                            </div>

                            {/* Payment Method */}
                            <div className="mt-4">
                                <label className="mb-2 block text-sm font-medium">Payment Method</label>
                                <div className="grid grid-cols-3 gap-2">
                                    {['cash', 'card', 'upi'].map((method) => (
                                        <button
                                            key={method}
                                            onClick={() => setPaymentMethod(method as typeof paymentMethod)}
                                            className={`rounded-md border py-2 text-sm font-medium capitalize ${
                                                paymentMethod === method
                                                    ? 'border-[var(--color-brand)] bg-[var(--color-brand-subtle)] text-[var(--color-brand)]'
                                                    : 'border-[var(--color-border-light)] hover:border-[var(--color-brand)]'
                                            }`}
                                        >
                                            {method}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Complete Sale Button */}
                            <button onClick={completeSale} className="btn btn-primary mt-4 w-full text-lg">
                                <Icon name="check-circle" size={20} />
                                <span>Complete Sale</span>
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
