# Angisflow Frontend — Complete Implementation Roadmap

**Date:** 2026-08-14  
**Status:** Ready to Execute  
**Backend:** ✅ All 38 tasks complete, production ready  
**Frontend:** 📋 Phase-by-phase implementation plan

---

## Overview

This roadmap covers complete frontend implementation for all Angisflow modules. The UI will be professional, consistent, and inspired by modern ERP interfaces like DreamsERP while maintaining Angisflow's unique identity.

**Design Principles:**
- Clean, professional interface suitable for daily business operations
- Consistent component patterns across all modules
- Mobile-responsive (desktop-first, mobile-friendly)
- Fast navigation with prefetching
- Accessible (WCAG AA minimum)
- Data-dense but not cluttered
- Action-oriented (common tasks immediately visible)

**Technical Stack:**
- React 19 with TypeScript
- TanStack Query v5 (data fetching & caching)
- React Router v8 (navigation)
- Tailwind CSS v4 (styling)
- Phosphor Icons (iconography)
- Lazy-loaded routes (50+ code splits)

---

## PHASE 1: UI Foundation & Core Components (10 tasks)

**Goal:** Build the reusable component library and establish UI patterns

### Task F1: Enhanced UI Component Library
**Complexity:** Medium  
**Dependencies:** None  
**Deliverables:**
- Table component (sortable, filterable, paginated)
- Data grid with virtual scrolling
- Modal/Dialog system
- Dropdown menus (single & multi-select)
- Date picker & range picker
- Form components (Input, Textarea, Select, Checkbox, Radio, Switch)
- Toast notification system
- Confirmation dialogs
- File upload with preview
- Search with debounce
- Tabs component
- Accordion component
- Tooltip component
- Loading states & skeletons
- Empty states for all scenarios

**Files:**
```
resources/js/components/ui/Table.tsx
resources/js/components/ui/DataGrid.tsx
resources/js/components/ui/Modal.tsx
resources/js/components/ui/Dropdown.tsx
resources/js/components/ui/DatePicker.tsx
resources/js/components/ui/Form/Input.tsx
resources/js/components/ui/Form/Textarea.tsx
resources/js/components/ui/Form/Select.tsx
resources/js/components/ui/Form/Checkbox.tsx
resources/js/components/ui/Form/Radio.tsx
resources/js/components/ui/Form/Switch.tsx
resources/js/components/ui/Toast.tsx
resources/js/components/ui/Confirm.tsx
resources/js/components/ui/FileUpload.tsx
resources/js/components/ui/SearchInput.tsx
resources/js/components/ui/Tabs.tsx
resources/js/components/ui/Accordion.tsx
resources/js/components/ui/Tooltip.tsx
resources/js/providers/ToastProvider.tsx
```

---

### Task F2: Layout Components & Navigation
**Complexity:** Medium  
**Dependencies:** F1  
**Deliverables:**
- Enhanced sidebar with collapsible sections
- Breadcrumb navigation
- Page layout templates (list, detail, form, dashboard)
- Action bar component (bulk actions, filters)
- Stats card component
- Chart wrappers (bar, line, pie, donut)
- Timeline component
- Activity feed component

**Files:**
```
resources/js/layouts/PageLayouts/ListPage.tsx
resources/js/layouts/PageLayouts/DetailPage.tsx
resources/js/layouts/PageLayouts/FormPage.tsx
resources/js/layouts/PageLayouts/DashboardPage.tsx
resources/js/components/ui/Breadcrumb.tsx
resources/js/components/ui/ActionBar.tsx
resources/js/components/ui/StatsCard.tsx
resources/js/components/ui/Charts/BarChart.tsx
resources/js/components/ui/Charts/LineChart.tsx
resources/js/components/ui/Charts/PieChart.tsx
resources/js/components/ui/Timeline.tsx
resources/js/components/ui/ActivityFeed.tsx
```

---

### Task F3: Enhanced Dashboard
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Multi-widget dashboard (draggable/configurable in future)
- Sales overview chart (7 days, 30 days, 12 months)
- Top products/customers widget
- Recent orders list
- Cash flow mini-chart
- Pending tasks widget
- Quick actions panel
- Real-time notifications dropdown

**Files:**
```
resources/js/pages/Dashboard.tsx (enhance existing)
resources/js/components/dashboard/SalesChart.tsx
resources/js/components/dashboard/TopProducts.tsx
resources/js/components/dashboard/TopCustomers.tsx
resources/js/components/dashboard/RecentOrders.tsx
resources/js/components/dashboard/CashFlowMini.tsx
resources/js/components/dashboard/PendingTasks.tsx
resources/js/components/dashboard/QuickActions.tsx
resources/js/components/dashboard/NotificationBell.tsx
```

---

### Task F4: Settings & Configuration UI
**Complexity:** Medium  
**Dependencies:** F1  
**Deliverables:**
- Settings page with tabbed navigation (enhance existing)
- General settings form
- Currency management interface
- Tax configuration
- Notification preferences
- Email templates
- Integration configuration cards
- Webhook management

**Files:**
```
resources/js/pages/settings/Settings.tsx (enhance existing)
resources/js/pages/settings/GeneralSettings.tsx
resources/js/pages/settings/CurrencySettings.tsx
resources/js/pages/settings/TaxSettings.tsx
resources/js/pages/settings/NotificationSettings.tsx
resources/js/pages/settings/EmailTemplates.tsx
resources/js/pages/settings/Integrations.tsx
resources/js/pages/settings/Webhooks.tsx
```

---

### Task F5: User Management & Permissions
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Users list with invite functionality
- User detail page (profile, roles, activity)
- Role management page
- Permission matrix interface
- Invite user modal
- Bulk user actions

**Files:**
```
resources/js/pages/team/Users.tsx
resources/js/pages/team/UserDetail.tsx
resources/js/pages/team/Roles.tsx
resources/js/pages/team/RoleForm.tsx
resources/js/components/team/PermissionMatrix.tsx
resources/js/components/team/InviteUserModal.tsx
```

---

### Task F6: Financial Reports Enhancement
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Enhanced reports page (existing basic version)
- Profit & Loss with drill-down
- Balance Sheet with comparisons
- Trial Balance
- Receivables Aging
- Account Ledger view
- Date range selector
- Export to PDF/Excel buttons
- Print layouts

**Files:**
```
resources/js/pages/Reports.tsx (enhance existing)
resources/js/pages/reports/ProfitAndLoss.tsx
resources/js/pages/reports/BalanceSheet.tsx
resources/js/pages/reports/TrialBalance.tsx
resources/js/pages/reports/ReceivablesAging.tsx
resources/js/pages/reports/AccountLedger.tsx
resources/js/components/reports/DateRangeSelector.tsx
resources/js/components/reports/ReportExport.tsx
resources/js/utils/print.ts
```

---

### Task F7: Chart of Accounts UI
**Complexity:** Low  
**Dependencies:** F1, F2  
**Deliverables:**
- Hierarchical account tree view (enhance existing)
- Account detail page
- Add/edit account modal
- Account balance history
- Posting list for account

**Files:**
```
resources/js/pages/Accounts.tsx (enhance existing)
resources/js/pages/accounts/AccountDetail.tsx
resources/js/components/accounts/AccountTree.tsx
resources/js/components/accounts/AccountFormModal.tsx
resources/js/components/accounts/AccountBalanceChart.tsx
```

---

### Task F8: Transactions & Journal
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Transactions list with filters (enhance existing)
- Transaction detail slide-over
- Manual journal entry form
- Journal entry reversal interface
- Transaction search (by date, account, narration)
- Daily journal view (enhance existing)

**Files:**
```
resources/js/pages/Transactions.tsx (enhance existing)
resources/js/pages/Journal.tsx (enhance existing)
resources/js/components/transactions/TransactionDetail.tsx
resources/js/components/transactions/JournalEntryForm.tsx
resources/js/components/transactions/ReverseEntryModal.tsx
resources/js/components/transactions/TransactionFilters.tsx
```

---

### Task F9: Credential Vault UI
**Complexity:** Low  
**Dependencies:** F1  
**Deliverables:**
- Credentials list (enhance existing)
- Add credential modal
- Reveal credential (with password confirm)
- Rotate key interface
- Revoke confirmation
- Usage history

**Files:**
```
resources/js/pages/Credentials.tsx (enhance existing)
resources/js/components/credentials/AddCredentialModal.tsx
resources/js/components/credentials/RevealCredential.tsx
resources/js/components/credentials/RotateKeyModal.tsx
resources/js/components/credentials/UsageHistory.tsx
```

---

### Task F10: Workspace & Business Management
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Workspaces list (enhance existing)
- Create workspace wizard
- Workspace settings
- Business list per workspace
- Create business modal
- Switch workspace/business interface
- Delete confirmation with impact analysis

**Files:**
```
resources/js/pages/Workspaces.tsx (enhance existing)
resources/js/pages/Businesses.tsx (enhance existing)
resources/js/components/workspaces/CreateWorkspaceWizard.tsx
resources/js/components/workspaces/WorkspaceSettings.tsx
resources/js/components/businesses/CreateBusinessModal.tsx
resources/js/components/businesses/SwitchBusinessModal.tsx
resources/js/components/businesses/DeleteBusinessModal.tsx
```

---

## PHASE 2: Sales & Orders (8 tasks)

**Goal:** Complete order-to-cash workflow

### Task F11: Orders List & Management
**Complexity:** High  
**Dependencies:** F1, F2  
**Deliverables:**
- Orders list with advanced filters
- Status badges & timeline
- Quick actions (fulfill, invoice, cancel)
- Bulk operations
- Order search (by customer, product, date)
- Export orders

**Files:**
```
resources/js/pages/orders/Orders.tsx
resources/js/components/orders/OrdersList.tsx
resources/js/components/orders/OrderFilters.tsx
resources/js/components/orders/OrderBulkActions.tsx
```

---

### Task F12: Order Detail & Timeline
**Complexity:** High  
**Dependencies:** F11  
**Deliverables:**
- Order detail page with tabs
- Order timeline (created → fulfilled → invoiced → paid)
- Line items display
- Customer info panel
- Delivery tracking integration
- Payment status
- Related documents (invoices, receipts)
- Action buttons (fulfill, refund, cancel)

**Files:**
```
resources/js/pages/orders/OrderDetail.tsx
resources/js/components/orders/OrderTimeline.tsx
resources/js/components/orders/OrderLineItems.tsx
resources/js/components/orders/OrderCustomerPanel.tsx
resources/js/components/orders/OrderDeliveryTracking.tsx
resources/js/components/orders/OrderPaymentStatus.tsx
resources/js/components/orders/OrderActions.tsx
```

---

### Task F13: Create & Edit Order
**Complexity:** High  
**Dependencies:** F11  
**Deliverables:**
- Multi-step order creation form
- Product search & selection
- Quantity picker
- Price override
- Discount application
- Tax calculation display
- Customer selection/quick-add
- Delivery address form
- Order notes
- Save as draft
- Validation & error handling

**Files:**
```
resources/js/pages/orders/CreateOrder.tsx
resources/js/pages/orders/EditOrder.tsx
resources/js/components/orders/OrderForm.tsx
resources/js/components/orders/ProductSelector.tsx
resources/js/components/orders/CustomerSelector.tsx
resources/js/components/orders/DeliveryAddressForm.tsx
resources/js/components/orders/OrderSummaryPanel.tsx
```

---

### Task F14: Customers Module
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Customers list with segments
- Customer detail page
- Order history per customer
- Customer lifetime value display
- Risk score indicator
- Quick actions (email, call, create order)
- Customer merge interface
- Add/edit customer form
- Export customers

**Files:**
```
resources/js/pages/customers/Customers.tsx
resources/js/pages/customers/CustomerDetail.tsx
resources/js/components/customers/CustomersList.tsx
resources/js/components/customers/CustomerSegments.tsx
resources/js/components/customers/CustomerOrderHistory.tsx
resources/js/components/customers/CustomerForm.tsx
resources/js/components/customers/CustomerMerge.tsx
```

---

### Task F15: Invoicing
**Complexity:** High  
**Dependencies:** F11, F14  
**Deliverables:**
- Invoices list
- Invoice detail & preview
- Create invoice from order
- Create standalone invoice
- Invoice templates (printable)
- Payment recording interface
- Payment allocation
- Credit notes
- Invoice status tracking
- Email invoice
- Download PDF

**Files:**
```
resources/js/pages/invoices/Invoices.tsx
resources/js/pages/invoices/InvoiceDetail.tsx
resources/js/pages/invoices/CreateInvoice.tsx
resources/js/components/invoices/InvoiceList.tsx
resources/js/components/invoices/InvoicePreview.tsx
resources/js/components/invoices/InvoiceTemplate.tsx
resources/js/components/invoices/RecordPayment.tsx
resources/js/components/invoices/AllocatePayment.tsx
resources/js/components/invoices/CreateCreditNote.tsx
```

---

### Task F16: Point of Sale (POS)
**Complexity:** High  
**Dependencies:** F11, F13  
**Deliverables:**
- POS interface (full-screen, optimized)
- Product grid with search
- Cart with quick add/remove
- Customer lookup/quick-add
- Payment methods selector
- Till session management
- Cash drawer open/close
- Day-end reconciliation
- Receipt printing
- Barcode scanner support
- Keyboard shortcuts

**Files:**
```
resources/js/pages/pos/PointOfSale.tsx
resources/js/components/pos/POSProductGrid.tsx
resources/js/components/pos/POSCart.tsx
resources/js/components/pos/POSPayment.tsx
resources/js/components/pos/TillSession.tsx
resources/js/components/pos/DayEndReconciliation.tsx
resources/js/components/pos/Receipt.tsx
resources/js/hooks/useBarcodeScanner.ts
resources/js/hooks/usePOSKeyboard.ts
```

---

### Task F17: Returns & RTO
**Complexity:** Medium  
**Dependencies:** F11, F12  
**Deliverables:**
- Returns list with filters
- Create return from order
- Return reasons dropdown
- Refund vs exchange selection
- Stock receipt on return
- RTO tracking
- Return analytics

**Files:**
```
resources/js/pages/returns/Returns.tsx
resources/js/pages/returns/CreateReturn.tsx
resources/js/components/returns/ReturnsList.tsx
resources/js/components/returns/ReturnForm.tsx
resources/js/components/returns/ReturnReasons.tsx
resources/js/components/returns/RTOTracking.tsx
resources/js/components/returns/ReturnAnalytics.tsx
```

---

### Task F18: Quotes & Estimates
**Complexity:** Medium  
**Dependencies:** F11, F13  
**Deliverables:**
- Quotes list
- Create quote form
- Quote preview & templates
- Convert quote to order
- Quote expiry tracking
- Email quote
- Quote revisions

**Files:**
```
resources/js/pages/quotes/Quotes.tsx
resources/js/pages/quotes/CreateQuote.tsx
resources/js/pages/quotes/QuoteDetail.tsx
resources/js/components/quotes/QuoteForm.tsx
resources/js/components/quotes/QuotePreview.tsx
resources/js/components/quotes/ConvertToOrder.tsx
```

---

## PHASE 3: Inventory & Products (7 tasks)

**Goal:** Complete product catalog and stock management

### Task F19: Product Catalogue
**Complexity:** High  
**Dependencies:** F1, F2  
**Deliverables:**
- Products list with grid/list toggle
- Product detail page
- Create/edit product form
- Variant management interface
- Schema-based variant generator (size × color)
- Product images gallery
- Category management
- Stock levels indicator
- Bulk price update
- Import/export products

**Files:**
```
resources/js/pages/catalogue/Products.tsx
resources/js/pages/catalogue/ProductDetail.tsx
resources/js/pages/catalogue/CreateProduct.tsx
resources/js/components/catalogue/ProductsList.tsx
resources/js/components/catalogue/ProductGrid.tsx
resources/js/components/catalogue/ProductForm.tsx
resources/js/components/catalogue/VariantManager.tsx
resources/js/components/catalogue/VariantGenerator.tsx
resources/js/components/catalogue/ProductImages.tsx
resources/js/components/catalogue/Categories.tsx
resources/js/components/catalogue/BulkPriceUpdate.tsx
resources/js/components/catalogue/ImportProducts.tsx
```

---

### Task F20: Stock Management
**Complexity:** High  
**Dependencies:** F19  
**Deliverables:**
- Stock overview dashboard
- Stock movements list
- Receive stock interface
- Stock adjustment form
- Transfer stock between warehouses
- Stock reservations view
- Batch tracking
- Expiry alerts
- Low stock alerts
- Stock valuation report

**Files:**
```
resources/js/pages/stock/StockOverview.tsx
resources/js/pages/stock/StockMovements.tsx
resources/js/pages/stock/ReceiveStock.tsx
resources/js/pages/stock/AdjustStock.tsx
resources/js/pages/stock/TransferStock.tsx
resources/js/components/stock/StockAlerts.tsx
resources/js/components/stock/BatchTracking.tsx
resources/js/components/stock/StockValuation.tsx
```

---

### Task F21: Warehouses
**Complexity:** Medium  
**Dependencies:** F19, F20  
**Deliverables:**
- Warehouses list
- Warehouse detail with stock levels
- Add/edit warehouse form
- Bin locations management
- Warehouse-to-warehouse transfers
- Warehouse analytics

**Files:**
```
resources/js/pages/warehouses/Warehouses.tsx
resources/js/pages/warehouses/WarehouseDetail.tsx
resources/js/components/warehouses/WarehouseForm.tsx
resources/js/components/warehouses/BinLocations.tsx
resources/js/components/warehouses/WarehouseTransfers.tsx
resources/js/components/warehouses/WarehouseAnalytics.tsx
```

---

### Task F22: Purchasing & Suppliers
**Complexity:** High  
**Dependencies:** F19, F20  
**Deliverables:**
- Suppliers list
- Supplier detail page
- Add/edit supplier form
- Purchase orders list
- Create purchase order
- PO approval workflow
- Receive against PO
- Bills from PO
- Supplier payments

**Files:**
```
resources/js/pages/purchasing/Suppliers.tsx
resources/js/pages/purchasing/SupplierDetail.tsx
resources/js/pages/purchasing/PurchaseOrders.tsx
resources/js/pages/purchasing/CreatePO.tsx
resources/js/pages/purchasing/PODetail.tsx
resources/js/components/purchasing/SupplierForm.tsx
resources/js/components/purchasing/POForm.tsx
resources/js/components/purchasing/POApproval.tsx
resources/js/components/purchasing/ReceivePO.tsx
resources/js/components/purchasing/CreateBillFromPO.tsx
```

---

### Task F23: Bills & Payables
**Complexity:** Medium  
**Dependencies:** F22  
**Deliverables:**
- Bills list
- Bill detail page
- Create standalone bill
- Bill from purchase order
- Payment scheduling
- Make payment interface
- Payables aging report
- Vendor statements

**Files:**
```
resources/js/pages/payables/Bills.tsx
resources/js/pages/payables/BillDetail.tsx
resources/js/pages/payables/CreateBill.tsx
resources/js/components/payables/BillForm.tsx
resources/js/components/payables/PaymentSchedule.tsx
resources/js/components/payables/MakePayment.tsx
resources/js/components/payables/PayablesAging.tsx
```

---

### Task F24: Manufacturing & Production
**Complexity:** High  
**Dependencies:** F19, F20  
**Deliverables:**
- Bill of Materials (BOM) list
- Create/edit BOM
- Production orders list
- Create production order
- Material consumption tracking
- Work-in-progress view
- Finished goods receipt
- Production costing
- Production analytics

**Files:**
```
resources/js/pages/production/BOMs.tsx
resources/js/pages/production/CreateBOM.tsx
resources/js/pages/production/ProductionOrders.tsx
resources/js/pages/production/CreateProductionOrder.tsx
resources/js/pages/production/ProductionOrderDetail.tsx
resources/js/components/production/BOMForm.tsx
resources/js/components/production/MaterialConsumption.tsx
resources/js/components/production/WIPTracking.tsx
resources/js/components/production/ProductionCosting.tsx
```

---

### Task F25: Quality Control
**Complexity:** Medium  
**Dependencies:** F20, F24  
**Deliverables:**
- Quality checks list
- Create quality check
- Inspection forms
- Pass/fail tracking
- Defect logging
- Quality metrics dashboard

**Files:**
```
resources/js/pages/quality/QualityChecks.tsx
resources/js/pages/quality/CreateQualityCheck.tsx
resources/js/components/quality/InspectionForm.tsx
resources/js/components/quality/DefectLog.tsx
resources/js/components/quality/QualityDashboard.tsx
```

---

## PHASE 4: Delivery & Courier Integration (4 tasks)

**Goal:** Unified courier management and shipment tracking

### Task F26: Courier Dashboard
**Complexity:** High  
**Dependencies:** F11, F12  
**Deliverables:**
- Unified courier dashboard (all couriers)
- Shipment creation interface
- Bulk shipment creation
- Courier selection logic
- Label printing
- Tracking integration
- Delivery status timeline
- COD settlement tracking
- Courier performance metrics

**Files:**
```
resources/js/pages/delivery/CourierDashboard.tsx
resources/js/pages/delivery/Shipments.tsx
resources/js/pages/delivery/CreateShipment.tsx
resources/js/components/delivery/CourierSelector.tsx
resources/js/components/delivery/BulkShipments.tsx
resources/js/components/delivery/PrintLabels.tsx
resources/js/components/delivery/ShipmentTracking.tsx
resources/js/components/delivery/DeliveryTimeline.tsx
resources/js/components/delivery/CODSettlement.tsx
resources/js/components/delivery/CourierPerformance.tsx
```

---

### Task F27: Courier Connections
**Complexity:** Medium  
**Dependencies:** F26  
**Deliverables:**
- Courier connections list
- Add courier connection wizard
- Credential management per courier
- Status mapping interface
- Webhook configuration
- Test connection button
- Connection health monitoring

**Files:**
```
resources/js/pages/delivery/CourierConnections.tsx
resources/js/components/delivery/AddCourierWizard.tsx
resources/js/components/delivery/StatusMapping.tsx
resources/js/components/delivery/CourierWebhooks.tsx
resources/js/components/delivery/TestConnection.tsx
resources/js/components/delivery/ConnectionHealth.tsx
```

---

### Task F28: Shipment Detail & Tracking
**Complexity:** Medium  
**Dependencies:** F26  
**Deliverables:**
- Shipment detail page
- Real-time tracking display
- Status history
- Customer notification log
- Re-attempt delivery
- Mark as delivered
- Report issue
- Download proof of delivery

**Files:**
```
resources/js/pages/delivery/ShipmentDetail.tsx
resources/js/components/delivery/TrackingMap.tsx
resources/js/components/delivery/StatusHistory.tsx
resources/js/components/delivery/NotificationLog.tsx
resources/js/components/delivery/ShipmentActions.tsx
resources/js/components/delivery/ProofOfDelivery.tsx
```

---

### Task F29: COD Reconciliation
**Complexity:** Medium  
**Dependencies:** F26  
**Deliverables:**
- COD pending list
- Remittance recording interface
- Reconciliation report
- Commission calculation display
- Settlement history
- Discrepancy tracking

**Files:**
```
resources/js/pages/delivery/CODReconciliation.tsx
resources/js/components/delivery/PendingCOD.tsx
resources/js/components/delivery/RecordRemittance.tsx
resources/js/components/delivery/ReconciliationReport.tsx
resources/js/components/delivery/SettlementHistory.tsx
```

---

## PHASE 5: Conversations & Customer Support (5 tasks)

**Goal:** Omnichannel inbox and customer engagement

### Task F30: Unified Inbox
**Complexity:** High  
**Dependencies:** F1, F2  
**Deliverables:**
- Conversation list with filters
- Multi-channel message view (WhatsApp, FB, Instagram, Email)
- Conversation detail with message thread
- Rich message composer
- Quick replies / canned responses
- Attachment handling
- Conversation assignment
- SLA indicators
- Link to customer/order
- Internal notes
- Mark resolved

**Files:**
```
resources/js/pages/inbox/Inbox.tsx
resources/js/components/inbox/ConversationList.tsx
resources/js/components/inbox/ConversationDetail.tsx
resources/js/components/inbox/MessageThread.tsx
resources/js/components/inbox/MessageComposer.tsx
resources/js/components/inbox/QuickReplies.tsx
resources/js/components/inbox/ConversationFilters.tsx
resources/js/components/inbox/AssignConversation.tsx
resources/js/components/inbox/InternalNotes.tsx
```

---

### Task F31: Channel Management
**Complexity:** Medium  
**Dependencies:** F30  
**Deliverables:**
- Connected channels list
- Add channel wizard (WhatsApp, FB, Instagram, Email, etc.)
- Channel configuration
- Webhook setup
- Test message
- Channel health status
- Auto-assignment rules

**Files:**
```
resources/js/pages/inbox/Channels.tsx
resources/js/components/inbox/AddChannelWizard.tsx
resources/js/components/inbox/ChannelConfig.tsx
resources/js/components/inbox/ChannelHealth.tsx
resources/js/components/inbox/AutoAssignmentRules.tsx
```

---

### Task F32: Message Automations
**Complexity:** Medium  
**Dependencies:** F30  
**Deliverables:**
- Automation rules list
- Create automation wizard
- Trigger configuration (keywords, time-based)
- Action builder (send message, assign, tag)
- Templates management
- Testing & preview
- Analytics per automation

**Files:**
```
resources/js/pages/inbox/Automations.tsx
resources/js/components/inbox/CreateAutomationWizard.tsx
resources/js/components/inbox/TriggerBuilder.tsx
resources/js/components/inbox/ActionBuilder.tsx
resources/js/components/inbox/AutomationAnalytics.tsx
```

---

### Task F33: Helpdesk & Ticketing
**Complexity:** High  
**Dependencies:** F30  
**Deliverables:**
- Tickets list with kanban view
- Create ticket form
- Ticket detail page
- Priority & status management
- SLA tracking with alerts
- Escalation interface
- Ticket assignment
- Merge tickets
- Knowledge base browser
- Ticket templates

**Files:**
```
resources/js/pages/helpdesk/Tickets.tsx
resources/js/pages/helpdesk/TicketDetail.tsx
resources/js/pages/helpdesk/CreateTicket.tsx
resources/js/components/helpdesk/TicketsKanban.tsx
resources/js/components/helpdesk/TicketsList.tsx
resources/js/components/helpdesk/TicketForm.tsx
resources/js/components/helpdesk/SLAIndicator.tsx
resources/js/components/helpdesk/KnowledgeBase.tsx
```

---

### Task F34: Knowledge Base & FAQ Bot
**Complexity:** Medium  
**Dependencies:** F33  
**Deliverables:**
- Knowledge base articles list
- Article editor (rich text)
- Category management
- Article search
- Public article view
- FAQ bot rules
- Bot analytics
- Article feedback

**Files:**
```
resources/js/pages/helpdesk/KnowledgeBase.tsx
resources/js/pages/helpdesk/ArticleEditor.tsx
resources/js/pages/helpdesk/ArticleDetail.tsx
resources/js/components/helpdesk/ArticlesList.tsx
resources/js/components/helpdesk/ArticleCategories.tsx
resources/js/components/helpdesk/BotRules.tsx
resources/js/components/helpdesk/BotAnalytics.tsx
```

---

## PHASE 6: CRM & Marketing (6 tasks)

**Goal:** Complete customer relationship and marketing tools

### Task F35: Leads & Pipeline
**Complexity:** High  
**Dependencies:** F1, F2  
**Deliverables:**
- Leads list with filters
- Lead capture form
- Lead detail page
- Pipeline kanban view
- Drag-and-drop deal stages
- Convert lead to customer
- Deal detail with activities
- Win/loss reasons
- Sales forecasting
- Pipeline analytics

**Files:**
```
resources/js/pages/crm/Leads.tsx
resources/js/pages/crm/LeadDetail.tsx
resources/js/pages/crm/Pipeline.tsx
resources/js/pages/crm/DealDetail.tsx
resources/js/components/crm/LeadsList.tsx
resources/js/components/crm/LeadForm.tsx
resources/js/components/crm/PipelineKanban.tsx
resources/js/components/crm/ConvertToCustomer.tsx
resources/js/components/crm/WinLossForm.tsx
resources/js/components/crm/SalesForecasting.tsx
```

---

### Task F36: Activities & Tasks
**Complexity:** Medium  
**Dependencies:** F35  
**Deliverables:**
- Activity timeline
- Schedule call/meeting
- Log activity
- Task list with reminders
- Activity types configuration
- Calendar view
- Activity reports

**Files:**
```
resources/js/pages/crm/Activities.tsx
resources/js/components/crm/ActivityTimeline.tsx
resources/js/components/crm/ScheduleActivity.tsx
resources/js/components/crm/LogActivity.tsx
resources/js/components/crm/TaskList.tsx
resources/js/components/crm/ActivityCalendar.tsx
```

---

### Task F37: Marketing Campaigns
**Complexity:** High  
**Dependencies:** F14, F30  
**Deliverables:**
- Campaigns list
- Create campaign wizard
- Multi-channel selector (Email, SMS, WhatsApp)
- Audience builder with segments
- A/B test configuration
- Campaign scheduler
- Performance dashboard
- Campaign analytics (opens, clicks, conversions)

**Files:**
```
resources/js/pages/marketing/Campaigns.tsx
resources/js/pages/marketing/CreateCampaign.tsx
resources/js/pages/marketing/CampaignDetail.tsx
resources/js/components/marketing/CampaignWizard.tsx
resources/js/components/marketing/AudienceBuilder.tsx
resources/js/components/marketing/ABTestConfig.tsx
resources/js/components/marketing/CampaignScheduler.tsx
resources/js/components/marketing/CampaignAnalytics.tsx
```

---

### Task F38: Offers & Coupons
**Complexity:** Medium  
**Dependencies:** F19  
**Deliverables:**
- Offers list
- Create offer/coupon
- Discount rules configuration
- Usage limits
- Validity period
- Apply to specific products/categories
- Coupon generation (single/bulk)
- Redemption tracking
- Offer analytics

**Files:**
```
resources/js/pages/marketing/Offers.tsx
resources/js/pages/marketing/CreateOffer.tsx
resources/js/components/marketing/OfferForm.tsx
resources/js/components/marketing/DiscountRules.tsx
resources/js/components/marketing/CouponGenerator.tsx
resources/js/components/marketing/RedemptionTracking.tsx
resources/js/components/marketing/OfferAnalytics.tsx
```

---

### Task F39: Loyalty Programs
**Complexity:** High  
**Dependencies:** F14  
**Deliverables:**
- Loyalty programs list
- Create program wizard
- Points earning rules
- Redemption catalog
- Tier configuration
- Member enrollment
- Points management interface
- Member detail with tier & points
- Rewards redemption interface
- Program analytics

**Files:**
```
resources/js/pages/loyalty/Programs.tsx
resources/js/pages/loyalty/CreateProgram.tsx
resources/js/pages/loyalty/ProgramDetail.tsx
resources/js/pages/loyalty/Members.tsx
resources/js/pages/loyalty/MemberDetail.tsx
resources/js/components/loyalty/ProgramWizard.tsx
resources/js/components/loyalty/EarningRules.tsx
resources/js/components/loyalty/RewardsCatalog.tsx
resources/js/components/loyalty/TierConfig.tsx
resources/js/components/loyalty/PointsManagement.tsx
resources/js/components/loyalty/RedeemReward.tsx
resources/js/components/loyalty/LoyaltyAnalytics.tsx
```

---

### Task F40: Review Incentives
**Complexity:** Medium  
**Dependencies:** F14, F11  
**Deliverables:**
- Review campaigns list
- Create review campaign
- Platform integration (Google, Trustpilot, etc.)
- Incentive configuration
- Automated review requests
- Review submissions tracking
- Incentive fulfillment tracking
- Campaign analytics

**Files:**
```
resources/js/pages/reviews/ReviewCampaigns.tsx
resources/js/pages/reviews/CreateReviewCampaign.tsx
resources/js/pages/reviews/CampaignDetail.tsx
resources/js/components/reviews/CampaignForm.tsx
resources/js/components/reviews/PlatformIntegration.tsx
resources/js/components/reviews/IncentiveConfig.tsx
resources/js/components/reviews/ReviewRequests.tsx
resources/js/components/reviews/ReviewAnalytics.tsx
```

---

## PHASE 7: HR & Payroll (5 tasks)

**Goal:** Complete employee management and payroll

### Task F41: Employees & Organization
**Complexity:** High  
**Dependencies:** F1, F2  
**Deliverables:**
- Employees list
- Employee detail page
- Add/edit employee form
- Organization chart view
- Department management
- Position/job title management
- Document uploads (contracts, IDs)
- Emergency contacts
- Employment history

**Files:**
```
resources/js/pages/hr/Employees.tsx
resources/js/pages/hr/EmployeeDetail.tsx
resources/js/pages/hr/CreateEmployee.tsx
resources/js/pages/hr/OrganizationChart.tsx
resources/js/components/hr/EmployeesList.tsx
resources/js/components/hr/EmployeeForm.tsx
resources/js/components/hr/Departments.tsx
resources/js/components/hr/Positions.tsx
resources/js/components/hr/EmployeeDocuments.tsx
```

---

### Task F42: Attendance & Leave
**Complexity:** High  
**Dependencies:** F41  
**Deliverables:**
- Attendance dashboard
- Clock in/out interface
- Daily attendance view
- Leave requests list
- Apply for leave form
- Leave approval interface
- Leave balance tracker
- Attendance reports
- Shift scheduling

**Files:**
```
resources/js/pages/hr/Attendance.tsx
resources/js/pages/hr/LeaveManagement.tsx
resources/js/components/hr/ClockInOut.tsx
resources/js/components/hr/AttendanceCalendar.tsx
resources/js/components/hr/LeaveRequestForm.tsx
resources/js/components/hr/ApproveLeave.tsx
resources/js/components/hr/LeaveBalance.tsx
resources/js/components/hr/ShiftSchedule.tsx
```

---

### Task F43: Payroll
**Complexity:** High  
**Dependencies:** F41  
**Deliverables:**
- Payroll runs list
- Create payroll run
- Salary structures
- Allowances & deductions configuration
- Payslip generation
- Payslip preview & download
- Bank file export
- Tax calculations display
- Payroll reports

**Files:**
```
resources/js/pages/payroll/PayrollRuns.tsx
resources/js/pages/payroll/CreatePayrollRun.tsx
resources/js/pages/payroll/PayrollRunDetail.tsx
resources/js/pages/payroll/Payslips.tsx
resources/js/components/payroll/SalaryStructures.tsx
resources/js/components/payroll/AllowancesDeductions.tsx
resources/js/components/payroll/PayslipPreview.tsx
resources/js/components/payroll/BankFileExport.tsx
resources/js/components/payroll/PayrollReports.tsx
```

---

### Task F44: Recruitment
**Complexity:** Medium  
**Dependencies:** F41  
**Deliverables:**
- Job postings list
- Create job posting
- Applicants list
- Applicant detail & resume viewer
- Interview scheduling
- Evaluation forms
- Offer letters
- Onboarding checklist
- Recruitment analytics

**Files:**
```
resources/js/pages/hr/Recruitment.tsx
resources/js/pages/hr/JobPostings.tsx
resources/js/pages/hr/CreateJobPosting.tsx
resources/js/pages/hr/Applicants.tsx
resources/js/pages/hr/ApplicantDetail.tsx
resources/js/components/hr/JobPostingForm.tsx
resources/js/components/hr/ScheduleInterview.tsx
resources/js/components/hr/EvaluationForm.tsx
resources/js/components/hr/OnboardingChecklist.tsx
```

---

### Task F45: Performance & Training
**Complexity:** Medium  
**Dependencies:** F41  
**Deliverables:**
- Performance reviews list
- Create review form
- Goal setting interface
- Self-assessment
- Manager assessment
- Training programs list
- Enroll in training
- Training completion tracking
- Skill matrix
- Performance analytics

**Files:**
```
resources/js/pages/hr/Performance.tsx
resources/js/pages/hr/CreateReview.tsx
resources/js/pages/hr/Training.tsx
resources/js/components/hr/ReviewForm.tsx
resources/js/components/hr/GoalSetting.tsx
resources/js/components/hr/SelfAssessment.tsx
resources/js/components/hr/TrainingPrograms.tsx
resources/js/components/hr/SkillMatrix.tsx
```

---

## PHASE 8: Projects & Field Service (4 tasks)

**Goal:** Project management and field operations

### Task F46: Projects
**Complexity:** High  
**Dependencies:** F1, F2, F41  
**Deliverables:**
- Projects list
- Create project form
- Project detail with tabs
- Task management (list, kanban)
- Gantt chart view
- Time tracking interface
- Expense tracking
- Budget vs actual
- Project profitability
- Project timeline

**Files:**
```
resources/js/pages/projects/Projects.tsx
resources/js/pages/projects/CreateProject.tsx
resources/js/pages/projects/ProjectDetail.tsx
resources/js/components/projects/ProjectForm.tsx
resources/js/components/projects/TaskList.tsx
resources/js/components/projects/TaskKanban.tsx
resources/js/components/projects/GanttChart.tsx
resources/js/components/projects/TimeTracking.tsx
resources/js/components/projects/ProjectExpenses.tsx
resources/js/components/projects/BudgetTracking.tsx
```

---

### Task F47: Timesheets
**Complexity:** Medium  
**Dependencies:** F46, F41  
**Deliverables:**
- Timesheet entry interface
- Weekly timesheet view
- Approval workflow
- Time by project report
- Billable hours tracking
- Time exports

**Files:**
```
resources/js/pages/projects/Timesheets.tsx
resources/js/components/projects/TimesheetEntry.tsx
resources/js/components/projects/WeeklyTimesheet.tsx
resources/js/components/projects/ApproveTimesheet.tsx
resources/js/components/projects/TimeReports.tsx
```

---

### Task F48: Work Orders & Field Service
**Complexity:** High  
**Dependencies:** F41  
**Deliverables:**
- Work orders list
- Create work order
- Work order detail
- Technician assignment
- Schedule work order
- Mobile-friendly job card
- Parts usage tracking
- Job completion checklist
- Customer signature capture
- Work order analytics

**Files:**
```
resources/js/pages/fieldservice/WorkOrders.tsx
resources/js/pages/fieldservice/CreateWorkOrder.tsx
resources/js/pages/fieldservice/WorkOrderDetail.tsx
resources/js/pages/fieldservice/JobCard.tsx
resources/js/components/fieldservice/WorkOrderForm.tsx
resources/js/components/fieldservice/AssignTechnician.tsx
resources/js/components/fieldservice/ScheduleJob.tsx
resources/js/components/fieldservice/PartsUsage.tsx
resources/js/components/fieldservice/CompletionChecklist.tsx
resources/js/components/fieldservice/SignatureCapture.tsx
```

---

### Task F49: Fleet Management
**Complexity:** Medium  
**Dependencies:** F48, F41  
**Deliverables:**
- Vehicles list
- Vehicle detail page
- Add/edit vehicle form
- Maintenance schedule
- Fuel tracking
- GPS tracking integration
- Vehicle assignment to jobs
- Fleet analytics

**Files:**
```
resources/js/pages/fieldservice/Fleet.tsx
resources/js/pages/fieldservice/VehicleDetail.tsx
resources/js/components/fieldservice/VehicleForm.tsx
resources/js/components/fieldservice/MaintenanceSchedule.tsx
resources/js/components/fieldservice/FuelTracking.tsx
resources/js/components/fieldservice/GPSTracking.tsx
resources/js/components/fieldservice/FleetAnalytics.tsx
```

---

## PHASE 9: Bookings & Partners (3 tasks)

**Goal:** Appointment booking and partnership management

### Task F50: Bookings & Appointments
**Complexity:** High  
**Dependencies:** F1, F2  
**Deliverables:**
- Bookings calendar view
- Create booking form
- Service catalog
- Resource scheduling (staff, rooms, equipment)
- Availability checker
- Customer booking portal (public)
- Booking confirmation emails
- Reminder system
- Booking analytics

**Files:**
```
resources/js/pages/bookings/Bookings.tsx
resources/js/pages/bookings/CreateBooking.tsx
resources/js/pages/bookings/Calendar.tsx
resources/js/components/bookings/BookingForm.tsx
resources/js/components/bookings/ServiceCatalog.tsx
resources/js/components/bookings/ResourceScheduling.tsx
resources/js/components/bookings/AvailabilityChecker.tsx
resources/js/components/bookings/BookingPortal.tsx
resources/js/components/bookings/BookingAnalytics.tsx
```

---

### Task F51: Partners & Profit Sharing
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Partners list
- Partner detail page
- Add/edit partner form
- Capital account tracking
- Profit sharing configuration
- Drawings tracking
- Distribution calculations
- KYC document management
- Partner statements

**Files:**
```
resources/js/pages/partners/Partners.tsx
resources/js/pages/partners/PartnerDetail.tsx
resources/js/components/partners/PartnerForm.tsx
resources/js/components/partners/CapitalAccount.tsx
resources/js/components/partners/ProfitSharing.tsx
resources/js/components/partners/Drawings.tsx
resources/js/components/partners/DistributionCalc.tsx
resources/js/components/partners/KYCDocuments.tsx
resources/js/components/partners/PartnerStatement.tsx
```

---

### Task F52: Storefronts & Customer Portal
**Complexity:** High  
**Dependencies:** F19, F11  
**Deliverables:**
- Storefronts list
- Create/configure storefront
- Public product catalog view
- Shopping cart
- Checkout flow
- Customer portal (auth separate from staff)
- Order history for customers
- Self-service returns
- Support tickets from portal
- Wishlist

**Files:**
```
resources/js/pages/storefronts/Storefronts.tsx
resources/js/pages/storefronts/StorefrontConfig.tsx
resources/js/pages/storefronts/PublicCatalog.tsx
resources/js/pages/storefronts/PublicCart.tsx
resources/js/pages/storefronts/PublicCheckout.tsx
resources/js/pages/portal/CustomerPortal.tsx
resources/js/pages/portal/CustomerOrders.tsx
resources/js/pages/portal/CustomerReturns.tsx
resources/js/pages/portal/CustomerSupport.tsx
resources/js/pages/portal/Wishlist.tsx
```

---

## PHASE 10: AI & Intelligence (3 tasks)

**Goal:** AI assistant and advanced analytics

### Task F53: AI Assistant Interface
**Complexity:** Medium  
**Dependencies:** F1  
**Deliverables:**
- AI chat interface
- Context-aware prompts
- Business data integration
- Suggested questions
- Response with citations
- Chat history
- Export conversation
- Rate limiting indicator

**Files:**
```
resources/js/pages/assistant/Assistant.tsx
resources/js/components/assistant/ChatInterface.tsx
resources/js/components/assistant/SuggestedQuestions.tsx
resources/js/components/assistant/ChatHistory.tsx
resources/js/components/assistant/RateLimitIndicator.tsx
```

---

### Task F54: AI Reports & Insights
**Complexity:** Medium  
**Dependencies:** F53  
**Deliverables:**
- AI-generated monthly summary
- Anomaly detection alerts
- Trend analysis
- Predictive insights
- Natural language explanations
- Insight actions (drill-down)

**Files:**
```
resources/js/pages/intelligence/AIReports.tsx
resources/js/components/intelligence/MonthlySummary.tsx
resources/js/components/intelligence/AnomalyAlerts.tsx
resources/js/components/intelligence/TrendAnalysis.tsx
resources/js/components/intelligence/PredictiveInsights.tsx
```

---

### Task F55: Forecasting & Alerts
**Complexity:** Medium  
**Dependencies:** F53  
**Deliverables:**
- Sales forecasting dashboard
- Cash flow forecast
- Inventory demand forecast
- Alert configuration
- Alert notifications
- Alert history

**Files:**
```
resources/js/pages/intelligence/Forecasting.tsx
resources/js/pages/intelligence/Alerts.tsx
resources/js/components/intelligence/SalesForecast.tsx
resources/js/components/intelligence/CashFlowForecast.tsx
resources/js/components/intelligence/DemandForecast.tsx
resources/js/components/intelligence/AlertConfig.tsx
resources/js/components/intelligence/AlertNotifications.tsx
```

---

## PHASE 11: Public API & Integrations (2 tasks)

**Goal:** External API and integration management

### Task F56: API Keys Management
**Complexity:** Low  
**Dependencies:** F1  
**Deliverables:**
- API keys list
- Create API key with scopes
- Revoke key
- Usage analytics
- Rate limit configuration
- API documentation viewer

**Files:**
```
resources/js/pages/api/APIKeys.tsx
resources/js/components/api/CreateAPIKey.tsx
resources/js/components/api/KeyScopes.tsx
resources/js/components/api/UsageAnalytics.tsx
resources/js/components/api/APIDocs.tsx
```

---

### Task F57: Integrations Marketplace
**Complexity:** Medium  
**Dependencies:** F56  
**Deliverables:**
- Available integrations catalog
- Integration detail pages
- Connect/configure integration
- Integration status monitoring
- Sync logs
- Disconnect integration

**Files:**
```
resources/js/pages/integrations/Marketplace.tsx
resources/js/pages/integrations/IntegrationDetail.tsx
resources/js/components/integrations/ConnectIntegration.tsx
resources/js/components/integrations/IntegrationStatus.tsx
resources/js/components/integrations/SyncLogs.tsx
```

---

## PHASE 12: Localization & Audit (2 tasks)

**Goal:** Multi-language support and audit logging

### Task F58: Localization Management
**Complexity:** Medium  
**Dependencies:** F1  
**Deliverables:**
- Language packs list
- Add/edit language pack
- String translation interface
- Import/export translations
- Language switcher in UI
- RTL support

**Files:**
```
resources/js/pages/localization/LanguagePacks.tsx
resources/js/pages/localization/EditPack.tsx
resources/js/components/localization/TranslationEditor.tsx
resources/js/components/localization/ImportExport.tsx
resources/js/hooks/useTranslation.ts
resources/js/utils/i18n.ts
```

---

### Task F59: Audit Log
**Complexity:** Medium  
**Dependencies:** F1, F2  
**Deliverables:**
- Audit log viewer
- Filter by user/action/date
- Event detail modal
- Entity change tracking
- Export audit log
- Compliance reports

**Files:**
```
resources/js/pages/audit/AuditLog.tsx
resources/js/components/audit/AuditFilters.tsx
resources/js/components/audit/AuditEventDetail.tsx
resources/js/components/audit/ChangeTracking.tsx
resources/js/components/audit/ComplianceReports.tsx
```

---

## PHASE 13: Mobile & PWA (1 task)

**Goal:** Mobile responsiveness and progressive web app

### Task F60: Mobile Optimization & PWA
**Complexity:** High  
**Dependencies:** All previous tasks  
**Deliverables:**
- Mobile-responsive layouts for all pages
- Touch-optimized controls
- PWA manifest
- Service worker for offline support
- Install prompt
- Push notifications
- Mobile navigation patterns
- Bottom navigation for mobile
- Swipe gestures where appropriate

**Files:**
```
public/manifest.json
public/service-worker.js
resources/js/components/mobile/BottomNav.tsx
resources/js/components/mobile/InstallPrompt.tsx
resources/js/hooks/usePWA.ts
resources/js/utils/mobile.ts
```

---

## Implementation Notes

### Code Quality Standards

**TypeScript:**
- Strict mode enabled
- No `any` types (use `unknown` if truly dynamic)
- Interfaces for all API responses
- Proper null handling

**React:**
- Functional components only
- Custom hooks for reusable logic
- Proper dependency arrays
- Memoization where appropriate (useMemo, useCallback)

**TanStack Query:**
- Consistent query key patterns: `['entity', 'action', ...params]`
- Proper error handling
- Optimistic updates for mutations
- Query invalidation on success

**Styling:**
- Tailwind utility classes
- CSS variables for theming
- Consistent spacing scale
- Responsive breakpoints

**Accessibility:**
- Semantic HTML
- ARIA labels where needed
- Keyboard navigation support
- Focus management
- Screen reader friendly

### Testing Strategy

**Per Task:**
- Manual testing of happy paths
- Edge case validation
- Error state testing
- Loading state verification
- Mobile responsiveness check

**Integration:**
- Real API integration (no mocks in final version)
- Cross-browser testing (Chrome, Firefox, Safari, Edge)
- Performance profiling (Lighthouse)

### Performance Targets

- First Contentful Paint: < 1.5s
- Time to Interactive: < 3.5s
- Largest Contentful Paint: < 2.5s
- Cumulative Layout Shift: < 0.1
- Bundle size per route: < 200KB (gzipped)

---

## Execution Plan

**Estimated Timeline:** 60 tasks × 4-6 hours average = 240-360 hours = 6-9 weeks (full-time)

**Workflow per Task:**
1. Read backend endpoint(s) for the feature
2. Design component hierarchy
3. Create TypeScript types for API responses
4. Build UI components
5. Wire up TanStack Query hooks
6. Add routing
7. Test manually (happy path + errors)
8. Verify mobile responsiveness
9. Check accessibility basics
10. Update router with new route

**Deliverables per Task:**
- Working feature integrated with real API
- TypeScript types for all data
- Responsive UI
- Error handling
- Loading states
- Empty states

**Sign-off Criteria:**
- Feature works end-to-end with real backend
- No console errors
- Mobile-friendly
- Loads within performance budget

---

## Priority Order

If resources are limited, tackle in this order for maximum subscriber value:

**Must-Have (MVP):**
- Phase 1 (Foundation)
- Phase 2 (Sales & Orders) — Core revenue operations
- Phase 3 (Inventory) — Stock management
- F6, F7, F8 (Financial reports) — Compliance requirement

**High Priority:**
- Phase 4 (Delivery) — COD businesses depend on this
- Phase 5 (Inbox) — Customer engagement
- Phase 6 (CRM) — Sales pipeline

**Medium Priority:**
- Phase 7 (HR & Payroll)
- Phase 8 (Projects & Field Service)

**Nice-to-Have:**
- Phase 9 (Bookings)
- Phase 10 (AI)
- Phase 11 (Public API UI)
- Phase 12 (Localization)
- Phase 13 (PWA)

---

**Document Version:** 1.0  
**Last Updated:** 2026-08-14  
**Next Review:** After Phase 1 completion
