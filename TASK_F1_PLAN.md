# Task F1: Enhanced UI Component Library — Implementation Plan

**Date:** 2026-08-14  
**Status:** Ready to execute  
**Dependencies:** None (foundation task)

---

## AUDIT COMPLETE ✅

**What Already Exists:**
- ✅ Badge, Button, Field, Icon, PageHeader, Skeleton, EmptyState, Preloader
- ✅ Table (just created - no conflicts)
- ✅ Toast system (complete in `lib/toast.ts` + `shell/Toasts.tsx`)
- ✅ 29 domain-specific components (home, media, shell, etc.)
- ✅ 16 custom hooks
- ✅ API layer, routing, auth, tenant context

**What We Need to Build (20 components):**

---

## BUILD ORDER (by dependency)

### Group 1: Form Inputs (8 components)
These are independent and can be built in parallel.

1. ✅ **Input** - Text input (standalone, simpler than Field)
2. ✅ **Textarea** - Multi-line text input
3. ✅ **Select** - Dropdown select
4. ✅ **Checkbox** - Checkbox input
5. ✅ **Radio** - Radio button
6. ✅ **Switch** - Toggle switch
7. ✅ **SearchInput** - Input with debounce & clear button
8. ✅ **FileUpload** - File upload with drag-drop & preview

---

### Group 2: Interactive Overlays (3 components)
Modal depends on nothing, Dropdown uses Modal patterns, Confirm uses Modal.

9. ✅ **Modal** - Reusable dialog system
10. ✅ **Dropdown** - Menu/popover component
11. ✅ **Confirm** - Confirmation dialog (uses Modal)

---

### Group 3: Date & Specialized Inputs (2 components)

12. ✅ **DatePicker** - Single date picker
13. ✅ **MoneyInput** - Formatted currency input (integrates with Money value object)

---

### Group 4: Navigation & Organization (3 components)

14. ✅ **Tabs** - Tab navigation
15. ✅ **Accordion** - Collapsible sections
16. ✅ **Breadcrumb** - Breadcrumb navigation

---

### Group 5: Feedback & Info (2 components)

17. ✅ **Tooltip** - Hover tooltip
18. ✅ **Alert** / **Banner** - Inline alerts

---

### Group 6: Data Display (2 components)

19. ✅ **Pagination** - Page-based pagination
20. ✅ **StatsCard** - Reusable KPI card (extract pattern from Dashboard)

---

## DESIGN PRINCIPLES

### 1. Follow Existing Patterns
- Use CSS variables for colors (never hardcode)
- Use `cn()` utility for classnames
- Use forwardRef for components that need refs
- Use TypeScript strict mode (no `any`)
- Follow accessibility guidelines from Field.tsx

### 2. CSS Variable Usage
```tsx
// Good
className="text-[var(--color-text-main)]"
className="bg-[var(--color-surface)]"
className="border-[var(--color-border)]"

// Bad
className="text-gray-900"
className="bg-white"
```

### 3. Accessibility
- Proper ARIA labels
- Keyboard navigation support
- Focus management
- Screen reader friendly
- Semantic HTML

### 4. Component API Design
- Props over render props (unless composition needed)
- Controlled & uncontrolled modes where appropriate
- Loading states built-in
- Error states built-in
- Disabled states
- Size variants where appropriate

---

## FILE STRUCTURE

All components go in: `resources/js/components/ui/`

### Group 1: Form Inputs
```
resources/js/components/ui/Form/Input.tsx
resources/js/components/ui/Form/Textarea.tsx
resources/js/components/ui/Form/Select.tsx
resources/js/components/ui/Form/Checkbox.tsx
resources/js/components/ui/Form/Radio.tsx
resources/js/components/ui/Form/Switch.tsx
resources/js/components/ui/Form/SearchInput.tsx
resources/js/components/ui/Form/FileUpload.tsx
resources/js/components/ui/Form/MoneyInput.tsx
```

### Group 2-6: Other Components
```
resources/js/components/ui/Modal.tsx
resources/js/components/ui/Dropdown.tsx
resources/js/components/ui/Confirm.tsx
resources/js/components/ui/DatePicker.tsx
resources/js/components/ui/Tabs.tsx
resources/js/components/ui/Accordion.tsx
resources/js/components/ui/Breadcrumb.tsx
resources/js/components/ui/Tooltip.tsx
resources/js/components/ui/Alert.tsx
resources/js/components/ui/Pagination.tsx
resources/js/components/ui/StatsCard.tsx
```

---

## IMPLEMENTATION APPROACH

### Phase 1: Form Components (Priority 1)
Start with form components because they're used in almost every page:
- Input, Textarea, Select, Checkbox, Radio, Switch
- SearchInput, FileUpload, MoneyInput

### Phase 2: Modal System (Priority 2)
Critical for dialogs, confirmations, forms:
- Modal, Confirm

### Phase 3: Navigation (Priority 3)
Used in many layouts:
- Tabs, Breadcrumb, Pagination

### Phase 4: Feedback & Data (Priority 4)
Nice-to-have enhancements:
- Dropdown, Tooltip, Alert, StatsCard, Accordion, DatePicker

---

## VERIFICATION CHECKLIST (Per Component)

- [ ] TypeScript types exported
- [ ] Accessibility (keyboard, ARIA, focus)
- [ ] CSS variables (no hardcoded colors)
- [ ] Loading/disabled/error states
- [ ] forwardRef where needed
- [ ] Example usage in docstring
- [ ] Follows existing naming patterns
- [ ] No console errors/warnings

---

## NEXT STEPS

1. ✅ Delete Table.tsx I just created (keep audit, will rebuild properly)
2. ✅ Start with **Input.tsx** (simplest form component)
3. ✅ Build remaining form components
4. ✅ Build Modal & Confirm
5. ✅ Build remaining components
6. ✅ Update FRONTEND_AUDIT.md with progress

---

**Ready to execute Phase 1: Form Components**
