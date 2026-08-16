# Dashboard Implementation Plan

**Date:** 2026-08-13  
**Status:** In Progress

---

## Current Situation

The existing Dashboard (`resources/js/pages/Dashboard.tsx`) shows:
- Basic KPI cards (revenue, orders, customers, etc.)
- Period selector (Today, Last 7 days, This month, Last month, This year)
- Loads data from `/dashboard` API endpoint

**Issue:** The dashboard is generic and doesn't adapt to business category.

---

## Solution: Category-Aware Dashboard System

### Approach 1: Single Dashboard with Category-Aware Widgets ✅ (Recommended)

Keep one Dashboard page, but show different widget combinations based on business category.

**Pros:**
- Single route (`/dashboard`)
- Backend can return category-specific data
- Frontend adapts UI based on `business.category` or enabled modules
- Simpler navigation (no sub-menus needed initially)
- Easier to maintain

**Implementation:**
1. Backend adds `category` field to `BusinessSummary` type
2. Backend `/dashboard` endpoint returns category-specific widgets
3. Frontend Dashboard renders widgets based on category
4. Widgets are reusable components in `components/dashboard/`

---

## Widget Catalog

### Universal Widgets (All Categories)
1. **KPI Cards** ✅ (Already exists)
   - Revenue, Orders, Customers, etc.
   - Period comparison

2. **Quick Actions Panel**
   - Category-specific shortcuts
   - E-Commerce: "Create Order", "Add Product"
   - Service: "New Appointment", "Create Work Order"
   - Retail: "Open POS", "Stock Count"

3. **Notifications Bell**
   - Recent system notifications
   - Alerts (low stock, pending orders, etc.)

### E-Commerce / Retail Widgets
4. **Sales Chart** 🔄 (In Progress)
   - Revenue over time (7d, 30d, 12m)
   - Line chart with comparisons

5. **Top Products**
   - Best-selling products by revenue or quantity
   - Product image, name, sales, revenue

6. **Top Customers**
   - Customers by total spend
   - Avatar, name, total orders, lifetime value

7. **Recent Orders**
   - Last 5-10 orders
   - Order number, customer, total, status
   - Click to view details

8. **Inventory Alerts**
   - Low stock products
   - Out of stock products
   - Expiring items (for perishables)

9. **Channel Performance** (E-Commerce)
   - Revenue by sales channel (Website, Marketplace, Social)
   - Pie/Bar chart

### Service Business Widgets
10. **Upcoming Appointments**
    - Next 5-10 appointments
    - Time, customer, service type, technician

11. **Pending Work Orders**
    - Open work orders by status
    - Kanban-style or list

12. **Technician Utilization** (Field Service)
    - Staff members with today's tasks
    - Completion rate

13. **Service Revenue Chart**
    - Revenue by service type
    - Bar chart

### Manufacturing Widgets
14. **Production Orders Status**
    - Orders in progress, completed today
    - WIP (Work in Progress) value

15. **Material Inventory**
    - Raw materials low stock
    - Critical materials to reorder

16. **Production Efficiency**
    - Target vs actual production
    - Completion rate

### Financial Widgets (All)
17. **Cash Flow Mini**
    - Simple in/out chart (last 7 days)
    - Current balance

18. **Pending Tasks**
    - Actions requiring attention
    - Unapproved invoices, pending payments, etc.

---

## Implementation Phases

### Phase 3A: Core Dashboard Enhancement ✅ (Current)
**Goal:** Enhance existing dashboard with universal widgets

**Widgets to Build:**
1. ✅ Enhance existing KPI cards
2. 🔄 Sales Chart (in progress)
3. Top Products
4. Top Customers  
5. Recent Orders
6. Quick Actions Panel
7. Cash Flow Mini
8. Pending Tasks
9. Notifications Bell (simple version)

**Timeline:** 1 session

---

### Phase 3B: Category-Specific Widgets
**Goal:** Build widgets for specific business types

**E-Commerce/Retail:**
- Inventory Alerts
- Channel Performance

**Service:**
- Upcoming Appointments
- Pending Work Orders
- Technician Utilization

**Manufacturing:**
- Production Orders Status
- Material Inventory
- Production Efficiency

**Timeline:** 1-2 sessions

---

### Phase 3C: Advanced Dashboard Features
**Goal:** Polish and interactivity

**Features:**
- Widget drag-and-drop reordering (future)
- Widget show/hide configuration (future)
- Export dashboard as PDF (future)
- Scheduled email reports (future)
- Real-time updates via WebSocket (future)

**Timeline:** Future phases

---

## Technical Architecture

### Backend API Endpoints

```
GET /dashboard
- Returns KPIs + widget data based on business category
- Query params: ?period=this_month

GET /dashboard/sales-chart
- Returns time-series data for revenue/orders
- Query params: ?period=7d|30d|12m

GET /dashboard/top-products
- Returns top N products
- Query params: ?period=this_month&limit=5

GET /dashboard/top-customers
- Returns top N customers
- Query params: ?period=this_month&limit=5

GET /dashboard/recent-orders
- Returns recent orders
- Query params: ?limit=10

GET /dashboard/pending-tasks
- Returns actionable items
- No params

GET /dashboard/quick-actions
- Returns category-specific quick actions
- No params
```

### Frontend Components

```
pages/
└── Dashboard.tsx (main page, orchestrates widgets)

components/dashboard/
├── SalesChart.tsx           🔄 In progress
├── TopProducts.tsx          ⏳ Next
├── TopCustomers.tsx         ⏳ Next
├── RecentOrders.tsx         ⏳ Next
├── CashFlowMini.tsx         ⏳ Next
├── PendingTasks.tsx         ⏳ Next
├── QuickActions.tsx         ⏳ Next
├── NotificationBell.tsx     ⏳ Next
├── InventoryAlerts.tsx      ⏳ Future
├── UpcomingAppointments.tsx ⏳ Future
└── ...
```

---

## Dashboard Layout Strategy

### Grid-Based Responsive Layout

```tsx
<DashboardPage title="Dashboard" actions={<PeriodSelector />}>
  {/* Row 1: KPI Cards */}
  <StatsGrid columns={{ sm: 2, xl: 4 }}>
    <StatsCard ... />
    <StatsCard ... />
    <StatsCard ... />
    <StatsCard ... />
  </StatsGrid>

  {/* Row 2: Main Charts */}
  <div className="mt-6 grid gap-6 lg:grid-cols-2">
    <SalesChart />
    <CashFlowMini />
  </div>

  {/* Row 3: Lists and Widgets */}
  <div className="mt-6 grid gap-6 lg:grid-cols-3">
    <div className="lg:col-span-2">
      <RecentOrders />
    </div>
    <div className="space-y-6">
      <TopProducts />
      <PendingTasks />
    </div>
  </div>

  {/* Row 4: Secondary Widgets */}
  <div className="mt-6 grid gap-6 lg:grid-cols-2">
    <TopCustomers />
    <QuickActions />
  </div>
</DashboardPage>
```

---

## Category Detection Strategy

### Option 1: Backend-Driven (Recommended) ✅

Backend determines category and returns appropriate widgets in `/dashboard` response:

```json
{
  "data": {
    "category": "ecommerce",
    "period": { ... },
    "kpis": [ ... ],
    "widgets": {
      "sales_chart": { ... },
      "top_products": [ ... ],
      "recent_orders": [ ... ]
    }
  }
}
```

Frontend just renders what backend provides.

**Pros:**
- Business logic in backend
- Frontend is presentation layer
- Easier to A/B test widgets
- Respects module entitlements

**Cons:**
- More backend work

### Option 2: Frontend-Driven

Frontend checks `tenant.business.category` and fetches relevant widget data:

```tsx
const category = tenant?.business?.category;

if (category === 'ecommerce' || category === 'retail') {
  return <SalesChart />;
}

if (category === 'service') {
  return <UpcomingAppointments />;
}
```

**Pros:**
- Faster to implement initially
- Frontend controls UX

**Cons:**
- Category logic duplicated
- Harder to manage entitlements

---

## Current Status

### Completed
- ✅ Business Model Guide created
- ✅ Dashboard Implementation Plan created
- ✅ Phase 1 UI Components (20 components)
- ✅ Phase 2 Layout Components (12 components)
- 🔄 SalesChart widget (in progress)

### Next Steps
1. Continue building Phase 3A widgets:
   - TopProducts
   - TopCustomers
   - RecentOrders
   - QuickActions
   - CashFlowMini
   - PendingTasks
   - NotificationBell

2. Update Dashboard.tsx to use new widgets

3. Test with different business categories

4. Build Phase 3B category-specific widgets

---

## Navigation Menu Strategy (Future)

When a business has multiple categories (e.g., Retail + E-Commerce):

```
📊 Dashboard
   ├── Overview (combined dashboard)
   ├── E-Commerce Dashboard
   └── Retail Dashboard
```

This requires:
1. Backend returns multiple categories for business
2. Frontend adds sub-menu items under Dashboard
3. Each dashboard route shows category-specific widgets
4. "Overview" combines key metrics from both

**Implementation:** Phase 4+

---

## Notes

- Backend APIs are ready (38 tasks complete)
- Focus on building widgets first
- Category-specific routing can come later
- Start with universal widgets that work for all categories
- Use existing KPI cards as template for styling

---

**Current Task:** Building Phase 3A dashboard widgets (SalesChart, TopProducts, TopCustomers, RecentOrders, etc.)
