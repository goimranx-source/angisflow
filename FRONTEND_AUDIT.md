# Angisflow Frontend — Current State Audit

**Date:** 2026-08-14  
**Purpose:** Comprehensive inventory of existing components and pages before building new ones

---

## ✅ EXISTING UI COMPONENTS (9 components)

**Location:** `resources/js/components/ui/`

| Component | Status | Notes |
|-----------|--------|-------|
| **AngisflowLogo** | ✅ Complete | Platform logo component |
| **Badge** | ✅ Complete | Square badges for workspaces/businesses with initials, icons, images |
| **Button** | ✅ Complete | 4 variants (primary, secondary, ghost, danger), busy state, sizes |
| **EmptyState** | ✅ Complete | Empty state component |
| **Field** | ✅ Complete | Labeled input with error handling, password reveal, accessibility |
| **Icon** | ✅ Complete | Phosphor icons wrapper |
| **PageHeader** | ✅ Complete | Page title, description, actions |
| **Preloader** | ✅ Complete | Loading spinner |
| **Skeleton** | ✅ Complete | Loading skeleton states |
| **Table** | ✅ Just created | Sortable table with loading/empty states (NEW - need to verify no conflicts) |

---

## ✅ EXISTING DOMAIN COMPONENTS

### Auth Components (1)
- `Turnstile.tsx` - Cloudflare Turnstile captcha

### Home Components (8)
- `CreateBusinessWizard.tsx`
- `CreateWorkspaceWizard.tsx`
- `EditBusinessModal.tsx`
- `EditWorkspaceModal.tsx`
- `HomeAsk.tsx`
- `HomeTodos.tsx`
- `NewBusiness.tsx`
- `NewWorkspace.tsx`

### Media Components (3)
- `MediaDetail.tsx`
- `MediaPicker.tsx`
- `MediaToolbar.tsx`

### Organiser Components (3)
- `FilterBar.tsx`
- `ItemMenu.tsx`
- `ManageTagsModal.tsx`

### Pova Components (2) — AI Assistant
- `PovaPanel.tsx`
- `PovaThread.tsx`

### Shell Components (12)
- `AccountMenu.tsx`
- `AccountNotice.tsx`
- `BrandMark.tsx`
- `BusinessMenu.tsx`
- `ContextPicker.tsx`
- `HeaderInfo.tsx`
- `HeaderPopover.tsx`
- `NavRow.tsx`
- `PrimaryNav.tsx`
- `SearchFlyout.tsx`
- `Sidebar.tsx`
- `Toasts.tsx`
- `Topbar.tsx`

---

## ✅ EXISTING PAGES (24 pages)

### Auth Pages (6) - COMPLETE
- ✅ `auth/Login.tsx`
- ✅ `auth/Register.tsx`
- ✅ `auth/ForgotPassword.tsx`
- ✅ `auth/ResetPassword.tsx`
- ✅ `auth/TwoFactor.tsx`
- ✅ `auth/ConfirmPassword.tsx`

### Settings Pages (5) - COMPLETE
- ✅ `settings/Settings.tsx` - Tabbed settings container
- ✅ `settings/AppearancePanel.tsx`
- ✅ `settings/CurrencyPanel.tsx`
- ✅ `settings/IntegrationsPanel.tsx`
- ✅ `settings/MediaPanel.tsx`
- ✅ `settings/SettingsCard.tsx` - Reusable settings card

### Core Pages (13)
- ✅ `Landing.tsx` - Public landing page
- ✅ `Home.tsx` - Authenticated home (workspace/business picker)
- ✅ `Onboarding.tsx` - Multi-step onboarding wizard
- ✅ `Dashboard.tsx` - Business dashboard with KPIs
- ✅ `Profile.tsx` - User profile
- ✅ `Billing.tsx` - Subscription & billing
- ✅ `Workspaces.tsx` - Workspace management
- ✅ `Businesses.tsx` - Business management
- ✅ `Reports.tsx` - Financial reports
- ✅ `Accounts.tsx` - Chart of accounts (grouped by type)
- ✅ `Transactions.tsx` - Transaction list
- ✅ `Journal.tsx` - Daily journal
- ✅ `Credentials.tsx` - Credential vault (list, add, reveal, revoke)

### Operator & Special Pages (3)
- ✅ `Operator.tsx` - Platform admin panel (comprehensive)
- ✅ `Inbox.tsx` - Placeholder for inbox
- ✅ `Roadmap.tsx` - Feature roadmap
- ✅ `Module.tsx` - "Coming soon" page for unbuilt modules
- ✅ `NotFound.tsx` - 404 page

---

## ✅ EXISTING HOOKS (16 hooks)

**Location:** `resources/js/hooks/`

| Hook | Purpose |
|------|---------|
| `useAccordionReveal` | Accordion animation |
| `useApiForm` | Form handling with API integration |
| `useClock` | Real-time clock |
| `useDocumentTitle` | Set page title |
| `useMenu` | Menu state management |
| `useModules` | Module catalog access |
| `useOpenBusiness` | Business switching |
| `useOrganiser` | Tags, favorites, todos |
| `usePova` | AI assistant |
| `usePovaHistory` | AI conversation history |
| `usePovaLayout` | AI panel layout |
| `useRail` | Sidebar state |
| `useScrollLock` | Lock body scroll (modals) |
| `useScrollReveal` | Scroll-based reveal |
| `useTheme` | Theme management |
| `useWeather` | Weather data (fun feature?) |

---

## ✅ EXISTING PROVIDERS (3 providers)

| Provider | Purpose |
|----------|---------|
| `NavigationLoadingProvider` | Loading state during navigation |
| `PovaProvider` | AI assistant state |
| `SessionProvider` | Auth & tenant context |

---

## ✅ EXISTING LAYOUTS

- `AppLayout` - Main shell (sidebar + topbar + content)

---

## ✅ EXISTING UTILITIES

**Location:** `resources/js/lib/`

| Utility | Purpose |
|---------|---------|
| `api.ts` | Fetch wrapper, error handling, CSRF |
| `utils.ts` | `cn()` (classnames), `pathMatches()`, `initials()`, cookies |

---

## ❌ MISSING UI COMPONENTS (Need to Build)

Based on roadmap requirements, these components are MISSING and needed:

### Form Components
- ❌ **Input** (standalone, beyond Field)
- ❌ **Textarea**
- ❌ **Select** (dropdown)
- ❌ **Checkbox**
- ❌ **Radio**
- ❌ **Switch** (toggle)
- ❌ **DatePicker**
- ❌ **DateRangePicker**
- ❌ **FileUpload** (with preview)
- ❌ **SearchInput** (with debounce)

### Layout Components
- ❌ **Modal** (reusable dialog system)
- ❌ **Dropdown** (menu component)
- ❌ **Tabs**
- ❌ **Accordion**
- ❌ **Tooltip**
- ❌ **Breadcrumb**

### Data Components
- ⚠️ **Table** (just created, need to verify)
- ❌ **DataGrid** (virtual scrolling for large lists)
- ❌ **Pagination**
- ❌ **CursorPagination** (for infinite scroll)

### Feedback Components
- ❌ **Toast** system (Toasts.tsx exists in shell, need to verify if reusable)
- ❌ **Confirm** dialog
- ❌ **Alert** / **Banner**

### Visualization Components
- ❌ **StatsCard** (KPI card - Dashboard has inline, need reusable)
- ❌ **BarChart**
- ❌ **LineChart**
- ❌ **PieChart** / **DonutChart**
- ❌ **Timeline**
- ❌ **ActivityFeed**

### Specialized Components
- ❌ **MoneyInput** (formatted currency input)
- ❌ **QuantityInput** (number with increment/decrement)
- ❌ **ColorPicker**
- ❌ **ImageCrop**
- ❌ **RichTextEditor**
- ❌ **CodeEditor** / **JsonEditor**

---

## ❌ MISSING PAGES (Need to Build)

### Sales & Orders (8 pages)
- ❌ **Orders** - List, filters, bulk actions
- ❌ **OrderDetail** - Timeline, line items, actions
- ❌ **CreateOrder** / **EditOrder** - Multi-step form
- ❌ **Customers** - List with segments
- ❌ **CustomerDetail** - Profile, orders, LTV
- ❌ **Invoices** - List
- ❌ **InvoiceDetail** - Preview, payments
- ❌ **PointOfSale** - Full-screen POS interface
- ❌ **Returns** - Returns & RTO management
- ❌ **Quotes** - Quote management

### Inventory & Products (7 pages)
- ❌ **Products** - Catalogue with grid/list view
- ❌ **ProductDetail** - Images, variants, stock
- ❌ **CreateProduct** / **EditProduct**
- ❌ **StockOverview** - Dashboard
- ❌ **StockMovements** - History
- ❌ **Warehouses** - List, details
- ❌ **PurchaseOrders** - List, create, receive
- ❌ **Suppliers** - Supplier management
- ❌ **Bills** - Bills & payables
- ❌ **Manufacturing** - Production orders, BOM
- ❌ **QualityControl** - Inspections

### Delivery & Courier (4 pages)
- ❌ **CourierDashboard** - Unified courier view
- ❌ **Shipments** - List, create, track
- ❌ **CourierConnections** - Setup, status mapping
- ❌ **CODReconciliation** - Settlement tracking

### Conversations & Support (5 pages)
- ⚠️ **Inbox** (exists but placeholder)
- ❌ **Channels** - Channel management
- ❌ **Automations** - Message automation rules
- ❌ **Tickets** - Helpdesk ticketing
- ❌ **KnowledgeBase** - Articles, FAQ

### CRM & Marketing (6 pages)
- ❌ **Leads** - Lead management
- ❌ **Pipeline** - Deal pipeline (kanban)
- ❌ **Activities** - Calendar, tasks
- ❌ **Campaigns** - Marketing campaigns
- ❌ **Offers** - Coupons & promotions
- ❌ **Loyalty** - Loyalty programs
- ❌ **Reviews** - Review incentives

### HR & Payroll (5 pages)
- ❌ **Employees** - Employee list
- ❌ **EmployeeDetail** - Profile, documents
- ❌ **OrganizationChart**
- ❌ **Attendance** - Clock in/out, calendar
- ❌ **LeaveManagement**
- ❌ **PayrollRuns** - Payroll processing
- ❌ **Payslips**
- ❌ **Recruitment** - Job postings, applicants
- ❌ **Performance** - Reviews, training

### Projects & Field Service (4 pages)
- ❌ **Projects** - Project list
- ❌ **ProjectDetail** - Tasks, time, budget
- ❌ **Timesheets**
- ❌ **WorkOrders** - Field service
- ❌ **Fleet** - Vehicle management

### Bookings & Partners (3 pages)
- ❌ **Bookings** - Appointment calendar
- ❌ **Partners** - Partner management
- ❌ **Storefronts** - Public storefront config
- ❌ **CustomerPortal** - Self-service portal

### AI & Intelligence (3 pages)
- ❌ **Assistant** - AI chat interface
- ❌ **AIReports** - Insights dashboard
- ❌ **Forecasting** - Predictions & alerts

### API & Integrations (2 pages)
- ❌ **APIKeys** - API key management
- ❌ **Integrations** - Integration marketplace

### Localization & Audit (2 pages)
- ❌ **LanguagePacks** - Translation management
- ❌ **AuditLog** - System audit log

---

## 📊 SUMMARY STATISTICS

### Components
- ✅ **Existing:** 10 UI components (9 original + 1 just created)
- ✅ **Domain:** 29 specialized components
- ❌ **Missing:** ~40 UI components needed

### Pages
- ✅ **Existing:** 24 pages (6 auth + 5 settings + 13 core)
- ❌ **Missing:** ~65 pages needed

### Infrastructure
- ✅ **Complete:** Routing, API layer, auth, tenant context
- ✅ **Complete:** Layout system, theming, navigation
- ✅ **Complete:** TanStack Query setup
- ✅ **Complete:** AI assistant (Pova) integration

---

## 🎯 RECOMMENDATIONS

### 1. **DO NOT REBUILD** What Exists
   - Auth pages are complete
   - Settings infrastructure is solid
   - Shell components (sidebar, topbar) are done
   - Home/workspace/business management is complete
   - Operator panel is comprehensive
   - Credential vault is fully functional
   - Chart of accounts, transactions, journal pages exist

### 2. **BUILD MISSING COMPONENTS FIRST** (Task F1)
   Focus on the 40 missing UI components before building pages:
   - Form components (Input, Select, Checkbox, etc.)
   - Modal & Dropdown systems
   - Data components (enhance Table if needed)
   - Chart components
   - Feedback components (Toast, Confirm)

### 3. **ENHANCE EXISTING PAGES** Where Needed
   - **Reports.tsx** - Add detailed report views (P&L, Balance Sheet)
   - **Transactions.tsx** - Add filters, detail view
   - **Journal.tsx** - Add entry form
   - **Inbox.tsx** - Currently placeholder, needs full implementation

### 4. **FOLLOW EXISTING PATTERNS**
   - Use `useQuery` from TanStack Query for data fetching
   - Use `api.get/post/patch/delete` from `@/lib/api`
   - Use `cn()` from `@/lib/utils` for classnames
   - Use `useDocumentTitle()` hook for page titles
   - Follow accessibility patterns from Field.tsx
   - Use CSS variables for colors (not hardcoded)
   - Use Card pattern (`.card` class) for containers
   - Use PageHeader for consistent page titles

### 5. **REUSE EXISTING PATTERNS**
   **Modal pattern** (from Credentials.tsx):
   ```tsx
   <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
       <div className="card w-full max-w-md space-y-4 p-5">
           {/* content */}
       </div>
   </div>
   ```

   **Slide-over pattern** (from Operator.tsx):
   ```tsx
   <div className="fixed inset-0 z-50 flex justify-end bg-black/30">
       <div className="h-full w-full max-w-xl overflow-y-auto bg-[var(--color-surface)]">
           {/* content */}
       </div>
   </div>
   ```

   **Status badge pattern** (from multiple files):
   ```tsx
   <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium', colorClass)}>
       {status}
   </span>
   ```

---

## ✅ NEXT IMMEDIATE ACTIONS

1. ✅ **Verify Table.tsx doesn't conflict** - Check if it was there before
2. ❌ **Build missing form components** - Input, Select, Checkbox, etc.
3. ❌ **Build Modal system** - Reusable dialog component
4. ❌ **Build Dropdown system** - Menu component
5. ❌ **Build Toast provider** - Check if Toasts.tsx is reusable
6. ❌ **Build chart components** - Bar, Line, Pie charts
7. ❌ **Then start on missing pages** - Follow roadmap phase order

---

**Audit Complete.** Ready to proceed with Task F1: Enhanced UI Component Library, building only what's missing.
