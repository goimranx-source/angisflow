# Business Category Architecture Changes

**Date:** 2026-08-14

## Overview

Categories have been moved from workspaces to businesses. This allows each business within a workspace to have its own category and available modules, making the system more flexible.

---

## What Changed

### 1. Database Migration
- **Migration:** `2026_08_14_000032_move_category_from_workspace_to_business.php`
- Removed `business_category_id` from `workspaces` table
- Added `business_category_id` to `businesses` table
- Migrated existing category data from workspaces to businesses

### 2. Business Model Updated
- **File:** `app/Domain/Tenancy/Models/Business.php`
- Added `business_category_id` to fillable fields
- Added `category()` relationship method to access BusinessCategory

### 3. Workspace Creation Simplified
- **File:** `resources/js/components/home/CreateWorkspaceWizard.tsx`
- **Before:** 3-step wizard (category selection → subcategory → workspace details)
- **After:** Simple form (workspace name + icon only)
- Removed category/subcategory selection steps
- Added info message explaining categories are set per business

### 4. Business Creation Enhanced with Category Wizard
- **File:** `resources/js/components/home/CreateBusinessWizard.tsx`
- **Added Steps:**
  1. **Category Selection** - Visual grid of business categories with icons
  2. **Subcategory Selection** - Radio list if parent has children (optional)
  3. **Country Selection** - Where the business trades (sets currency/timezone)
  4. **Money & Time** - Currency and timezone confirmation
  5. **Business Details** - Name, short code, logo upload
- **Before:** 3 steps (country → money → details)
- **After:** 5-6 steps depending on whether subcategory exists
- Progress bar shows current step
- Skip buttons available for category and country
- Shows summary with chosen category icon and module count

### 5. Business Editing Updated
- **File:** `resources/js/components/home/EditBusinessModal.tsx`
- Added category dropdown selector
- Fetches categories from `/business-categories` API endpoint
- Category is required when editing a business
- Updated form submission to include `business_category_id`

### 6. Modal Z-Index Fixed
- **File:** `resources/js/components/ui/Modal.tsx`
- Changed z-index from `z-50` to `z-[100]`
- Ensures modals appear above header and sidebar

### 7. API Route Added
- **File:** `routes/api.php`
- Added `GET /business-categories` route
- Uses existing `WorkspaceEndpoint::categories()` method
- Returns all business categories for dropdown/grid selection

### 8. Plan Limits Updated
- **File:** `database/seeders/PlanSeeder.php`
- Changed from `businesses` to `businesses_per_workspace`
- **Starter:** 2 businesses per workspace
- **Professional:** 10 businesses per workspace
- **Business:** Unlimited businesses per workspace
- **Enterprise:** Unlimited businesses per workspace

### 9. Allowance System
- **File:** `app/Domain/Billing/Allowance.php`
- Uses `businesses_per_workspace` limit key
- Each workspace can have its own business limit
- Enterprise plan workspaces get unlimited businesses

---

## User Experience

### Workspace Creation
**Before:** 3-step wizard with category selection
**After:** Simple 1-step form (name + icon only)

### Business Creation
**Before:** 3 steps (country → money → details)
**After:** 5-6 steps (category → subcategory? → country → money → details)

**Visual Improvements:**
- Category selection uses beautiful icon grid (not dropdown)
- Subcategory uses radio list (if applicable)
- Progress bar shows step progress
- Skip options for category and country
- Summary panel shows: category icon, module count, currency, country, timezone

### Business Editing
- Simple dropdown for category change
- All other fields remain the same

---

## Benefits

1. **Flexibility:** One workspace can contain multiple business types (e.g., Retail + Restaurant)
2. **Simpler Workspace Setup:** No category decision upfront
3. **Better Business Setup:** Category selection during business creation with visual grid
4. **Better mental model:** Business switcher contextually changes what you see
5. **Like competitors:** Matches QuickBooks/Xero model (company switching)
6. **Plan control:** Per-workspace business limits allow flexible pricing tiers

---

## Developer Account Setup

### Account Details
- **Email:** owner@prism.web
- **Password:** prism-dev-password
- **Plan:** Enterprise (unlimited everything)
- **Workspaces:** 2 (Demo, Povaly)
- **Businesses:** 2 (Povaly, Acme Retail)
- **Modules enabled:** All 86 modules

### Business Categories Assigned
- Both businesses assigned "Something else" category
- This gives access to all modules for development testing

---

## Next Steps

### 1. Update Sidebar Logic (Not Started)
- Sidebar currently reads category from workspace (old way)
- Need to update to read from `current_business.category`
- When user switches business, sidebar should update accordingly
- Backend PHP code needs update (not frontend)

### 2. Test Business Creation
- Verify category/subcategory grid appears
- Test skip buttons
- Verify unlimited businesses can be created (Enterprise plan)
- Test category selection shows module count

### 3. Test Business Switching
- Verify switching between businesses with different categories
- Verify sidebar updates when switching

### 4. Update Check Command
- `app/Console/Commands/CheckAccount.php` still references workspace categories
- Update to show business categories instead

---

## Files Modified

### Backend (PHP)
1. `database/migrations/2026_08_14_000032_move_category_from_workspace_to_business.php`
2. `app/Domain/Tenancy/Models/Business.php`
3. `database/seeders/PlanSeeder.php`
4. `routes/api.php`
5. `app/Domain/Billing/Allowance.php`

### Frontend (React/TypeScript)
1. `resources/js/components/home/CreateWorkspaceWizard.tsx` (simplified)
2. `resources/js/components/home/CreateBusinessWizard.tsx` (added category steps)
3. `resources/js/components/home/EditBusinessModal.tsx` (added dropdown)
4. `resources/js/components/ui/Modal.tsx` (z-index fix)

### Documentation
1. `IMPLEMENTATION_STATUS.md` (updated)
2. `BUSINESS_CATEGORY_CHANGES.md` (this file)

---

## Browser Cache Note

After these changes, users need to hard refresh their browser:
- **Windows:** Ctrl + Shift + R or Ctrl + F5
- **Mac:** Cmd + Shift + R
- Or clear browser cache manually

The build creates new file hashes, but browsers may cache the old JavaScript files.

