# Angisflow Business Model & Architecture Guide

**Date:** 2026-08-13  
**Purpose:** Complete reference for business categories, modules, and dashboards

---

## Overview

Angisflow is a **modular, multi-tenant ERP system** that adapts to different business types. Each workspace can have one or more businesses, and each business selects which **modules** it needs based on its **category/industry**.

---

## Business Categories

Each business belongs to one or more categories. The category determines which modules are available and which dashboard is shown.

### 1. **E-Commerce / Online Shop** 🛍️
**Description:** Businesses selling products online through websites, marketplaces, or mobile apps.

**Core Modules:**
- Product Catalogue (with variants, pricing, images)
- Orders Management (online orders)
- Inventory/Stock Management
- Delivery & Courier Integration
- Customer Management (CRM)
- Invoicing & Payments
- Returns & RTO (Return to Origin)
- Marketing & Campaigns
- Reviews & Ratings

**Dashboard Widgets:**
- Sales revenue chart (daily/weekly/monthly)
- Top-selling products
- Recent online orders
- Conversion rate
- Cart abandonment rate
- Delivery status overview
- Customer acquisition metrics
- Revenue by channel (website, marketplace, social)

**Example Businesses:** Online fashion store, electronics e-commerce, food delivery platform

---

### 2. **Retail / Offline Shop** 🏪
**Description:** Physical stores selling products to walk-in customers.

**Core Modules:**
- Point of Sale (POS)
- Inventory Management (multi-location)
- Product Catalogue
- Customer Management
- Invoicing & Receipts
- Cash Management / Till Sessions
- Returns & Exchanges
- Loyalty Programs

**Dashboard Widgets:**
- Daily sales (today vs yesterday)
- Top products sold today
- Inventory alerts (low stock)
- Till session summary
- Customer footfall (if tracked)
- Sales by cashier/counter
- Payment methods breakdown (cash, card, UPI)

**Example Businesses:** Grocery store, pharmacy, clothing boutique, electronics retail

---

### 3. **Wholesale / Distribution** 📦
**Description:** Businesses selling products in bulk to retailers or other businesses (B2B).

**Core Modules:**
- Product Catalogue (bulk pricing tiers)
- Purchase Orders (from suppliers)
- Sales Orders (to retailers)
- Inventory Management (warehouses)
- Supplier Management
- Invoicing (B2B terms, credit periods)
- Delivery Management
- Accounting & Ledger

**Dashboard Widgets:**
- Sales to top retailers/distributors
- Purchase orders pending
- Inventory valuation
- Outstanding receivables
- Supplier payment schedule
- Warehouse stock levels
- Revenue by customer segment

**Example Businesses:** Food distributor, electronics wholesaler, pharmaceutical distributor

---

### 4. **Service Business** 🔧
**Description:** Businesses providing services rather than physical products.

**Core Modules:**
- Service Booking/Appointments
- Customer Management
- Invoicing & Payments
- Field Service Management (for on-site services)
- Task/Job Management
- Resource Scheduling
- Helpdesk/Support Tickets
- Time Tracking

**Dashboard Widgets:**
- Upcoming appointments
- Service revenue (weekly/monthly)
- Technician/staff utilization
- Pending jobs/tasks
- Customer satisfaction score
- Revenue by service type
- Outstanding invoices
- Response time metrics

**Example Businesses:** Salon, repair shop, consultancy, plumbing services, IT support

---

### 5. **Manufacturing / Production** 🏭
**Description:** Businesses that produce goods from raw materials.

**Core Modules:**
- Bill of Materials (BOM)
- Production Orders
- Manufacturing/Production Tracking
- Raw Material Inventory
- Finished Goods Inventory
- Purchase Orders (raw materials)
- Sales Orders (finished goods)
- Quality Control
- Production Costing

**Dashboard Widgets:**
- Production orders in progress
- Raw material stock levels
- Finished goods ready to ship
- Production efficiency metrics
- Material consumption rate
- Work-in-progress value
- Production vs target
- Quality check pass rate

**Example Businesses:** Garment factory, food processing, furniture manufacturing, electronics assembly

---

### 6. **Field Service** 🚐
**Description:** Businesses providing on-site services at customer locations.

**Core Modules:**
- Work Orders
- Technician/Fleet Management
- GPS Tracking
- Route Optimization
- Customer Management
- Service History
- Invoicing
- Inventory (parts/equipment)
- Scheduling & Dispatch

**Dashboard Widgets:**
- Technicians on field (map view)
- Work orders today (pending, in-progress, completed)
- Average resolution time
- Customer satisfaction
- Parts inventory
- Revenue per technician
- Vehicle utilization
- Next 5 appointments

**Example Businesses:** HVAC services, pest control, appliance repair, cleaning services

---

### 7. **Hospitality / F&B** 🍽️
**Description:** Restaurants, cafes, hotels providing food and accommodation services.

**Core Modules:**
- POS (table management for restaurants)
- Menu/Item Management
- Kitchen Order Tracking (KOT)
- Table Reservations
- Inventory (ingredients, supplies)
- Supplier Management
- Customer Management (loyalty, feedback)
- Billing & Payments

**Dashboard Widgets:**
- Today's sales (by meal period: breakfast, lunch, dinner)
- Top-selling dishes
- Table occupancy rate
- Average order value
- Kitchen pending orders
- Inventory alerts (perishables)
- Customer reviews/ratings
- Revenue by dine-in/takeaway/delivery

**Example Businesses:** Restaurant, cafe, cloud kitchen, hotel, catering service

---

### 8. **Human Resources (HR)** 👥
**Description:** Internal HR management for any business type (module add-on).

**Core Modules:**
- Employee Management
- Attendance & Leave
- Payroll
- Performance & Appraisal
- Recruitment
- Training & Development
- HR Analytics

**Dashboard Widgets:**
- Total employees
- Attendance today
- Leave requests pending
- Upcoming appraisals
- Open positions
- Payroll summary
- Employee turnover rate
- Training completion rate

**Note:** HR is typically an **add-on module** for other business categories, not a standalone category.

---

### 9. **CRM (Customer Relationship Management)** 🤝
**Description:** Lead and opportunity management (module add-on).

**Core Modules:**
- Leads & Pipeline
- Opportunity Tracking
- Activities & Tasks
- Sales Forecasting
- Customer Segmentation
- Marketing Campaigns
- Deal Management

**Dashboard Widgets:**
- Pipeline value by stage
- Leads this month
- Conversion rate
- Activities due today
- Deals won/lost
- Sales forecast
- Top sales reps
- Customer lifetime value

**Note:** CRM is typically an **add-on module** for sales-driven businesses.

---

## Module Catalog

Angisflow has a **modular architecture**. Businesses enable only the modules they need.

### Core Modules (Available to All)
1. **Dashboard** - Business overview with KPIs
2. **Settings** - General configuration
3. **Users & Permissions** - Team management
4. **Accounting & Ledger** - Financial transactions
5. **Reports** - Financial reports (P&L, Balance Sheet)

### Trading Modules
6. **Product Catalogue** - Product management with variants
7. **Inventory/Stock** - Stock tracking, warehouses
8. **Orders** - Order management (online/offline)
9. **Invoicing** - Invoice generation and tracking
10. **POS (Point of Sale)** - For retail/restaurant
11. **Customers** - Customer database and CRM
12. **Suppliers** - Supplier management
13. **Purchase Orders** - Procurement management

### Service Modules
14. **Booking/Appointments** - Service scheduling
15. **Work Orders** - Service job management
16. **Field Service** - Technician/fleet management
17. **Helpdesk/Tickets** - Customer support

### Manufacturing Modules
18. **Bill of Materials (BOM)** - Product recipes
19. **Production Orders** - Manufacturing tracking
20. **Quality Control** - QC inspections

### Communication Modules
21. **Inbox (Omnichannel)** - WhatsApp, FB, Instagram, Email
22. **Marketing Campaigns** - Email, SMS, WhatsApp campaigns

### Delivery Modules
23. **Courier Integration** - Unified courier management
24. **Shipment Tracking** - Real-time tracking
25. **Returns & RTO** - Return management

### Growth Modules
26. **Loyalty Programs** - Points and rewards
27. **Reviews & Ratings** - Customer feedback
28. **Offers & Coupons** - Discount management

### HR Modules
29. **Employee Management** - Staff database
30. **Attendance & Leave** - Time tracking
31. **Payroll** - Salary processing

### CRM Modules
32. **Leads & Pipeline** - Sales pipeline
33. **Opportunities** - Deal tracking
34. **Activities** - Task management

---

## Dashboard Strategy

### Multi-Dashboard Approach

Each business category has its own **optimized dashboard**:

```
Dashboard (Menu Item)
├── E-Commerce Dashboard      (if business is e-commerce)
├── Retail Dashboard           (if business is retail)
├── Service Dashboard          (if business is service)
├── Manufacturing Dashboard    (if business is manufacturing)
└── Field Service Dashboard    (if business is field service)
```

### Example Scenarios

**Scenario 1: Pure E-Commerce Business**
- Category: E-Commerce
- Dashboard Menu: Only shows "E-Commerce Dashboard"
- Modules: Catalogue, Orders, Inventory, Delivery, Customers, Invoicing

**Scenario 2: Retail + E-Commerce (Omnichannel)**
- Category: Retail, E-Commerce
- Dashboard Menu: 
  - "Retail Dashboard" (default)
  - "E-Commerce Dashboard"
- Modules: POS, Catalogue, Orders, Inventory, Delivery, Customers

**Scenario 3: Service Business**
- Category: Service
- Dashboard Menu: Only shows "Service Dashboard"
- Modules: Booking, Customers, Invoicing, Work Orders

**Scenario 4: Manufacturing + Wholesale**
- Category: Manufacturing, Wholesale
- Dashboard Menu:
  - "Manufacturing Dashboard" (default)
  - "Wholesale Dashboard"
- Modules: BOM, Production, Inventory, Purchase Orders, Sales Orders

---

## Sidebar Navigation

The sidebar menu is **dynamic** based on:
1. **Business category** - Determines which modules are shown
2. **Enabled modules** - Only shows modules the business has enabled
3. **User permissions** - Only shows what the user can access

### Sidebar Structure Example (E-Commerce)

```
📊 Dashboard
   └── E-Commerce Dashboard

🛍️ Sales
   ├── Orders
   ├── Customers
   ├── Invoices
   └── Quotes

📦 Inventory
   ├── Products
   ├── Stock
   └── Warehouses

🚚 Delivery
   ├── Shipments
   ├── Courier Connections
   └── Returns

💬 Conversations
   ├── Inbox
   ├── Channels
   └── Automations

📈 Growth
   ├── Marketing Campaigns
   ├── Offers & Coupons
   └── Reviews

💰 Accounting
   ├── Accounts
   ├── Transactions
   └── Reports

⚙️ Settings
   ├── General
   ├── Users & Roles
   ├── Integrations
   └── Webhooks
```

### Sidebar Structure Example (Service Business)

```
📊 Dashboard
   └── Service Dashboard

📅 Service
   ├── Appointments
   ├── Work Orders
   └── Customers

🎫 Helpdesk
   ├── Tickets
   └── Knowledge Base

💰 Billing
   ├── Invoices
   └── Payments

💬 Conversations
   └── Inbox

⚙️ Settings
```

---

## Frontend Implementation Strategy

### Phase 1: Core UI ✅ (Complete)
- Base components
- Layout templates
- Charts

### Phase 2: Category-Specific Dashboards 🔄 (Current)
- E-Commerce Dashboard
- Retail Dashboard
- Service Dashboard
- Manufacturing Dashboard
- Field Service Dashboard

### Phase 3: Trading Modules (Orders, Products, Inventory)
- Module screens use shared components
- Adapt to category context

### Phase 4: Service Modules (Booking, Work Orders, Helpdesk)

### Phase 5: Communication & Growth Modules (Inbox, Marketing, Loyalty)

### Phase 6: Advanced Modules (Manufacturing, Field Service, HR, CRM)

---

## Business Category Selection

When creating a business, the user selects:
1. **Primary Category** (required) - e.g., E-Commerce
2. **Secondary Categories** (optional) - e.g., Retail (for omnichannel)

Based on selection:
- System recommends core modules
- Default dashboard is set
- Sidebar navigation is configured
- Appropriate onboarding flow is shown

---

## Module Provisioning

Modules can be:
1. **Included in plan** - Free based on business category
2. **Add-on** - Purchased separately (e.g., Advanced CRM, HR)
3. **Beta** - Available for testing

The **Product Catalogue** system (backend) handles:
- Module availability per category
- Pricing and plans
- Feature flags
- Entitlements

---

## Current Implementation Status

### Backend (38 tasks) ✅
- Multi-tenancy with `account_id`
- Module provisioning system
- All core APIs ready
- Trading modules APIs ready
- Service modules APIs ready
- Communication modules APIs ready

### Frontend Progress
- ✅ Phase 1: Core UI (20 components)
- ✅ Phase 2: Layout Components (12 components)
- 🔄 Phase 3: Dashboard (category-specific) - **CURRENT**
- ⏳ Phase 4: Trading Modules
- ⏳ Phase 5: Service Modules
- ⏳ Phase 6: Communication Modules

---

## Key Architectural Decisions

### 1. Single Schema Multi-Tenancy
- All tables have `account_id`
- Data isolation at application level
- Efficient resource usage

### 2. Modular System
- Each module is independent
- Modules can be enabled/disabled
- Clean separation of concerns

### 3. Category-Driven UX
- Different dashboards for different business types
- Sidebar adapts to business category
- Terminology changes per category (e.g., "Orders" vs "Bookings")

### 4. API-First
- Backend provides REST APIs
- Frontend consumes APIs
- Clear contracts between layers

### 5. Two Identifiers
- `id` - Internal database ID (for FKs)
- `public_id` - ULID for URLs and API responses

### 6. Money as Integers
- All money values stored as minor units (cents)
- Never use floats for money
- Use Money value object

---

## Next Steps

1. ✅ Create this guide document
2. 🔄 Build category-specific dashboards
3. Update sidebar navigation to be category-aware
4. Implement dashboard menu with sub-items for multi-category businesses
5. Build module screens using page layout templates

---

**Summary:** Angisflow is a flexible, modular ERP that adapts to 6+ business categories. Each category has optimized dashboards and modules. The system is multi-tenant, API-first, and fully modular.
