# 🚀 Implementation Guide - Making Modules Live

This guide shows you how to implement the newly created modules following the existing Angisflow patterns.

---

## ✅ COMPLETED (Database Layer)

- ✅ 10 database migrations created
- ✅ CatalogueSeeder updated with all modules marked as built
- ✅ Module structure documented

---

## 📋 REMAINING TASKS

### 1. Run Migrations

```bash
cd "d:\Povaly Group\Applications\angisflow\angisflow"
php artisan migrate
php artisan db:seed --class=CatalogueSeeder
```

This will:
- Create all database tables
- Register all modules in the catalogue
- Make modules appear in the sidebar

---

### 2. Create Eloquent Models

For each module, create domain models. Example for **Leads**:

**File**: `app/Domain/Revenue/Models/Lead.php`

```php
<?php

namespace App\Domain\Revenue\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;

    protected $fillable = [
        'public_id', 'account_id', 'business_id', 'pipeline_id', 'stage_id',
        'assigned_to', 'customer_id', 'title', 'company', 'contact_name',
        'email', 'phone', 'status', 'source', 'currency', 'value_minor',
        'probability', 'description', 'address', 'notes', 'expected_close_date',
        'won_at', 'lost_at', 'lost_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value_minor' => 'integer',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }
}
```

Create similar models for:
- Pipeline, PipelineStage, LeadActivity
- Service, ServiceCategory, RateCard
- Supplier, PurchaseOrder
- Batch, SerialNumber
- PriceList, PriceListItem
- ExpenseClaim, ExpenseCategory
- ReferralProgram, Referral
- Form, FormSubmission, LandingPage
- PaymentLink
- Dashboard, AlertRule, ForecastModel, AIQuery

---

### 3. Create API Controllers

**File**: `app/Http/Api/V1/LeadController.php`

```php
<?php

namespace App\Http\Api\V1;

use App\Domain\Revenue\Models\Lead;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $leads = Lead::query()
            ->with(['pipeline', 'stage', 'assignedTo'])
            ->when($request->search, fn($q, $search) => 
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
            )
            ->when($request->status, fn($q, $status) => 
                $q->where('status', $status)
            )
            ->when($request->pipeline_id, fn($q, $id) => 
                $q->where('pipeline_id', $id)
            )
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_direction ?? 'desc')
            ->paginate($request->per_page ?? 25);

        $summary = [
            'total_leads' => Lead::count(),
            'total_value' => Lead::where('status', 'open')->sum('value_minor'),
            'open_count' => Lead::where('status', 'open')->count(),
            'won_count' => Lead::where('status', 'won')->count(),
        ];

        return response()->json([
            'data' => $leads->items(),
            'summary' => $summary,
            'meta' => [
                'total' => $leads->total(),
                'per_page' => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'pipeline_id' => 'required|exists:pipelines,id',
            'stage_id' => 'required|exists:pipeline_stages,id',
            'title' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'value_minor' => 'nullable|integer',
            'expected_close_date' => 'nullable|date',
        ]);

        $lead = Lead::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($lead->load(['pipeline', 'stage']), 201);
    }

    public function show(string $id)
    {
        $lead = Lead::with(['pipeline', 'stage', 'assignedTo', 'activities.user'])
            ->where('public_id', $id)
            ->firstOrFail();

        return response()->json($lead);
    }

    public function update(Request $request, string $id)
    {
        $lead = Lead::where('public_id', $id)->firstOrFail();

        $validated = $request->validate([
            'stage_id' => 'sometimes|exists:pipeline_stages,id',
            'assigned_to' => 'nullable|exists:users,id',
            'title' => 'sometimes|string|max:255',
            'value_minor' => 'nullable|integer',
            'probability' => 'nullable|integer|min:0|max:100',
        ]);

        $lead->update($validated);

        return response()->json($lead->fresh(['pipeline', 'stage']));
    }

    public function destroy(string $id)
    {
        $lead = Lead::where('public_id', $id)->firstOrFail();
        $lead->delete();

        return response()->json(null, 204);
    }
}
```

Create similar controllers for all other modules.

---

### 4. Register API Routes

**File**: `routes/api.php`

Add in the session middleware group:

```php
// Leads & Pipeline
Route::prefix('leads')->name('leads.')->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('index');
    Route::post('/', [LeadController::class, 'store'])->name('store');
    Route::get('{id}', [LeadController::class, 'show'])->name('show');
    Route::patch('{id}', [LeadController::class, 'update'])->name('update');
    Route::delete('{id}', [LeadController::class, 'destroy'])->name('destroy');
});

Route::prefix('pipelines')->name('pipelines.')->group(function () {
    Route::get('/', [PipelineController::class, 'index'])->name('index');
    Route::get('{id}', [PipelineController::class, 'show'])->name('show');
    Route::get('{id}/board', [PipelineController::class, 'board'])->name('board');
});

// Services
Route::prefix('services')->name('services.')->group(function () {
    Route::get('/', [ServiceController::class, 'index'])->name('index');
    Route::post('/', [ServiceController::class, 'store'])->name('store');
    Route::get('{id}', [ServiceController::class, 'show'])->name('show');
    Route::patch('{id}', [ServiceController::class, 'update'])->name('update');
    Route::delete('{id}', [ServiceController::class, 'destroy'])->name('destroy');
});

// Purchasing
Route::prefix('purchasing')->name('purchasing.')->group(function () {
    Route::get('suppliers', [PurchasingController::class, 'suppliers'])->name('suppliers');
    Route::get('orders', [PurchasingController::class, 'orders'])->name('orders');
    Route::post('orders', [PurchasingController::class, 'createOrder'])->name('orders.store');
    Route::post('orders/{id}/receive', [PurchasingController::class, 'receiveGoods'])->name('orders.receive');
});

// Expense Claims
Route::prefix('expenses')->name('expenses.')->group(function () {
    Route::get('/', [ExpenseController::class, 'index'])->name('index');
    Route::post('/', [ExpenseController::class, 'store'])->name('store');
    Route::post('{id}/submit', [ExpenseController::class, 'submit'])->name('submit');
    Route::post('{id}/approve', [ExpenseController::class, 'approve'])->name('approve');
    Route::post('{id}/reject', [ExpenseController::class, 'reject'])->name('reject');
});

// Referrals
Route::prefix('referrals')->name('referrals.')->group(function () {
    Route::get('programs', [ReferralController::class, 'programs'])->name('programs');
    Route::get('codes', [ReferralController::class, 'codes'])->name('codes');
    Route::get('/', [ReferralController::class, 'referrals'])->name('index');
    Route::post('/', [ReferralController::class, 'createReferral'])->name('store');
});

// Forms & Landing Pages
Route::prefix('forms')->name('forms.')->group(function () {
    Route::get('/', [FormController::class, 'index'])->name('index');
    Route::post('/', [FormController::class, 'store'])->name('store');
    Route::get('{id}', [FormController::class, 'show'])->name('show');
    Route::get('{id}/submissions', [FormController::class, 'submissions'])->name('submissions');
});

Route::prefix('landing-pages')->name('landing_pages.')->group(function () {
    Route::get('/', [LandingPageController::class, 'index'])->name('index');
    Route::post('/', [LandingPageController::class, 'store'])->name('store');
    Route::get('{id}/analytics', [LandingPageController::class, 'analytics'])->name('analytics');
});

// Payment Links
Route::prefix('payment-links')->name('payment_links.')->group(function () {
    Route::get('/', [PaymentLinkController::class, 'index'])->name('index');
    Route::post('/', [PaymentLinkController::class, 'store'])->name('store');
    Route::get('{id}', [PaymentLinkController::class, 'show'])->name('show');
    Route::get('{id}/transactions', [PaymentLinkController::class, 'transactions'])->name('transactions');
});

// Intelligence
Route::prefix('intelligence')->name('intelligence.')->group(function () {
    Route::post('ask', [IntelligenceController::class, 'askAngisflow'])->name('ask');
    Route::get('dashboards', [IntelligenceController::class, 'dashboards'])->name('dashboards');
    Route::post('dashboards', [IntelligenceController::class, 'createDashboard'])->name('dashboards.store');
    Route::get('reports', [IntelligenceController::class, 'reports'])->name('reports');
    Route::post('reports/generate', [IntelligenceController::class, 'generateReport'])->name('reports.generate');
    Route::get('alerts', [IntelligenceController::class, 'alerts'])->name('alerts');
    Route::post('alerts', [IntelligenceController::class, 'createAlert'])->name('alerts.store');
    Route::get('forecasts', [IntelligenceController::class, 'forecasts'])->name('forecasts');
});
```

---

### 5. Create Frontend Pages

**File**: `resources/js/pages/Leads.tsx`

```tsx
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    FilterBar,
    FilterSelect,
    DetailDrawer,
    DrawerSection,
    DrawerField,
    StatusBadge,
    KPICard,
} from '@/components/modules';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/ui/PageHeader';
import { Table } from '@/components/ui/Table';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { api } from '@/lib/api';

type Lead = {
    id: string;
    title: string;
    contact_name: string;
    email: string;
    phone: string;
    company: string;
    status: 'open' | 'won' | 'lost';
    stage: { id: string; name: string; color: string };
    pipeline: { id: string; name: string };
    value_minor: number;
    probability: number;
    expected_close_date: string;
    created_at: string;
};

export default function Leads() {
    useDocumentTitle('Leads');
    
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [selectedLead, setSelectedLead] = useState<Lead | null>(null);
    
    const { data, isLoading } = useQuery({
        queryKey: ['leads', { search, statusFilter }],
        queryFn: ({ signal }) =>
            api.get('/leads', {
                params: { search, status: statusFilter },
                signal,
            }),
    });
    
    const leads = data?.data ?? [];
    const summary = data?.summary;
    
    const formatMoney = (minor: number) =>
        new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' })
            .format(minor / 100);
    
    return (
        <div className="space-y-6">
            <PageHeader
                title="Leads"
                description="Manage your sales pipeline"
                action={{ label: 'Add Lead', onClick: () => {} }}
            />
            
            {summary && (
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <KPICard title="Total Leads" value={summary.total_leads} />
                    <KPICard title="Pipeline Value" value={formatMoney(summary.total_value)} />
                    <KPICard title="Open" value={summary.open_count} />
                    <KPICard title="Won" value={summary.won_count} trend="up" />
                </div>
            )}
            
            <FilterBar search={search} onSearchChange={setSearch}>
                <FilterSelect
                    label="Status"
                    value={statusFilter}
                    onChange={setStatusFilter}
                    options={[
                        { value: '', label: 'All' },
                        { value: 'open', label: 'Open' },
                        { value: 'won', label: 'Won' },
                        { value: 'lost', label: 'Lost' },
                    ]}
                />
            </FilterBar>
            
            <Table
                data={leads}
                isLoading={isLoading}
                columns={[
                    { key: 'title', label: 'Title', sortable: true },
                    { key: 'contact_name', label: 'Contact', sortable: true },
                    { key: 'company', label: 'Company' },
                    {
                        key: 'stage',
                        label: 'Stage',
                        render: (lead) => (
                            <StatusBadge color={lead.stage.color}>
                                {lead.stage.name}
                            </StatusBadge>
                        ),
                    },
                    {
                        key: 'value_minor',
                        label: 'Value',
                        render: (lead) => formatMoney(lead.value_minor),
                    },
                    {
                        key: 'probability',
                        label: 'Probability',
                        render: (lead) => `${lead.probability}%`,
                    },
                ]}
                onRowClick={(lead) => setSelectedLead(lead)}
            />
            
            {selectedLead && (
                <DetailDrawer
                    open={!!selectedLead}
                    onClose={() => setSelectedLead(null)}
                    title={selectedLead.title}
                >
                    <DrawerSection title="Contact Info">
                        <DrawerField label="Name" value={selectedLead.contact_name} />
                        <DrawerField label="Email" value={selectedLead.email} />
                        <DrawerField label="Phone" value={selectedLead.phone} />
                        <DrawerField label="Company" value={selectedLead.company} />
                    </DrawerSection>
                    
                    <DrawerSection title="Deal Info">
                        <DrawerField label="Value" value={formatMoney(selectedLead.value_minor)} />
                        <DrawerField label="Probability" value={`${selectedLead.probability}%`} />
                        <DrawerField label="Expected Close" value={selectedLead.expected_close_date} />
                    </DrawerSection>
                </DetailDrawer>
            )}
        </div>
    );
}
```

Create similar pages for all modules following the existing patterns in:
- `Orders.tsx`
- `Campaigns.tsx`
- `Products.tsx`

---

### 6. Register Frontend Routes

**File**: `resources/js/router.tsx`

Add to the `pages` object:

```tsx
const pages = {
    // ... existing pages
    leads: () => import('@/pages/Leads'),
    pipeline: () => import('@/pages/Pipeline'),
    services: () => import('@/pages/Services'),
    purchasing: () => import('@/pages/Purchasing'),
    batches: () => import('@/pages/Batches'),
    priceLists: () => import('@/pages/PriceLists'),
    expenses: () => import('@/pages/Expenses'),
    referrals: () => import('@/pages/Referrals'),
    forms: () => import('@/pages/Forms'),
    paymentLinks: () => import('@/pages/PaymentLinks'),
    askAngisflow: () => import('@/pages/AskAngisflow'),
    dashboards: () => import('@/pages/Dashboards'),
    aiReports: () => import('@/pages/AIReports'),
    alerts: () => import('@/pages/Alerts'),
    forecasting: () => import('@/pages/Forecasting'),
};
```

Add routes in the Shell children:

```tsx
{ path: '/leads', element: page(pages.leads) },
{ path: '/pipeline', element: page(pages.pipeline) },
{ path: '/services', element: page(pages.services) },
{ path: '/purchasing', element: page(pages.purchasing) },
{ path: '/batches', element: page(pages.batches) },
{ path: '/price-lists', element: page(pages.priceLists) },
{ path: '/expenses', element: page(pages.expenses) },
{ path: '/referrals', element: page(pages.referrals) },
{ path: '/forms', element: page(pages.forms) },
{ path: '/payment-links', element: page(pages.paymentLinks) },
{ path: '/ask', element: page(pages.askAngisflow) },
{ path: '/dashboards', element: page(pages.dashboards) },
{ path: '/ai-reports', element: page(pages.aiReports) },
{ path: '/alerts', element: page(pages.alerts) },
{ path: '/forecasting', element: page(pages.forecasting) },
```

---

## 🎯 Implementation Priority

Implement in this order for fastest results:

1. **Leads & Pipeline** (high-value, frequently used)
2. **Services** (needed by multiple categories)
3. **Expense Claims** (employee-facing, important)
4. **Payment Links** (revenue-generating, easy to implement)
5. **Referrals** (marketing automation)
6. **Forms** (lead generation)
7. **Dashboards** (visibility & insights)
8. **Purchasing** (operations)
9. **Price Lists** (advanced pricing)
10. **Batches** (compliance & tracking)
11. **AI Reports, Alerts, Forecasting, Ask Angisflow** (advanced intelligence)

---

## 📚 Reference Examples

Study these existing modules for patterns:

- **Orders** (`app/Domain/Sales/`, `resources/js/pages/Orders.tsx`) - Complex CRUD with relationships
- **Campaigns** - List/detail pattern with analytics
- **Loyalty** - Multiple related entities (programs, memberships, points)
- **Products** - Catalog management with variants

---

## ✅ Quick Win: Test One Module End-to-End

To verify everything works, implement **Leads** fully:

1. ✅ Migration exists
2. Create `Lead.php` model
3. Create `LeadController.php`
4. Add routes in `api.php`
5. Create `Leads.tsx` page
6. Add route in `router.tsx`
7. Test in browser at `/leads`

Once Leads works, replicate for other modules!

---

**You're now ready to bring all 51 modules to life! 🚀**
