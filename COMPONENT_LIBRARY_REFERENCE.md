# Angisflow UI Component Library — Quick Reference

**Version:** 1.0  
**Last Updated:** 2026-08-14  
**Status:** Production Ready

---

## 📑 Table of Contents

1. [Form Components](#form-components)
2. [Modal System](#modal-system)
3. [Navigation & Layout](#navigation--layout)
4. [Existing Components](#existing-components)
5. [Import Paths](#import-paths)
6. [Common Patterns](#common-patterns)

---

## Form Components

### Input
**Path:** `resources/js/components/ui/Form/Input.tsx`

```tsx
<Input
  label="Email"
  type="email"
  value={email}
  onChange={(e) => setEmail(e.target.value)}
  placeholder="you@example.com"
  error={errors.email}
  size="md"
  required
/>
```

**Props:**
- `size?: 'sm' | 'md' | 'lg'` - Size variant
- `error?: string` - Error message to display
- `label?: string` - Label text
- All standard input props

---

### Textarea
**Path:** `resources/js/components/ui/Form/Textarea.tsx`

```tsx
<Textarea
  label="Description"
  value={description}
  onChange={(e) => setDescription(e.target.value)}
  rows={4}
  autoResize
  maxLength={500}
  showCount
  error={errors.description}
/>
```

**Props:**
- `autoResize?: boolean` - Auto-adjust height
- `maxLength?: number` - Character limit
- `showCount?: boolean` - Show character count
- `size?: 'sm' | 'md' | 'lg'`

---

### Select
**Path:** `resources/js/components/ui/Form/Select.tsx`

```tsx
<Select
  label="Category"
  value={category}
  onChange={(e) => setCategory(e.target.value)}
  error={errors.category}
>
  <option value="">Select category</option>
  <option value="electronics">Electronics</option>
  <option value="clothing">Clothing</option>
</Select>
```

**Props:**
- `size?: 'sm' | 'md' | 'lg'`
- `error?: string`
- `label?: string`
- `placeholder?: string` - Shows as first disabled option

---

### Checkbox
**Path:** `resources/js/components/ui/Form/Checkbox.tsx`

```tsx
<Checkbox
  label="I agree to the terms"
  description="You must accept the terms to continue"
  checked={agreed}
  onChange={(e) => setAgreed(e.target.checked)}
  error={errors.agreed}
  required
/>
```

**Props:**
- `label?: string` - Main label
- `description?: string` - Helper text below label
- `error?: string`
- `size?: 'sm' | 'md' | 'lg'`

---

### Radio
**Path:** `resources/js/components/ui/Form/Radio.tsx`

```tsx
<Radio
  name="plan"
  value="pro"
  label="Pro Plan"
  description="$29/month"
  checked={plan === 'pro'}
  onChange={(e) => setPlan(e.target.value)}
/>
```

**Props:**
- `label?: string`
- `description?: string`
- `error?: string`
- `size?: 'sm' | 'md' | 'lg'`

---

### Switch
**Path:** `resources/js/components/ui/Form/Switch.tsx`

```tsx
<Switch
  label="Email notifications"
  description="Receive emails about your account"
  checked={emailNotifs}
  onChange={(e) => setEmailNotifs(e.target.checked)}
/>
```

**Props:**
- `label?: string`
- `description?: string`
- `error?: string`
- `size?: 'sm' | 'md' | 'lg'`

---

### SearchInput
**Path:** `resources/js/components/ui/Form/SearchInput.tsx`

```tsx
<SearchInput
  placeholder="Search products..."
  onSearch={(value) => setSearchQuery(value)}
  debounceMs={300}
  loading={isSearching}
  fullWidth
/>
```

**Props:**
- `onSearch: (value: string) => void` - Debounced callback
- `debounceMs?: number` - Debounce delay (default 300)
- `loading?: boolean` - Show loading spinner
- `fullWidth?: boolean` - Take full width
- `size?: 'sm' | 'md' | 'lg'`

---

### FileUpload
**Path:** `resources/js/components/ui/Form/FileUpload.tsx`

```tsx
<FileUpload
  onFilesSelected={(files) => handleUpload(files)}
  accept="image/*"
  multiple
  maxSize={5 * 1024 * 1024} // 5MB
  showPreview
  error={errors.files}
/>
```

**Props:**
- `onFilesSelected: (files: File[]) => void` - Callback with files
- `accept?: string` - File types (MIME or extension)
- `multiple?: boolean` - Allow multiple files
- `maxSize?: number` - Max file size in bytes
- `showPreview?: boolean` - Show file list
- `compact?: boolean` - Smaller variant

---

### MoneyInput
**Path:** `resources/js/components/ui/Form/MoneyInput.tsx`

```tsx
<MoneyInput
  label="Price"
  currency="USD"
  value={price} // Integer minor units (150000 = $1,500.00)
  onChange={(value) => setPrice(value)}
  error={errors.price}
  allowNegative={false}
/>
```

**Props:**
- `currency: string` - Currency code (USD, EUR, GBP, etc.)
- `value: number` - Amount in minor units (cents)
- `onChange: (value: number) => void` - New value in minor units
- `allowNegative?: boolean` - Allow negative amounts
- `size?: 'sm' | 'md' | 'lg'`

**Important:** Values are always integer minor units (150000 = $1,500.00)

---

## Modal System

### Modal
**Path:** `resources/js/components/ui/Modal.tsx`

```tsx
<Modal
  open={isOpen}
  onClose={() => setIsOpen(false)}
  title="Edit Product"
  description="Update product information"
  size="lg"
  footer={
    <>
      <Button variant="ghost" onClick={() => setIsOpen(false)}>
        Cancel
      </Button>
      <Button onClick={handleSave}>Save</Button>
    </>
  }
>
  <ProductForm data={product} />
</Modal>
```

**Props:**
- `open: boolean` - Whether modal is visible
- `onClose: () => void` - Close callback
- `title?: string` - Modal title
- `description?: string` - Modal description
- `size?: 'sm' | 'md' | 'lg' | 'xl' | 'full'`
- `footer?: ReactNode` - Footer content (buttons)
- `showClose?: boolean` - Show X button (default true)
- `closeOnClickOutside?: boolean` - Click outside to close (default true)

---

### Confirm
**Path:** `resources/js/components/ui/Confirm.tsx`

```tsx
<Confirm
  open={showConfirm}
  onClose={() => setShowConfirm(false)}
  onConfirm={async () => {
    await deleteItem(id);
    setShowConfirm(false);
  }}
  title="Delete item"
  description="This will permanently delete this item. This action cannot be undone."
  confirmText="Delete"
  cancelText="Cancel"
  variant="danger"
/>
```

**Props:**
- `open: boolean`
- `onClose: () => void`
- `onConfirm: () => void | Promise<void>` - Async supported
- `title: string`
- `description?: string`
- `confirmText?: string` - Confirm button text
- `cancelText?: string` - Cancel button text
- `variant?: 'default' | 'danger' | 'warning'`
- `loading?: boolean` - External loading state

---

## Navigation & Layout

### Dropdown
**Path:** `resources/js/components/ui/Dropdown.tsx`

```tsx
<Dropdown
  trigger={<Button>Actions</Button>}
  align="end"
  closeOnClick={true}
>
  <DropdownItem onClick={() => handleEdit()}>
    Edit
  </DropdownItem>
  <DropdownSeparator />
  <DropdownItem variant="danger" onClick={() => handleDelete()}>
    Delete
  </DropdownItem>
</Dropdown>
```

**Components:**
- `Dropdown` - Container
- `DropdownItem` - Menu item
- `DropdownSeparator` - Visual separator
- `DropdownLabel` - Section header

**Props (Dropdown):**
- `trigger: ReactNode` - Element that opens dropdown
- `align?: 'start' | 'end' | 'center'`
- `side?: 'top' | 'bottom' | 'left' | 'right'`
- `closeOnClick?: boolean` - Close on item click

---

### Tabs
**Path:** `resources/js/components/ui/Tabs.tsx`

```tsx
<Tabs defaultValue="general">
  <TabsList>
    <TabsTrigger value="general">General</TabsTrigger>
    <TabsTrigger value="security" badge={3}>Security</TabsTrigger>
    <TabsTrigger value="billing">Billing</TabsTrigger>
  </TabsList>
  
  <TabsContent value="general">
    <p>General settings content</p>
  </TabsContent>
  <TabsContent value="security">
    <p>Security settings content</p>
  </TabsContent>
</Tabs>
```

**Components:**
- `Tabs` - Container
- `TabsList` - Tab button container
- `TabsTrigger` - Individual tab button
- `TabsContent` - Tab panel

**Props (Tabs):**
- `defaultValue?: string` - Initial active tab (uncontrolled)
- `value?: string` - Active tab (controlled)
- `onValueChange?: (value: string) => void`

**Props (TabsList):**
- `variant?: 'default' | 'pills'` - Visual style

---

### Alert
**Path:** `resources/js/components/ui/Alert.tsx`

```tsx
<Alert
  variant="success"
  title="Success!"
  dismissible
  onDismiss={() => setShowAlert(false)}
>
  Your settings have been saved.
</Alert>
```

**Props:**
- `variant?: 'info' | 'success' | 'warning' | 'error'`
- `title?: string`
- `dismissible?: boolean`
- `onDismiss?: () => void`
- `icon?: string` - Custom icon (Phosphor name)

---

### Breadcrumb
**Path:** `resources/js/components/ui/Breadcrumb.tsx`

```tsx
<Breadcrumb
  items={[
    { label: 'Home', href: '/' },
    { label: 'Products', href: '/products' },
    { label: 'Edit Product' }, // Current page (no href)
  ]}
  separator="/"
/>
```

**Props:**
- `items: Array<{ label: string; href?: string; icon?: string }>`
- `separator?: string` - Custom separator
- `maxItems?: number` - Collapse after N items

---

### Pagination
**Path:** `resources/js/components/ui/Pagination.tsx`

```tsx
<Pagination
  currentPage={3}
  totalPages={10}
  onPageChange={(page) => setCurrentPage(page)}
  totalItems={248}
  showPageSize
  pageSize={25}
  pageSizes={[10, 25, 50, 100]}
  onPageSizeChange={(size) => setPageSize(size)}
/>
```

**Props:**
- `currentPage: number` - Current page (1-indexed)
- `totalPages: number` - Total page count
- `onPageChange: (page: number) => void`
- `totalItems?: number` - Show "1-25 of 248"
- `showPageSize?: boolean` - Show page size selector
- `pageSize?: number` - Current page size
- `pageSizes?: number[]` - Available page sizes
- `onPageSizeChange?: (size: number) => void`
- `siblingCount?: number` - Pages around current (default 1)
- `showFirstLast?: boolean` - Show first/last buttons

---

### StatsCard
**Path:** `resources/js/components/ui/StatsCard.tsx`

```tsx
<StatsCard
  label="Total Revenue"
  value="$48,500"
  icon="currency-dollar"
  delta={12.5}
  direction="up"
  riseIsGood={true}
  trendLabel="vs last month"
  onClick={() => navigate('/revenue')}
/>
```

**Components:**
- `StatsCard` - Individual stat card
- `StatsGrid` - Grid container

**Props (StatsCard):**
- `label: string` - Card label
- `value: string | number` - Main value
- `icon?: string` - Phosphor icon name
- `iconElement?: ReactNode` - Custom icon
- `delta?: number` - Change percentage
- `direction?: 'up' | 'down' | 'flat'`
- `riseIsGood?: boolean` - Color trend accordingly
- `trendLabel?: string` - e.g., "vs last month"
- `onClick?: () => void` - Make card clickable

**Props (StatsGrid):**
- `columns?: { sm?: 1|2, md?: 2|3|4, lg?: 2|3|4, xl?: 2|3|4|5|6 }`

---

### Tooltip
**Path:** `resources/js/components/ui/Tooltip.tsx`

```tsx
<Tooltip content="Click to edit" position="top" delay={200}>
  <button>Edit</button>
</Tooltip>

{/* Helper for inline info icons */}
<label>
  Username
  <TooltipIcon content="Must be 3-20 characters" />
</label>
```

**Components:**
- `Tooltip` - Main wrapper
- `TooltipIcon` - Info icon helper

**Props (Tooltip):**
- `content: ReactNode` - Tooltip content
- `position?: 'top' | 'bottom' | 'left' | 'right'`
- `delay?: number` - Show delay in ms (default 200)
- `disabled?: boolean`

---

### Accordion
**Path:** `resources/js/components/ui/Accordion.tsx`

```tsx
<Accordion defaultValue="item-1" allowMultiple={false}>
  <AccordionItem value="item-1">
    <AccordionTrigger>What is Angisflow?</AccordionTrigger>
    <AccordionContent>
      Angisflow is an all-in-one business management platform.
    </AccordionContent>
  </AccordionItem>
  
  <AccordionItem value="item-2">
    <AccordionTrigger>How does pricing work?</AccordionTrigger>
    <AccordionContent>
      We offer flexible pricing based on your needs.
    </AccordionContent>
  </AccordionItem>
</Accordion>
```

**Components:**
- `Accordion` - Container
- `AccordionItem` - Individual item
- `AccordionTrigger` - Clickable header
- `AccordionContent` - Collapsible content

**Props (Accordion):**
- `defaultValue?: string | string[]` - Initial open items
- `value?: string | string[]` - Controlled mode
- `onValueChange?: (value: string | string[]) => void`
- `allowMultiple?: boolean` - Multiple items open (default false)

---

### DatePicker
**Path:** `resources/js/components/ui/DatePicker.tsx`

```tsx
<DatePicker
  label="Start Date"
  value={startDate}
  onChange={(e) => setStartDate(e.target.value)}
  min="2024-01-01"
  max="2024-12-31"
  required
  error={errors.startDate}
/>

{/* Date range */}
<DateRangePicker
  startDate={startDate}
  endDate={endDate}
  onStartDateChange={setStartDate}
  onEndDateChange={setEndDate}
  startLabel="From"
  endLabel="To"
/>
```

**Components:**
- `DatePicker` - Single date picker
- `DateRangePicker` - Start/End date picker

**Props (DatePicker):**
- `label?: string`
- `value?: string` - YYYY-MM-DD format
- `onChange?: (e: ChangeEvent) => void`
- `min?: string` - Min date
- `max?: string` - Max date
- `error?: string`
- `helperText?: string`
- `size?: 'sm' | 'md' | 'lg'`

---

## Existing Components

### Button
**Path:** `resources/js/components/ui/Button.tsx`

```tsx
<Button variant="primary" size="md" busy={loading}>
  Save Changes
</Button>
```

**Props:**
- `variant?: 'primary' | 'secondary' | 'ghost' | 'danger'`
- `size?: 'sm' | 'md'`
- `busy?: boolean` - Show spinner
- `block?: boolean` - Full width

---

### Badge
**Path:** `resources/js/components/ui/Badge.tsx`

```tsx
<Badge text="AB" icon="buildings" size="md" showFavorite />
<BusinessBadge business={business} workspace={workspace} />
<WorkspaceBadge workspace={workspace} />
```

---

### Table
**Path:** `resources/js/components/ui/Table.tsx`

```tsx
<Table>
  <thead>
    <tr>
      <th>Name</th>
      <th>Email</th>
    </tr>
  </thead>
  <tbody>
    {users.map(user => (
      <tr key={user.id}>
        <td>{user.name}</td>
        <td>{user.email}</td>
      </tr>
    ))}
  </tbody>
</Table>
```

---

### EmptyState
**Path:** `resources/js/components/ui/EmptyState.tsx`

```tsx
<EmptyState
  icon="shopping-cart"
  title="No orders yet"
  description="Create your first order to get started"
  action={<Button onClick={createOrder}>Create Order</Button>}
/>
```

---

### Icon
**Path:** `resources/js/components/ui/Icon.tsx`

```tsx
<Icon name="check" size={16} weight="bold" />
```

**Props:**
- `name: string` - Phosphor icon name
- `size?: number` - Icon size in pixels
- `weight?: 'thin' | 'light' | 'regular' | 'bold' | 'fill' | 'duotone'`

---

### PageHeader
**Path:** `resources/js/components/ui/PageHeader.tsx`

```tsx
<PageHeader
  title="Orders"
  description="Manage your customer orders"
  actions={<Button>Create Order</Button>}
/>
```

---

### Skeleton
**Path:** `resources/js/components/ui/Skeleton.tsx`

```tsx
<Skeleton className="h-10 w-full" />
<SkeletonKpi /> {/* For dashboard KPI cards */}
```

---

## Import Paths

```tsx
// Form components
import { Input } from '@/components/ui/Form/Input';
import { Textarea } from '@/components/ui/Form/Textarea';
import { Select } from '@/components/ui/Form/Select';
import { Checkbox } from '@/components/ui/Form/Checkbox';
import { Radio } from '@/components/ui/Form/Radio';
import { Switch } from '@/components/ui/Form/Switch';
import { SearchInput } from '@/components/ui/Form/SearchInput';
import { FileUpload } from '@/components/ui/Form/FileUpload';
import { MoneyInput } from '@/components/ui/Form/MoneyInput';

// Modal system
import { Modal } from '@/components/ui/Modal';
import { Confirm } from '@/components/ui/Confirm';

// Navigation & layout
import { Dropdown, DropdownItem, DropdownSeparator, DropdownLabel } from '@/components/ui/Dropdown';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/Tabs';
import { Alert } from '@/components/ui/Alert';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { Pagination } from '@/components/ui/Pagination';
import { StatsCard, StatsGrid } from '@/components/ui/StatsCard';
import { Tooltip, TooltipIcon } from '@/components/ui/Tooltip';
import { Accordion, AccordionItem, AccordionTrigger, AccordionContent } from '@/components/ui/Accordion';
import { DatePicker, DateRangePicker } from '@/components/ui/DatePicker';

// Existing components
import { Button } from '@/components/ui/Button';
import { Badge, BusinessBadge, WorkspaceBadge } from '@/components/ui/Badge';
import { Table } from '@/components/ui/Table';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/ui/Icon';
import { PageHeader } from '@/components/ui/PageHeader';
import { Skeleton, SkeletonKpi } from '@/components/ui/Skeleton';
```

---

## Common Patterns

### Form with Validation
```tsx
const [formData, setFormData] = useState({ name: '', email: '' });
const [errors, setErrors] = useState<Record<string, string>>({});

const handleSubmit = async (e: FormEvent) => {
  e.preventDefault();
  
  // Validate
  const newErrors: Record<string, string> = {};
  if (!formData.name) newErrors.name = 'Name is required';
  if (!formData.email) newErrors.email = 'Email is required';
  
  if (Object.keys(newErrors).length > 0) {
    setErrors(newErrors);
    return;
  }
  
  // Submit
  await api.post('/users', formData);
};

return (
  <form onSubmit={handleSubmit} className="space-y-4">
    <Input
      label="Name"
      value={formData.name}
      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
      error={errors.name}
      required
    />
    <Input
      label="Email"
      type="email"
      value={formData.email}
      onChange={(e) => setFormData({ ...formData, email: e.target.value })}
      error={errors.email}
      required
    />
    <Button type="submit">Submit</Button>
  </form>
);
```

### Controlled vs Uncontrolled
```tsx
// Uncontrolled (internal state)
<SearchInput onSearch={(value) => console.log(value)} />
<Accordion defaultValue="item-1">...</Accordion>
<Tabs defaultValue="general">...</Tabs>

// Controlled (external state)
<SearchInput value={query} onChange={setQuery} />
<Accordion value={openItems} onValueChange={setOpenItems}>...</Accordion>
<Tabs value={activeTab} onValueChange={setActiveTab}>...</Tabs>
```

### Async Operations
```tsx
const [loading, setLoading] = useState(false);

const handleDelete = async () => {
  setLoading(true);
  try {
    await api.delete(`/items/${id}`);
    toast.success('Item deleted');
  } catch (error) {
    toast.error('Failed to delete item');
  } finally {
    setLoading(false);
  }
};

return (
  <Confirm
    open={showConfirm}
    onClose={() => setShowConfirm(false)}
    onConfirm={handleDelete}
    loading={loading}
    variant="danger"
    title="Delete item"
  />
);
```

---

**Happy building! 🚀**

