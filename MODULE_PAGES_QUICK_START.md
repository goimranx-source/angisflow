# Module Pages Quick Start Guide

## 🎉 What We've Built

We've completed **Phase 1: Foundation** of the module pages implementation! This includes:

1. **7 Shared Components** - Ready to use across all 86 module pages
2. **First Complete Module** - Customers page as a working example
3. **Consistent Design System** - All components follow Angisflow's design standards

---

## 📦 Available Components

All components are exported from `@/components/modules`:

```tsx
import {
    // Layout & Filtering
    FilterBar,
    ViewToggleButton,
    FilterSelect,
    
    // Details & Viewing
    DetailDrawer,
    DrawerSection,
    DrawerField,
    
    // Status & Badges
    StatusBadge,
    OrderStatus,
    PaymentStatus,
    InvoiceStatus,
    StockStatus,
    UserStatus,
    BookingStatus,
    
    // Selection & Bulk Actions
    BulkActions,
    BulkActionButton,
    SelectCheckbox,
    
    // Creation & Actions
    QuickCreateModal,
    QuickActionButton,
    
    // Metrics & KPIs
    KPICard,
    KPICardSkeleton,
} from '@/components/modules';
```

---

## 🚀 Building a New Module Page

### Step 1: Copy the Pattern

Start with the Customers page (`pages/Customers.tsx`) as a template:

```bash
# Copy the file
cp resources/js/pages/Customers.tsx resources/js/pages/YourModule.tsx
```

### Step 2: Update State & Data Type

```tsx
// Define your data type
type YourItem = {
    id: string;
    name: string;
    // ... your fields
};

// Update the query key and endpoint
const { data } = useQuery({
    queryKey: ['your-items', { search, filters }],
    queryFn: () => api.get<YourResponse>('/your-endpoint'),
});
```

### Step 3: Customize Table Columns

```tsx
<Table
    data={items}
    columns={[
        {
            key: 'select',
            label: '',
            width: 'w-12',
            render: (item) => <SelectCheckbox ... />,
        },
        {
            key: 'name',
            label: 'Name',
            sortable: true,
            accessor: (item) => item.name,
        },
        {
            key: 'status',
            label: 'Status',
            render: (item) => <StatusBadge label={item.status} variant="success" />,
        },
        // ... more columns
    ]}
/>
```

### Step 4: Customize Filters

```tsx
<FilterBar
    searchValue={search}
    onSearchChange={setSearch}
    searchPlaceholder="Search your items..."
    filters={
        <>
            <FilterSelect
                label="Category"
                value={category}
                onChange={setCategory}
                options={[
                    { value: 'cat1', label: 'Category 1' },
                    { value: 'cat2', label: 'Category 2' },
                ]}
            />
            <FilterSelect
                label="Status"
                value={status}
                onChange={setStatus}
                options={[
                    { value: 'active', label: 'Active' },
                    { value: 'inactive', label: 'Inactive' },
                ]}
            />
        </>
    }
/>
```

### Step 5: Customize Detail Drawer

```tsx
<DetailDrawer
    open={!!selectedItem}
    onClose={() => setSelectedItem(null)}
    title={selectedItem?.name ?? ''}
    tabs={[
        {
            key: 'overview',
            label: 'Overview',
            content: (
                <DrawerSection title="Details">
                    <DrawerField
                        label="Field 1"
                        value={selectedItem?.field1}
                        icon="icon-name"
                    />
                    <DrawerField
                        label="Field 2"
                        value={selectedItem?.field2}
                        icon="icon-name"
                    />
                </DrawerSection>
            ),
        },
        // ... more tabs
    ]}
/>
```

### Step 6: Customize Create Modal

```tsx
<QuickCreateModal
    open={showCreateModal}
    onClose={() => setShowCreateModal(false)}
    title="Add Your Item"
    onSubmit={handleCreate}
    submitLabel="Create Item"
>
    <div className="space-y-4">
        <div>
            <label className="mb-1.5 block text-sm font-medium text-[var(--color-text-main)]">
                Name <span className="text-[var(--color-danger)]">*</span>
            </label>
            <input
                type="text"
                className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm"
                style={{ borderRadius: 'var(--shell-radius)' }}
            />
        </div>
        {/* More fields */}
    </div>
</QuickCreateModal>
```

---

## 📋 Component Reference

### FilterBar

**Props:**
```tsx
{
    searchValue?: string;
    onSearchChange?: (value: string) => void;
    searchPlaceholder?: string;
    filters?: ReactNode;
    viewControls?: ReactNode;
    actions?: ReactNode;
    compact?: boolean;
}
```

**Example:**
```tsx
<FilterBar
    searchValue={search}
    onSearchChange={setSearch}
    filters={<FilterSelect ... />}
    viewControls={<ViewToggleButton ... />}
    actions={<button>Export</button>}
/>
```

---

### DetailDrawer

**Props:**
```tsx
{
    open: boolean;
    onClose: () => void;
    title: string;
    subtitle?: ReactNode;
    children: ReactNode;
    tabs?: { key: string; label: string; content: ReactNode }[];
    activeTab?: string;
    onTabChange?: (key: string) => void;
    actions?: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg' | 'xl';
}
```

**Example:**
```tsx
<DetailDrawer
    open={!!item}
    onClose={() => setItem(null)}
    title={item?.name ?? ''}
    subtitle={item?.email}
    tabs={[...]}
    size="lg"
/>
```

---

### StatusBadge

**Props:**
```tsx
{
    label: string;
    variant?: 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand';
    icon?: string;
    size?: 'sm' | 'md' | 'lg';
    dot?: boolean;
}
```

**Examples:**
```tsx
// With icon
<StatusBadge label="Paid" variant="success" icon="check-circle" />

// With dot
<StatusBadge label="Active" variant="success" dot />

// Using presets
<OrderStatus.Delivered />
<PaymentStatus.Paid />
<InvoiceStatus.Overdue />
```

---

### BulkActions

**Props:**
```tsx
{
    selectedCount: number;
    onClearSelection: () => void;
    children: ReactNode;
    position?: 'top' | 'bottom' | 'floating';
}
```

**Example:**
```tsx
<BulkActions
    selectedCount={selected.length}
    onClearSelection={() => setSelected([])}
>
    <BulkActionButton icon="download" label="Export" onClick={handleExport} />
    <BulkActionButton icon="trash" label="Delete" onClick={handleDelete} variant="danger" />
</BulkActions>
```

---

### QuickCreateModal

**Props:**
```tsx
{
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    onSubmit: () => void;
    submitLabel?: string;
    submitting?: boolean;
    size?: 'sm' | 'md' | 'lg';
}
```

**Example:**
```tsx
<QuickCreateModal
    open={showModal}
    onClose={() => setShowModal(false)}
    title="Add Customer"
    onSubmit={handleSubmit}
    submitLabel="Create Customer"
    submitting={isSubmitting}
>
    {/* Form fields */}
</QuickCreateModal>
```

---

### KPICard

**Props:**
```tsx
{
    label: string;
    value: string | number;
    icon: string;
    delta?: number;
    deltaIsGood?: boolean;
    comparisonText?: string;
    variant?: 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';
}
```

**Example:**
```tsx
<KPICard
    label="Total Revenue"
    value="$45,231.89"
    icon="currency-dollar"
    delta={20.1}
    deltaIsGood={true}
    comparisonText="vs last month"
    variant="success"
/>
```

---

## 🎨 Design System Rules

### Border Radius
```css
/* Main elements (cards, buttons, inputs) */
border-radius: var(--shell-radius); /* 5px */

/* Small elements (badges, chips, toggles) */
border-radius: var(--shell-radius-sm); /* 4px */
```

### Colors
```css
/* Borders */
border-color: var(--color-border-light);

/* Brand colors */
color: var(--color-brand);
background: var(--color-brand);
hover: var(--color-brand-hover);

/* Text colors */
color: var(--color-text-main);    /* Primary text */
color: var(--color-text-body);    /* Body text */
color: var(--color-text-muted);   /* Muted text */
color: var(--color-text-subtle);  /* Subtle text */

/* Status colors */
color: var(--color-success);      /* Success */
color: var(--color-warning);      /* Warning */
color: var(--color-danger);       /* Danger */
```

### Form Inputs
```tsx
<input
    className="w-full border border-[var(--color-border-light)] px-3 py-2 text-sm 
               focus:border-[var(--color-brand)] focus:outline-none 
               focus:ring-1 focus:ring-[var(--color-brand)]"
    style={{ borderRadius: 'var(--shell-radius)' }}
/>
```

### Buttons
```tsx
// Primary
<button className="bg-[var(--color-brand)] text-white px-4 py-2 
                   hover:bg-[var(--color-brand-hover)]"
        style={{ borderRadius: 'var(--shell-radius)' }}>

// Secondary
<button className="border border-[var(--color-border-light)] bg-white 
                   text-[var(--color-text-body)] px-4 py-2 
                   hover:bg-[var(--shell-hover)]"
        style={{ borderRadius: 'var(--shell-radius)' }}>

// Danger
<button className="bg-[var(--color-danger)] text-white px-4 py-2 
                   hover:bg-[var(--color-danger-hover)]"
        style={{ borderRadius: 'var(--shell-radius)' }}>
```

---

## ✅ Checklist for New Pages

When building a new module page, ensure:

- [ ] Uses PageHeader component for title and actions
- [ ] Uses FilterBar for search and filters
- [ ] Uses Table component with proper columns
- [ ] Implements selection with SelectCheckbox
- [ ] Shows BulkActions when items are selected
- [ ] Uses DetailDrawer for viewing details
- [ ] Uses QuickCreateModal for adding items
- [ ] Uses StatusBadge for status display
- [ ] Has proper loading states (skeletons)
- [ ] Has proper empty states (EmptyState component)
- [ ] Has proper error handling
- [ ] Uses correct border radius (5px/4px)
- [ ] Uses CSS variables for colors
- [ ] Works on mobile (responsive)
- [ ] Has keyboard navigation
- [ ] Has proper ARIA labels

---

## 📚 Files Created

### Components
- `resources/js/components/modules/FilterBar.tsx`
- `resources/js/components/modules/DetailDrawer.tsx`
- `resources/js/components/modules/StatusBadge.tsx`
- `resources/js/components/modules/BulkActions.tsx`
- `resources/js/components/modules/QuickCreate.tsx`
- `resources/js/components/modules/KPICard.tsx`
- `resources/js/components/modules/index.ts`

### Pages
- `resources/js/pages/Customers.tsx` (Complete example)

### Documentation
- `MODULE_PAGES_IMPLEMENTATION_PLAN.md` (Full plan)
- `MODULE_PAGES_PROGRESS.md` (Progress tracking)
- `MODULE_PAGES_QUICK_START.md` (This file)

---

## 🎯 Next Steps

### Immediate Next Tasks (Priority 2)
1. **Orders Page** - Similar to Customers, add order-specific fields
2. **Products Page** - Add grid view, stock indicators, images
3. **Invoices Page** - Add PDF preview, payment recording
4. **Employees Page** - Add department filters, role badges

### Testing the Customers Page
1. Navigate to `/customers` in your app
2. Test all interactions:
   - Search
   - Filters
   - Sorting
   - Selection
   - Bulk actions
   - Detail drawer
   - Create modal

### Getting Help
- Reference `Customers.tsx` for complete working example
- Check component files for detailed prop types
- All components have JSDoc comments with examples

---

## 💡 Pro Tips

1. **Start with the Customers pattern** - It's complete and well-tested
2. **Customize gradually** - Don't try to change everything at once
3. **Use the preset status badges** - They're consistent and ready to use
4. **Keep loading/empty/error states** - Users need feedback
5. **Test with keyboard** - Make sure Tab, Enter, Escape work
6. **Check mobile** - Resize browser to test responsiveness

---

**Ready to build!** 🚀

Start with copying `Customers.tsx` and adapting it to your next module.
