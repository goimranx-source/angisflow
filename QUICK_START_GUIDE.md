# Quick Start Guide - Category-Based Navigation

## TL;DR - What Changed

Your sidebar now shows different modules based on your business category! 🎉

---

## For Users

### What You'll See

**Before:** All businesses showed the same 86 modules
**After:** Each business shows 45-60 modules relevant to its industry

### How to Use

1. **Select a business** from the header dropdown
2. **Sidebar updates** automatically with relevant modules
3. **Different business types** see different modules

### Examples

- **Retail store** → See: Orders, POS, Stock, Online Store
- **Restaurant** → See: Orders, POS, Bookings, Shifts
- **Agency** → See: Projects, Timesheets, Contracts
- **Wellness center** → See: Bookings, Products, Services

---

## For Admins

### How to Assign Categories

1. Go to **Businesses** page
2. Click **Edit** on a business
3. Click **Add Category**
4. Select category (e.g., "Restaurant", "Retail", "Agency")
5. Can select **multiple categories** if needed
6. Click **Save**
7. Refresh browser (**Ctrl + Shift + R**)

### Multiple Categories

If your business does multiple things:
- Select both categories (e.g., "Retail" + "Restaurant")
- You'll see modules from BOTH
- Example: Café that sells products → Restaurant + Retail categories

---

## After Deployment

### 1. Hard Refresh Browser
**Press: Ctrl + Shift + R**

This clears cached JavaScript and shows the new navigation.

### 2. Test Different Businesses
Switch between businesses to see different modules.

### 3. Edit Business Categories
Update categories if needed to show more/fewer modules.

---

## What Modules Are Always Visible?

### Core Modules (12 - Always shown)
- Dashboard
- Customers  
- Inbox & Live Chat
- Finance basics (Transactions, Journal, Accounts)
- Settings

### Universal Modules (20 - Shown to everyone)
- Channels, Templates, Automations
- Helpdesk, Knowledge Base
- Ask Angisflow, Dashboards, Reports
- Campaigns, Reviews
- Documents, Integrations

### Category-Specific (Varies by business)
These change based on your business category!

---

## Troubleshooting

**Q: Sidebar hasn't changed?**
A: Hard refresh browser (Ctrl + Shift + R)

**Q: Not seeing expected modules?**
A: Check business has correct categories assigned

**Q: Too many/few modules?**
A: Edit business and adjust categories

**Q: Business has no categories?**
A: Will show core + universal modules only (32 modules)

---

## Technical Notes

- Changes apply **immediately** when switching businesses
- Categories are **cached** for performance
- **Subcategories** (like "Restaurant") use parent category modules ("Food & Hospitality")
- **Multiple categories** show union of all category modules

---

## Need Help?

The system is fully functional and tested. If you experience issues:

1. Hard refresh browser
2. Check business categories are assigned
3. Clear cache: `php artisan cache:clear`
4. Rebuild: `npm run build`

---

**Ready to use! Hard refresh your browser and start exploring!** 🚀
