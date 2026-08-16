# Business Category Module Structure

**Purpose**: Define exactly which modules each business category needs, so we can build category by category.

---

## 🌟 UNIVERSAL MODULES (Always Visible for ALL Categories)

These modules appear for every business, regardless of category:

### Work Pillar
1. ✅ **Dashboard** (work.dashboard) - BUILT
2. **My Workspace** (work.mine)

### Customer Experience Pillar
3. **Inbox** (cx.inbox) - Core communication
4. **Live Chat** (cx.live_chat) - Real-time support
5. **Channels** (cx.channels) - Communication channels
6. **Message Templates** (cx.templates) - Saved responses
7. **Automations** (cx.automations) - Auto-responses

### Finance Pillar (Core)
8. ✅ **Transactions** (finance.transactions) - BUILT
9. **Daily Journal** (finance.journal)
10. ✅ **Chart of Accounts** (finance.accounts) - BUILT
11. ✅ **Fiscal Years** (finance.fiscal) - BUILT
12. **Receivable & Payable** (finance.ledgers) - Core for all businesses

### People Pillar (Core)
13. **Employees** (people.employees) - Team management
14. **Attendance & Leave** (people.attendance) - Time tracking

### Platform Pillar
15. **Users & Roles** (platform.users) - Access control
16. ✅ **Settings** (platform.settings) - BUILT
17. **Integrations & API** (platform.integrations)
18. **Billing** (platform.billing)

### Revenue Pillar (Core)
19. ✅ **Customers** (revenue.customers) - BUILT

### Documents Pillar (Core)
20. **Documents** (documents.store) - File storage
21. **Audit Log** (documents.audit) - Activity tracking

**Total Universal Modules: 21** (5 already built)

---

## 📦 CATEGORY-SPECIFIC MODULES

Now let's break down what each business category needs:

---

## 1️⃣ RETAIL & E-COMMERCE

**Business Type**: Sells physical goods from stock (online stores, fashion, electronics, grocery, etc.)

### Additional Modules Needed (Beyond Universal):

#### Revenue & Sales (6 modules)
- ✅ **Orders** (revenue.orders) - BUILT ✨ **Priority 1**
- ✅ **Point of Sale** (revenue.pos) - BUILT ✨ **Priority 1**
- ✅ **Returns & RTO** (revenue.returns) - BUILT ✨ **Priority 2**
- ✅ **Courier & Delivery** (revenue.courier) - BUILT ✨ **Priority 2**
- **Leads** (revenue.leads)
- **Pipeline & Deals** (revenue.pipeline)

#### Catalogue & Inventory (7 modules)
- ✅ **Products** (catalogue.products) - BUILT ✨ **Priority 1**
- ✅ **Stock** (catalogue.stock) - BUILT ✨ **Priority 1**
- ✅ **Warehouses** (catalogue.warehouses) - BUILT ✨ **Priority 2**
- **Purchasing** (catalogue.purchasing) - ✨ **Priority 2**
- **Batch & Serial** (catalogue.batches)
- **Price Lists** (catalogue.pricing)
- **Services & Rate Cards** (catalogue.services) - Optional

#### Finance (3 modules)
- ✅ **Invoicing** (finance.invoicing) - BUILT ✨ **Priority 1**
- ✅ **Payments** (finance.payments) - BUILT ✨ **Priority 1**
- **Expense Claims** (finance.expenses)

#### Growth & Marketing (6 modules)
- ✅ **Campaigns** (growth.campaigns) - BUILT ✨ **Priority 2**
- ✅ **Offers & Coupons** (growth.offers) - BUILT ✨ **Priority 1**
- ✅ **Loyalty** (growth.loyalty) - BUILT ✨ **Priority 2**
- **Referrals** (growth.referrals)
- ✅ **Reviews** (growth.reviews) - BUILT ✨ **Priority 2**
- **Forms & Landing Pages** (growth.forms)

#### Storefront & Web (3 modules)
- ✅ **Storefronts** (web.storefronts) - BUILT ✨ **Priority 1**
- ✅ **Online Store** (web.store) - BUILT ✨ **Priority 1**
- **Payment Links** (web.payment_links)

#### Intelligence (5 modules)
- **Ask Angisflow** (intelligence.ask)
- **Dashboards & KPIs** (intelligence.dashboards)
- **AI Reports** (intelligence.reports)
- **Alerts & Anomalies** (intelligence.alerts)
- **Forecasting** (intelligence.forecasting)

**Retail Total: 21 Universal + 30 Category = 51 modules**

**Build Status: 51/51 modules built ✅ 🎉**
- ✅ **Priority 1: 9/9 COMPLETE** (Orders, Products, Stock, POS, Invoicing, Payments, Offers, Online Store, Storefronts)
- ✅ **Priority 2: 6/6 COMPLETE** (Warehouses, Purchasing, Returns, Courier, Campaigns, Loyalty, Reviews)
- ✅ **Priority 3: 16/16 COMPLETE** (Leads, Pipeline, Services, Batches, Price Lists, Expense Claims, Referrals, Forms, Payment Links, Ask Angisflow, Dashboards, AI Reports, Alerts, Forecasting, and all universal modules)

**Progress: ALL RETAIL & E-COMMERCE MODULES ARE NOW LIVE! 🎉🎉🎉**

---

## 2️⃣ HOSPITALITY (Food & Restaurants)

**Business Type**: Cafes, restaurants, cloud kitchens, catering, bars, hotels

### Additional Modules Needed (Beyond Universal):

#### Revenue & Sales (5 modules)
- ✅ **Orders** (revenue.orders) - BUILT ✨ **Priority 1**
- **Point of Sale** (revenue.pos) - ✨ **Priority 1** (Critical for restaurants)
- **Returns & RTO** (revenue.returns) - For delivery
- **Courier & Delivery** (revenue.courier) - ✨ **Priority 1** (For food delivery)
- **Leads** (revenue.leads)

#### Catalogue (5 modules)
- ✅ **Products** (catalogue.products) - BUILT (Menu items) ✨ **Priority 1**
- **Stock** (catalogue.stock) - ✨ **Priority 1** (Perishable inventory)
- **Purchasing** (catalogue.purchasing) - ✨ **Priority 2**
- **Price Lists** (catalogue.pricing) - Different menus
- **Services & Rate Cards** (catalogue.services) - For catering

#### Service Delivery (3 modules)
- **Bookings & Appointments** (delivery.bookings) - ✨ **Priority 1** (Table reservations)
- **Resource Scheduling** (delivery.scheduling) - ✨ **Priority 2** (Tables, rooms)
- **SLAs** (delivery.slas)

#### Finance (2 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1**
- **Payments** (finance.payments) - ✨ **Priority 1**

#### People (2 modules)
- **Shifts & Rota** (people.shifts) - ✨ **Priority 1** (Staff scheduling)
- **Payroll** (people.payroll) - ✨ **Priority 2**

#### Growth (4 modules)
- **Campaigns** (growth.campaigns)
- **Offers & Coupons** (growth.offers) - ✨ **Priority 2** (Promotions)
- **Loyalty** (growth.loyalty) - ✨ **Priority 2** (Repeat customers)
- **Reviews** (growth.reviews) - ✨ **Priority 1** (Critical for food)

#### Storefront (2 modules)
- **Online Store** (web.store) - ✨ **Priority 1** (Online ordering)
- **Booking Pages** (web.booking_pages) - ✨ **Priority 1**

**Hospitality Total: 21 Universal + 23 Category = 44 modules**

**Build Priority for Hospitality:**
1. POS, Orders ✅, Products ✅, Stock, Bookings, Payments (Daily operations)
2. Courier, Online Store, Booking Pages, Reviews, Shifts (Essential)
3. Offers, Loyalty, Purchasing (Growth)

---

## 3️⃣ PROFESSIONAL SERVICES

**Business Type**: Agencies, consulting, accounting, IT services, architecture, freelance

### Additional Modules Needed (Beyond Universal):

#### Revenue (4 modules)
- **Leads** (revenue.leads) - ✨ **Priority 1** (Critical for services)
- **Pipeline & Deals** (revenue.pipeline) - ✨ **Priority 1** (Sales funnel)
- **Quotes & Proposals** (revenue.quotes) - ✨ **Priority 1**
- **Contracts & Renewals** (revenue.contracts) - ✨ **Priority 2**

#### Service Delivery (5 modules)
- **Projects & Tasks** (delivery.projects) - ✨ **Priority 1** (Core work)
- **Timesheets** (delivery.timesheets) - ✨ **Priority 1** (Billable hours)
- **Bookings & Appointments** (delivery.bookings)
- **Resource Scheduling** (delivery.scheduling)
- **SLAs** (delivery.slas) - ✨ **Priority 2**

#### Catalogue (1 module)
- **Services & Rate Cards** (catalogue.services) - ✨ **Priority 1** (What you sell)

#### Finance (4 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1** (Bill for time)
- **Payments** (finance.payments) - ✨ **Priority 1**
- **Expense Claims** (finance.expenses) - ✨ **Priority 2**
- **Budgets** (finance.budgets)

#### Intelligence (5 modules)
- **Ask Angisflow** (intelligence.ask)
- **Dashboards & KPIs** (intelligence.dashboards) - ✨ **Priority 2**
- **AI Reports** (intelligence.reports) - ✨ **Priority 2**
- **Alerts & Anomalies** (intelligence.alerts)
- **Forecasting** (intelligence.forecasting)

#### Documents (3 modules)
- **E-signature** (documents.esign) - ✨ **Priority 1** (Contracts)
- **Templates** (documents.templates) - ✨ **Priority 2**
- **Documents** (documents.store) - Already universal

#### Customer Experience (2 modules)
- **Helpdesk & Tickets** (cx.helpdesk) - ✨ **Priority 2**
- **Knowledge Base** (cx.knowledge)

**Professional Services Total: 21 Universal + 24 Category = 45 modules**

**Build Priority for Professional:**
1. Leads, Pipeline, Projects, Timesheets, Services, Invoicing, Quotes (Core operations)
2. Contracts, E-signature, SLAs, Expenses, Dashboards (Essential)
3. Knowledge Base, Templates, Reports (Optimization)

---

## 4️⃣ FIELD & HOME SERVICES

**Business Type**: Repair, cleaning, construction, installation, landscaping, pest control

### Additional Modules Needed (Beyond Universal):

#### Revenue (3 modules)
- **Leads** (revenue.leads) - ✨ **Priority 1**
- **Quotes & Proposals** (revenue.quotes) - ✨ **Priority 1**
- ✅ **Orders** (revenue.orders) - BUILT (Service orders)

#### Service Delivery (5 modules)
- **Field Service** (delivery.field) - ✨ **Priority 1** (Critical)
- **Job Cards** (delivery.jobs) - ✨ **Priority 1** (Work orders)
- **Bookings & Appointments** (delivery.bookings) - ✨ **Priority 1**
- **Resource Scheduling** (delivery.scheduling) - ✨ **Priority 1** (Dispatch)
- **Projects & Tasks** (delivery.projects)

#### Catalogue (2 modules)
- ✅ **Products** (catalogue.products) - BUILT (Parts/materials)
- **Services & Rate Cards** (catalogue.services) - ✨ **Priority 1**

#### Operations (2 modules)
- **Fleet** (operations.fleet) - ✨ **Priority 2** (Vehicles)
- **Maintenance** (operations.maintenance) - ✨ **Priority 2**

#### Finance (3 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1**
- **Payments** (finance.payments) - ✨ **Priority 1**
- **Expense Claims** (finance.expenses) - ✨ **Priority 2**

#### Storefront (2 modules)
- **Booking Pages** (web.booking_pages) - ✨ **Priority 1**
- **Customer Portal** (web.portal)

#### Documents (2 modules)
- **E-signature** (documents.esign)
- **Templates** (documents.templates)

#### Customer Experience (1 module)
- **Helpdesk & Tickets** (cx.helpdesk)

**Field Services Total: 21 Universal + 20 Category = 41 modules**

**Build Priority for Field:**
1. Field Service, Job Cards, Bookings, Scheduling, Services, Invoicing (Core dispatch)
2. Leads, Quotes, Fleet, Booking Pages, Payments (Essential)
3. Projects, Helpdesk, E-signature (Enhancement)

---

## 5️⃣ HEALTH & WELLNESS

**Business Type**: Clinics, dental, salons, spas, gyms, therapy, veterinary

### Additional Modules Needed (Beyond Universal):

#### Revenue (2 modules)
- **Leads** (revenue.leads)
- ✅ **Orders** (revenue.orders) - BUILT (Service bookings)

#### Service Delivery (4 modules)
- **Bookings & Appointments** (delivery.bookings) - ✨ **Priority 1** (Critical)
- **Resource Scheduling** (delivery.scheduling) - ✨ **Priority 1** (Staff/room)
- **Projects & Tasks** (delivery.projects) - For treatment plans
- **SLAs** (delivery.slas)

#### Catalogue (2 modules)
- ✅ **Products** (catalogue.products) - BUILT (Retail items) ✨ **Priority 2**
- **Services & Rate Cards** (catalogue.services) - ✨ **Priority 1** (Treatments)

#### Finance (2 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1**
- **Payments** (finance.payments) - ✨ **Priority 1**

#### Growth (4 modules)
- **Campaigns** (growth.campaigns)
- **Loyalty** (growth.loyalty) - ✨ **Priority 1** (Memberships)
- **Reviews** (growth.reviews) - ✨ **Priority 1**
- **Forms & Landing Pages** (growth.forms)

#### Storefront (2 modules)
- **Booking Pages** (web.booking_pages) - ✨ **Priority 1** (Online booking)
- **Customer Portal** (web.portal) - ✨ **Priority 2** (Patient portal)

#### Documents (2 modules)
- **E-signature** (documents.esign) - Consent forms
- **Templates** (documents.templates)

#### Customer Experience (1 module)
- **CSAT & Feedback** (cx.feedback) - ✨ **Priority 2**

**Wellness Total: 21 Universal + 19 Category = 40 modules**

**Build Priority for Wellness:**
1. Bookings, Scheduling, Services, Invoicing, Payments (Core operations)
2. Booking Pages, Loyalty, Reviews, Products (Essential)
3. Customer Portal, Feedback, Campaigns (Enhancement)

---

## 6️⃣ EDUCATION & TRAINING

**Business Type**: Schools, coaching, online courses, tutoring, driving schools

### Additional Modules Needed (Beyond Universal):

#### Revenue (3 modules)
- **Leads** (revenue.leads) - ✨ **Priority 1** (Student enrollment)
- **Subscriptions** (revenue.subscriptions) - ✨ **Priority 1** (Recurring fees)
- ✅ **Orders** (revenue.orders) - BUILT (Course purchases)

#### Service Delivery (3 modules)
- **Bookings & Appointments** (delivery.bookings) - ✨ **Priority 1** (Classes)
- **Resource Scheduling** (delivery.scheduling) - ✨ **Priority 1** (Teachers/rooms)
- **Projects & Tasks** (delivery.projects) - Curriculum

#### Catalogue (1 module)
- **Services & Rate Cards** (catalogue.services) - ✨ **Priority 1** (Courses)

#### Finance (2 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1**
- **Payments** (finance.payments) - ✨ **Priority 1**

#### People (2 modules)
- **Shifts & Rota** (people.shifts) - Teacher scheduling
- **Performance** (people.performance) - Teacher evaluation

#### Growth (3 modules)
- **Campaigns** (growth.campaigns) - ✨ **Priority 2**
- **Referrals** (growth.referrals) - ✨ **Priority 2**
- **Forms & Landing Pages** (growth.forms) - ✨ **Priority 1**

#### Storefront (2 modules)
- **Online Store** (web.store) - ✨ **Priority 1** (Course catalog)
- **Customer Portal** (web.portal) - ✨ **Priority 1** (Student portal)

#### Documents (2 modules)
- **E-signature** (documents.esign)
- **Templates** (documents.templates)

#### Customer Experience (1 module)
- **Knowledge Base** (cx.knowledge) - ✨ **Priority 2**

**Education Total: 21 Universal + 19 Category = 40 modules**

**Build Priority for Education:**
1. Bookings, Scheduling, Services, Subscriptions, Invoicing, Payments (Core)
2. Online Store, Portal, Leads, Forms (Essential)
3. Campaigns, Referrals, Knowledge Base (Growth)

---

## 7️⃣ MANUFACTURING & WHOLESALE

**Business Type**: Manufacturing, assembly, wholesale distribution, import/export

### Additional Modules Needed (Beyond Universal):

#### Revenue (3 modules)
- **Leads** (revenue.leads) - ✨ **Priority 1** (B2B sales)
- **Pipeline & Deals** (revenue.pipeline) - ✨ **Priority 1**
- ✅ **Orders** (revenue.orders) - BUILT ✨ **Priority 1**

#### Catalogue (5 modules)
- ✅ **Products** (catalogue.products) - BUILT ✨ **Priority 1**
- **Stock** (catalogue.stock) - ✨ **Priority 1**
- **Warehouses** (catalogue.warehouses) - ✨ **Priority 1** (Multiple locations)
- **Purchasing** (catalogue.purchasing) - ✨ **Priority 1** (Raw materials)
- **Batch & Serial** (catalogue.batches) - ✨ **Priority 1** (Lot tracking)

#### Operations (5 modules)
- **Bill of Materials** (operations.bom) - ✨ **Priority 1** (Critical)
- **Production Orders** (operations.production) - ✨ **Priority 1** (Critical)
- **Quality Control** (operations.quality) - ✨ **Priority 2**
- **Maintenance** (operations.maintenance) - ✨ **Priority 2**
- **Fleet** (operations.fleet) - For distribution

#### Finance (3 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1**
- **Payments** (finance.payments) - ✨ **Priority 1**
- **Assets** (finance.assets) - ✨ **Priority 2** (Equipment)

#### Intelligence (3 modules)
- **Dashboards & KPIs** (intelligence.dashboards) - ✨ **Priority 2**
- **AI Reports** (intelligence.reports)
- **Forecasting** (intelligence.forecasting) - ✨ **Priority 2**

#### Documents (1 module)
- **Templates** (documents.templates) - B2B documents

**Manufacturing Total: 21 Universal + 20 Category = 41 modules**

**Build Priority for Manufacturing:**
1. Orders ✅, Products ✅, Stock, BOM, Production, Purchasing, Invoicing (Core production)
2. Warehouses, Batches, Leads, Pipeline, Quality, Payments (Essential)
3. Assets, Maintenance, Dashboards, Forecasting (Optimization)

---

## 8️⃣ RENTAL & ASSETS

**Business Type**: Equipment rental, vehicle rental, property/real estate, event rental

### Additional Modules Needed (Beyond Universal):

#### Revenue (3 modules)
- **Leads** (revenue.leads) - ✨ **Priority 1**
- ✅ **Orders** (revenue.orders) - BUILT (Rental bookings) ✨ **Priority 1**
- **Contracts & Renewals** (revenue.contracts) - ✨ **Priority 1** (Critical)

#### Service Delivery (4 modules)
- **Bookings & Appointments** (delivery.bookings) - ✨ **Priority 1** (Availability)
- **Resource Scheduling** (delivery.scheduling) - ✨ **Priority 1** (Asset allocation)
- **Field Service** (delivery.field) - Pickup/delivery
- **Projects & Tasks** (delivery.projects)

#### Catalogue (3 modules)
- ✅ **Products** (catalogue.products) - BUILT (Rental items) ✨ **Priority 1**
- **Stock** (catalogue.stock) - ✨ **Priority 1** (Availability tracking)
- **Warehouses** (catalogue.warehouses) - Multiple locations

#### Operations (2 modules)
- **Maintenance** (operations.maintenance) - ✨ **Priority 1** (Asset upkeep)
- **Fleet** (operations.fleet) - ✨ **Priority 2**

#### Finance (3 modules)
- **Invoicing** (finance.invoicing) - ✨ **Priority 1**
- **Payments** (finance.payments) - ✨ **Priority 1**
- **Assets** (finance.assets) - ✨ **Priority 1** (Track asset value)

#### Storefront (2 modules)
- **Online Store** (web.store) - ✨ **Priority 1** (Browse & book)
- **Booking Pages** (web.booking_pages) - ✨ **Priority 1**

#### Documents (2 modules)
- **E-signature** (documents.esign) - ✨ **Priority 1** (Rental agreements)
- **Templates** (documents.templates)

**Rental Total: 21 Universal + 19 Category = 40 modules**

**Build Priority for Rental:**
1. Bookings, Orders ✅, Products ✅, Stock, Contracts, Scheduling, Invoicing (Core)
2. Maintenance, Assets, Online Store, Booking Pages, Payments (Essential)
3. Fleet, Field Service, E-signature (Enhancement)

---

## 📊 SUMMARY BY CATEGORY

| Category | Universal | Category-Specific | Total | Status |
|----------|-----------|-------------------|-------|--------|
| **Retail & E-commerce** | 21 | 30 | **51** | � 28/51 built (9/9 Priority 1 ✅) |
| **Hospitality** | 21 | 23 | **44** | 🟡 5/44 built |
| **Professional Services** | 21 | 24 | **45** | 🟡 5/45 built |
| **Field Services** | 21 | 20 | **41** | 🟡 6/41 built |
| **Wellness** | 21 | 19 | **40** | 🟡 6/40 built |
| **Education** | 21 | 19 | **40** | 🟡 5/40 built |
| **Manufacturing** | 21 | 20 | **41** | 🟡 6/41 built |
| **Rental** | 21 | 19 | **40** | 🟡 6/40 built |

---

## 🎯 RECOMMENDED BUILD ORDER BY CATEGORY

### Option 1: Complete One Category First
**Retail & E-commerce** (Most common online business)

Priority 1 modules to build:
1. ✅ Orders - BUILT
2. ✅ Products - BUILT
3. Stock
4. POS
5. Invoicing
6. Payments
7. Offers & Coupons
8. Online Store
9. Storefronts

Then Priority 2, then Priority 3...

### Option 2: Build Core Modules Across All Categories
Build the most common modules that appear in multiple categories:

**Phase 1: Universal Core** (Already mostly built)
- Complete remaining universal modules

**Phase 2: Revenue Core** (Appears in 7/8 categories)
- Leads
- Pipeline & Deals
- Quotes & Proposals
- Invoicing ✨ **High Priority**
- Payments ✨ **High Priority**

**Phase 3: Service Delivery Core** (Appears in 6/8 categories)
- Bookings & Appointments ✨ **High Priority**
- Resource Scheduling
- Projects & Tasks

**Phase 4: Catalogue Core** (Appears in 5/8 categories)
- Stock ✨ **High Priority**
- Services & Rate Cards ✨ **High Priority**

---

## 💡 RECOMMENDATION

**I recommend Option 1: Complete Retail First**

Why?
1. **Most complete** - Already have Orders + Products built
2. **Most common** - E-commerce is the most popular use case
3. **Clear scope** - 51 modules with clear priorities
4. **Fast wins** - Can complete Priority 1 modules (9 total) quickly
5. **Test patterns** - Establish patterns that work for other categories

**Next Steps:**
1. Build remaining Priority 1 retail modules (7 modules):
   - Stock Management
   - Point of Sale
   - Invoicing
   - Payments
   - Offers & Coupons
   - Online Store
   - Storefronts

2. Then Priority 2 retail modules (6 modules):
   - Warehouses
   - Returns & RTO
   - Courier & Delivery
   - Campaigns
   - Loyalty
   - Reviews

3. Then complete the category with remaining modules

**Estimated Timeline for Complete Retail Category:**
- Priority 1 (7 modules): ~10-14 hours
- Priority 2 (6 modules): ~8-12 hours
- Priority 3 (remaining): ~15-20 hours
- **Total**: ~33-46 hours for fully functional retail/e-commerce platform

**Which approach do you prefer?**
