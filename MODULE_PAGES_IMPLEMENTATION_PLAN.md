# Module Pages Implementation - Task List

## Overview
Create professional, consistent UI pages for all 86 modules in the system. Each page should follow a unified design system with appropriate components based on the module's function.

---

## Design System Foundation

### Phase 0: Design System Setup ✅ (Already exists)
- [x] Color system with CSS variables
- [x] Border radius standards (5px primary, 4px small)
- [x] Typography system
- [x] Icon system (Phosphor icons)
- [x] Spacing scale
- [x] Component library (Button, Input, Select, etc.)

---

## Module Page Patterns

Based on module types, we need 7 different page patterns:

### Pattern 1: List/Table View (Most Common)
**Used for**: Orders, Customers, Products, Invoices, Employees, etc. (~40 modules)

**Components needed**:
- Page header with title, breadcrumbs, actions
- Filter bar with search and filters
- Data table with sorting, pagination
- Bulk actions toolbar
- Empty state
- Detail drawer/modal

**Example modules**: 
- revenue.orders
- revenue.customers
- catalogue.products
- people.employees

---

### Pattern 2: Dashboard/Overview
**Used for**: Dashboard, Reports, Analytics (~8 modules)

**Components needed**:
- KPI cards grid
- Chart containers (line, bar, pie)
- Period selector
- Quick actions
- Activity feed
- Metric cards

**Example modules**:
- work.dashboard
- finance.reports
- intelligence.dashboards

---

### Pattern 3: Calendar/Timeline View
**Used for**: Bookings, Scheduling, Shifts (~5 modules)

**Components needed**:
- Calendar grid (day/week/month views)
- Timeline view
- Event cards
- Date picker
- Quick create popover
- Resource filters

**Example modules**:
- delivery.bookings
- delivery.scheduling
- people.shifts

---

### Pattern 4: Kanban/Pipeline View
**Used for**: Pipeline, Projects, Tickets (~4 modules)

**Components needed**:
- Column-based layout
- Draggable cards
- Stage headers with counts
- Card quick actions
- Filter by stage
- Add new card button

**Example modules**:
- revenue.pipeline
- delivery.projects
- cx.helpdesk

---

### Pattern 5: Settings/Configuration
**Used for**: Settings, Integrations, Users (~6 modules)

**Components needed**:
- Settings navigation sidebar
- Form sections with cards
- Toggle switches
- Save/cancel buttons
- Danger zone section
- Preview panels

**Example modules**:
- platform.settings
- platform.users
- platform.integrations

---

### Pattern 6: Chat/Messaging View
**Used for**: Inbox, Live Chat, Messages (~3 modules)

**Components needed**:
- Conversation list
- Message thread
- Message composer
- Contact info sidebar
- Attachment preview
- Status indicators

**Example modules**:
- cx.inbox
- cx.live_chat

---

### Pattern 7: Document/Form View
**Used for**: Forms, Documents, Templates (~5 modules)

**Components needed**:
- Document preview
- Form builder
- Template gallery
- Version history
- Share/export actions
- Collaboration tools

**Example modules**:
- documents.store
- documents.templates
- growth.forms

---

## Implementation Tasks by Priority

### 🔴 Priority 1: Core Modules (BUILT - Must complete first)

#### Task 1.1: Dashboard (work.dashboard) ✅
**Status**: Already built  
**Pattern**: Dashboard/Overview  
**Verify**: Ensure all KPIs, charts working properly

#### Task 1.2: Settings (platform.settings) ✅
**Status**: Already built  
**Pattern**: Settings/Configuration  
**Verify**: Ensure all settings panels functional

---

### ⚡ Phase 1: Foundation Components ✅ (COMPLETED)

**Shared Components Built:**
- ✅ FilterBar - Search, filters, view toggle, actions
- ✅ DetailDrawer - Slide-out panel with tabs
- ✅ StatusBadge - Colored status indicators with presets
- ✅ BulkActions - Selection toolbar with actions
- ✅ QuickCreateModal - Fast add modal
- ✅ KPICard - Metric display with trends
- ✅ SelectCheckbox - Multi-select checkboxes
- ✅ ViewToggleButton - Grid/list toggle
- ✅ FilterSelect - Dropdown filters

**Status**: Foundation complete! All shared components ready for use across module pages.

---

### 🟠 Priority 2: Essential List Views (High Impact)

#### Task 2.1: Customers Page ✅
**Module**: revenue.customers  
**Pattern**: List/Table View  
**Status**: COMPLETED
**Components built**:
- ✅ Customer list table (name, email, phone, total spent, status)
- ✅ Filter by status with search
- ✅ Search by name/email/phone
- ✅ Customer detail drawer with tabs (Overview, Orders, Activity)
- ✅ Add customer modal
- ✅ Export button
- ✅ Bulk actions (export, delete)
- ✅ View toggle (list/grid - list completed)
- ✅ Select all/individual checkboxes
- ✅ Sortable columns
- ✅ Empty state
- ✅ Loading skeletons

**Estimated effort**: 6-8 hours

---

#### Task 2.2: Orders Page
**Module**: revenue.orders  
**Pattern**: List/Table View  
**Components to build**:
- Orders table (order #, customer, date, status, amount)
- Filter by status, date range, payment status
- Search by order #, customer name
- Order detail page (full view, not drawer)
- Create new order button
- Status badges (pending, confirmed, shipped, delivered)
- Print order, download invoice actions
- Bulk status update

**Estimated effort**: 8-10 hours

---

#### Task 2.3: Products Page
**Module**: catalogue.products  
**Pattern**: List/Table View with Grid Toggle  
**Components to build**:
- Product table/grid toggle view
- Product cards (image, name, SKU, price, stock)
- Filter by category, status, stock level
- Search by name, SKU
- Product detail drawer with tabs (Details, Stock, Pricing, Media)
- Add/Edit product modal
- Import products (CSV)
- Low stock indicator
- Quick edit stock

**Estimated effort**: 8-10 hours

---

#### Task 2.4: Invoices Page
**Module**: finance.invoicing  
**Pattern**: List/Table View  
**Components to build**:
- Invoice list table (invoice #, customer, date, due date, amount, status)
- Filter by status, date range, overdue
- Search by invoice #, customer
- Invoice detail page with preview
- Create invoice button
- Status badges (draft, sent, paid, overdue)
- Send invoice button
- Download PDF
- Record payment

**Estimated effort**: 8-10 hours

---

#### Task 2.5: Employees Page
**Module**: people.employees  
**Pattern**: List/Table View  
**Components to build**:
- Employee list table (photo, name, role, department, status)
- Filter by department, status, role
- Search by name, email
- Employee detail drawer (Overview, Contact, Employment, Documents)
- Add employee modal with wizard
- Status badges (active, on leave, terminated)
- Export directory
- Bulk actions

**Estimated effort**: 6-8 hours

---

### 🟡 Priority 3: Special View Modules

#### Task 3.1: Bookings & Appointments
**Module**: delivery.bookings  
**Pattern**: Calendar/Timeline View  
**Components to build**:
- Calendar view (day/week/month)
- Timeline view for resources
- Booking cards with customer, service, time
- Quick create booking popover
- Resource filters (staff, rooms, equipment)
- Date range picker
- Booking detail modal
- Status indicators (confirmed, cancelled, completed)
- Drag to reschedule

**Estimated effort**: 10-12 hours

---

#### Task 3.2: Pipeline & Deals
**Module**: revenue.pipeline  
**Pattern**: Kanban/Pipeline View  
**Components to build**:
- Kanban columns (stages: Lead, Qualified, Proposal, Negotiation, Won, Lost)
- Deal cards (company, value, probability, contact)
- Drag and drop between stages
- Filter by owner, date range, value
- Search by company, contact
- Deal detail drawer
- Add deal modal
- Stage settings
- Win/Loss reasons

**Estimated effort**: 10-12 hours

---

#### Task 3.3: Inbox & Messages
**Module**: cx.inbox  
**Pattern**: Chat/Messaging View  
**Components to build**:
- Conversation list with unread badges
- Message thread view
- Message composer with attachments
- Contact info sidebar
- Status indicators (online, typing)
- Search conversations
- Filter by channel, status, assigned
- Canned responses
- Internal notes

**Estimated effort**: 12-14 hours

---

#### Task 3.4: Stock Management
**Module**: catalogue.stock  
**Pattern**: List/Table View with Location Breakdown  
**Components to build**:
- Stock levels table (product, SKU, on hand, available, reserved)
- Filter by location, low stock, category
- Search by product, SKU
- Location breakdown view
- Adjust stock modal
- Stock transfer modal
- Stock history timeline
- Low stock alerts
- Reorder point settings

**Estimated effort**: 8-10 hours

---

### 🟢 Priority 4: Supporting Modules

#### Task 4.1: Leads Management
**Module**: revenue.leads  
**Pattern**: List/Table View  
**Components to build**:
- Leads table (name, company, source, score, status, owner)
- Filter by source, status, score, date
- Search by name, email, company
- Lead detail drawer
- Add lead modal/form
- Lead scoring badge
- Convert to deal button
- Assign to user
- Bulk import

**Estimated effort**: 6-8 hours

---

#### Task 4.2: Quotes & Proposals
**Module**: revenue.quotes  
**Pattern**: List/Table View  
**Components to build**:
- Quote list table (quote #, customer, date, value, status)
- Filter by status, date, expiry
- Search by quote #, customer
- Quote detail page with line items
- Create quote button with builder
- Status workflow (draft → sent → accepted/rejected)
- Convert to order button
- Download PDF
- Send via email

**Estimated effort**: 10-12 hours

---

#### Task 4.3: Transactions List
**Module**: finance.transactions  
**Pattern**: List/Table View  
**Components to build**:
- Transaction table (date, description, account, debit, credit, balance)
- Filter by date range, account, type
- Search by description, reference
- Transaction detail modal
- Add transaction button
- Reconciliation status
- Attach receipt
- Export to Excel
- Running balance column

**Estimated effort**: 6-8 hours

---

#### Task 4.4: Projects & Tasks
**Module**: delivery.projects  
**Pattern**: Kanban + List Toggle  
**Components to build**:
- Project list view
- Kanban board for tasks
- Task cards with assignee, due date, priority
- Filter by project, assignee, status
- Project detail page with tasks, timeline, files
- Create project/task modal
- Time tracking
- Progress indicators
- Gantt chart (optional)

**Estimated effort**: 12-14 hours

---

#### Task 4.5: Shifts & Rota
**Module**: people.shifts  
**Pattern**: Calendar/Timeline View  
**Components to build**:
- Weekly rota grid
- Employee rows, day columns
- Shift blocks with time ranges
- Drag to assign/move shifts
- Template shifts
- Shift swap requests
- Availability management
- Conflict detection
- Print rota

**Estimated effort**: 10-12 hours

---

### 🔵 Priority 5: Advanced Modules

#### Task 5.1: Payments
**Module**: finance.payments  
**Pattern**: List/Table View  
**Components to build**:
- Payment list (date, customer/vendor, method, amount, status)
- Filter by type, method, status, date
- Record payment modal
- Payment detail view
- Link to invoice/bill
- Receipt upload
- Refund action
- Payment methods config

**Estimated effort**: 6-8 hours

---

#### Task 5.2: Warehouses
**Module**: catalogue.warehouses  
**Pattern**: List with Location Detail  
**Components to build**:
- Warehouse list
- Location/bin management tree
- Stock per location
- Transfer between warehouses
- Warehouse settings
- Receiving area
- Picking lists

**Estimated effort**: 8-10 hours

---

#### Task 5.3: Timesheets
**Module**: delivery.timesheets  
**Pattern**: Weekly Grid View  
**Components to build**:
- Weekly timesheet grid
- Project/task selection
- Time entry cells
- Total hours calculation
- Submit for approval
- Approval workflow
- Time reports
- Export to payroll

**Estimated effort**: 8-10 hours

---

#### Task 5.4: Campaigns
**Module**: growth.campaigns  
**Pattern**: List/Table View  
**Components to build**:
- Campaign list (name, type, status, reach, conversions)
- Campaign detail page with analytics
- Create campaign wizard
- Audience targeting
- Performance metrics
- A/B testing
- Schedule campaign

**Estimated effort**: 10-12 hours

---

#### Task 5.5: Reports & Analytics
**Module**: finance.reports  
**Pattern**: Dashboard with Report Builder  
**Components to build**:
- Report templates gallery
- Custom report builder
- Chart options (line, bar, pie, table)
- Date range picker
- Filter panel
- Export options (PDF, Excel)
- Scheduled reports
- Save custom reports

**Estimated effort**: 12-14 hours

---

### ⚫ Priority 6: Remaining Modules (~40 modules)

These follow similar patterns to above. Once the core patterns are established, these can be implemented faster using the existing components.

**Estimated effort per module**: 4-6 hours (using templates)

---

## Shared Components to Build

### Essential Components (Build First)
1. **PageHeader** - Title, breadcrumbs, actions, tabs
2. **FilterBar** - Search, filters, sort, view toggle
3. **DataTable** - Sortable columns, pagination, selection
4. **DetailDrawer** - Slide-out panel with tabs
5. **StatusBadge** - Colored status indicators
6. **EmptyState** - No data placeholder with actions
7. **BulkActions** - Floating toolbar for selected items
8. **QuickCreate** - Fast add modal/popover
9. **Pagination** - Page navigation with per-page selector
10. **LoadingSkeleton** - Content placeholder while loading

### Secondary Components
11. **KPICard** - Metric display with trend
12. **ChartContainer** - Wrapper for charts with loading/error states
13. **TimelineView** - Vertical timeline for history
14. **ActivityFeed** - Recent activity list
15. **TagInput** - Multi-select tag input
16. **DateRangePicker** - From/to date selector
17. **FileUpload** - Drag-drop file upload with preview
18. **ImageUpload** - Image upload with crop
19. **RichTextEditor** - WYSIWYG editor for descriptions
20. **NotificationBanner** - Inline notification/alert

---

## Implementation Strategy

### Phase 1: Foundation (Week 1-2)
- Build shared components library
- Create page templates for each pattern
- Set up routing structure
- Create mock API endpoints

### Phase 2: Core Modules (Week 3-4)
- Priority 1 & 2 modules
- Essential list views
- Basic CRUD operations

### Phase 3: Special Views (Week 5-6)
- Calendar views
- Kanban boards
- Chat interface
- Advanced interactions

### Phase 4: Polish (Week 7-8)
- Remaining modules
- Error handling
- Loading states
- Empty states
- Mobile responsiveness
- Keyboard shortcuts
- Accessibility

---

## Technical Requirements

### Frontend Stack (Already in place)
- React 18
- TypeScript
- React Router for navigation
- TanStack Query for data fetching
- Tailwind CSS for styling
- Phosphor Icons

### API Integration
- RESTful API endpoints
- Consistent response format
- Error handling
- Loading states
- Optimistic updates
- Cache invalidation

### Quality Standards
- TypeScript strict mode
- Component documentation
- Responsive design (mobile-first)
- Accessibility (WCAG AA)
- Performance (Core Web Vitals)
- Error boundaries
- Loading states everywhere

---

## Success Criteria

For each module page:
- ✅ Follows design system (5px borders, consistent spacing)
- ✅ Has proper loading states
- ✅ Has meaningful empty states
- ✅ Shows error messages gracefully
- ✅ Works on mobile devices
- ✅ Has keyboard navigation
- ✅ Meets accessibility standards
- ✅ Has proper breadcrumbs
- ✅ Includes search/filter where appropriate
- ✅ Has appropriate bulk actions
- ✅ Includes help text/tooltips

---

## Next Steps

1. **Review & Approve** this plan
2. **Start with Phase 1** - Build shared components
3. **Implement Task 2.1** - Customers page (first full implementation)
4. **Iterate & improve** based on feedback
5. **Scale to other modules** using established patterns

---

## Estimated Timeline

- **Phase 1 (Foundation)**: 2 weeks
- **Phase 2 (Core Modules)**: 2 weeks  
- **Phase 3 (Special Views)**: 2 weeks
- **Phase 4 (Polish + Remaining)**: 2 weeks

**Total**: ~8 weeks for complete implementation

With 2-3 developers working in parallel: ~4-5 weeks

---

## Let's Start!

**Ready to begin with Task 1: Build Shared Components?**

Or would you prefer to:
- Start with a specific high-value module (e.g., Customers)?
- Review/modify the plan first?
- See a detailed breakdown of one specific task?
