# Design system reference

The single source of truth for rebuilding pages. Every value below was read out of
`resources/css/app.css` — nothing here is aspirational, and nothing here is copied
from another product.

**How to use this while rebuilding a page:** take the *structure* from the patterns
in section 4 (what goes where, in what order), and take every *colour, corner,
spacing and font* from sections 1–3. Never write a hex value in a component.

---

## 0. What our product actually looks like

Worth stating plainly, because it is easy to get wrong from memory:

- The brand colour is **cyan** (`#0891b2`), not green.
- The **default theme is light** — a pale blue-white page (`#f4f9fd`) with white cards.
- Dark is opt-in, driven by `data-theme="dark"` on `<html>`, set before first paint
  so a dark preference never flashes light.
- The shell (rail + top bar + cards) separates things with **hairlines, not shadows**,
  and uses a **tight 5px corner**.

---

## 1. Tokens

All defined in `@theme` in `app.css`. Reference them as `var(--token)`.

### Surfaces

| Token | Light | Dark |
|---|---|---|
| `--color-site-bg` | `#f4f9fd` | `#0a1420` |
| `--color-card-bg` | `#ffffff` | `#111d2e` |
| `--color-card-raised` | `#eaf5fb` | `#16283d` |
| `--color-border-light` | `#d6eaf8` | `#22354a` |
| `--color-border-strong` | `#a8cde8` | `#3c5978` |

### Text

| Token | Light | Dark | Use for |
|---|---|---|---|
| `--color-text-main` | `#0d1b2a` | `#e8f0f8` | headings, primary values |
| `--color-text-body` | `#2e3d52` | `#c3d2e0` | body copy, table cells |
| `--color-text-muted` | `#7a8fa6` | `#8296ab` | labels, secondary facts |
| `--color-text-subtle` | `#a8bed4` | `#5b7086` | placeholders, disabled |
| `--color-text-on-accent` | `#ffffff` | `#ffffff` | text on a brand fill |
| `--color-text-on-dark` | `#f4f9fd` | `#0d1b2a` | text on an `--color-ink` fill |

`--color-ink` / `--color-text-on-dark` **invert together** between themes on purpose:
ink is "the solid fill a chip wears", on-dark is "what stays legible against it".
Any rule built on that pair keeps working without knowing which theme it is in.

### Brand

| Token | Light | Dark |
|---|---|---|
| `--color-brand` | `#0891b2` | *(same)* |
| `--color-brand-hover` | `#0e7490` | *(same)* |
| `--color-brand-active` | `#155e75` | *(same)* |
| `--color-brand-text` | `#0e7490` | `#22d3ee` |
| `--color-brand-text-hover` | `#155e75` | `#67e8f9` |
| `--color-brand-subtle` | `#ecfeff` | `#0e2a30` |
| `--color-brand-border` | `#67e8f9` | *(same)* |

Use `--color-brand` for **fills** (buttons, active chips) and `--color-brand-text`
for **text and icons** — they are separated precisely because the fill colour is
too light to read as text.

### Status

| Token | Light | Dark |
|---|---|---|
| `--color-success` | `#2e7d32` | *(same)* |
| `--color-success-subtle` | `#e8f5e9` | `#123320` |
| `--color-danger` | `#ff5c5c` | *(same)* |
| `--color-danger-hover` | `#d94040` | *(same)* |
| `--color-danger-text` | `#b71c1c` | `#ff8a8a` |
| `--color-danger-subtle` | `#fdecea` | `#3a1414` |
| `--color-warning` | `#f59e0b` | *(same)* |
| `--color-warning-subtle` | `#fffbeb` | `#332a10` |
| `--color-info` | `#4059ad` | `#8fa4e8` |
| `--color-info-subtle` | `#eef2fb` | `#1a2340` |
| `--color-link` | `#3b5ba5` | `#7fa3e8` |

`--color-info` is the "in flight" tone — sent, scheduled, in transit. Indigo rather
than a second cyan on purpose: on a page whose primary colour is cyan, a cyan
status pill reads as a brand accent rather than a fact about the record.

`--color-success` is the one status colour with a **different value per theme**
(`#2e7d32` light, `#4ade80` dark). The light value is a deep bottle green that is
unreadable on the dark theme's own dark-green ground.

### The shell

Used by the rail, the top bar, and `.card`. Named separately from the page's own
colours so the whole frame can be re-themed in one block.

`--shell-bg` · `--shell-border` · `--shell-text` · `--shell-text-strong` ·
`--shell-hover` · `--shell-active-soft` · `--shell-tint`

### Corners

| Token | Value |
|---|---|
| `--shell-radius` | `5px` |
| `--shell-radius-sm` | `4px` |
| `--radius-sm` / `md` / `lg` / `card` | `8` / `12` / `16` / `20px` |
| `--radius-pill` | `999px` |

> **These two sets disagree.** `.card`, the rail, every flyout and every menu use
> `--shell-radius` (5px). The older `--radius-*` scale is legacy — `.btn` still
> uses `--radius-md` (12px), which is why a button can look rounder than the card
> it sits in. **New work uses `--shell-radius` / `--shell-radius-sm`.** Pills stay
> pills only where something is genuinely conversational (starter chips, Ask AI).

### Type

| Token | Stack |
|---|---|
| `--font-sans` | Inter → system UI |
| `--font-heading` | Rubik → system UI |
| `--font-mono` | ui-monospace → JetBrains Mono |

`h1`–`h4` already get `--font-heading` + `--color-text-main` from the base layer.
Big numeric values (a KPI figure) also take the heading face — see `StatsCard`.

Fonts are self-hosted, not fetched from Google. Do not add a font CDN link.

### Shadows

`--shadow-xs` … `--shadow-xl`. Used for **things that float** — flyouts, menus,
modals, the peeked rail. A card in the page flow gets a border, not a shadow.

### Layout

| Token | Value | Note |
|---|---|---|
| `--breakpoint-md` | `55rem` (880px) | **`md:` means 880px here, not Tailwind's 768** |
| `--sidebar-open` | `16rem` | |
| `--sidebar-rail` | `5.25rem` | collapsed |
| `--topbar-height` | `4rem` | |
| `--header-bottom` | computed | bottom edge of the header |
| `--header-flyout-top` | computed | where a floating panel starts |
| `--focus-ring` | cyan 3px ring | applied globally via `:focus-visible` |

Anything docked under the header anchors to `--header-bottom`; anything floating
below it anchors to `--header-flyout-top`. Do not hard-code either.

---

## 2. Components that already exist

In `resources/js/components/ui/`:

`Accordion` · `ActionBar` · `ActivityFeed` · `Alert` · `AngisflowLogo` · `Badge` ·
`Breadcrumb` · `Button` · `Confirm` · `DatePicker` · `DateRangePicker` · `Dropdown` ·
`EmptyState` · `Field` · `Icon` · `Modal` · `PageHeader` · `Pagination` · `Preloader` ·
`Skeleton` · `StatsCard` · `StatusBadge` · `Table` · `Tabs` · `Timeline` · `Tooltip`

Charts in `components/ui/Charts/`: `BarChart` · `LineChart` · `PieChart`

**Name collision to know about:** `Badge` is the **rounded-square avatar** that
stands for a workspace or business (initials / logo / icon). It is *not* a status
pill — that is `StatusBadge`.

### `StatusBadge`

The status of one record. Pass the raw API value; the tone is looked up.

```tsx
<StatusBadge status={order.status} />              // 'shipped' → info
<StatusBadge status={year.status} tone="success" />  // override the lookup
<StatusBadge status="on_leave" label="Away until 4 Sep" />
```

`StatusBadge.tsx` holds the one table mapping every state in the product onto five
tones. Add new states there, never a local colour decision on a page — that is how
five different greens happened before.

| Tone | Means | Examples |
|---|---|---|
| `success` | it worked, or it is live | paid, delivered, active, approved |
| `warning` | needs somebody, nothing wrong yet | pending, draft, low stock, on leave |
| `danger` | failed, lapsed, called off | overdue, cancelled, expired, absent |
| `info` | in flight | sent, shipped, scheduled, reserved |
| `neutral` | over, or never applied | archived, closed, inactive |

`statusTone(status)` is exported separately, for when a chart segment or a row
border needs the same colour decision without rendering a badge.

### CSS classes available

`.card` · `.table` (+`-compact` `-striped`) · `.status` (+`-success` `-warning`
`-danger` `-info` `-neutral`, `.status-dot`) · `.btn` (+`-primary` `-secondary`
`-ghost` `-danger`) · `.field` · `.empty-state` · `.list-search` · `.list-head` ·
`.filter-bar` · `.filter-toggle` · `.filter-count` · `.row-menu` (+`-trigger`
`-item`) · `.row-submenu` · `.row-tag` · `.modal` (+`-icon` `-primary-action`
`-danger-action` `-lg`) · `.modal-wizard` · `.choice` / `.choice-grid` ·
`.page-header-icon` · `.topbar-icon` · `.animate-pulse`

`.table` draws no outer border — it expects to sit inside a `.card`, which owns the
edge and the corner. Row modifiers set by `Table.tsx`: `.is-clickable` on a row,
`.is-sortable` / `.is-sorted` on a header cell.

Every control in the shell is **36px (2.25rem) tall** so a field and the button
beside it line up. Keep to it.

---

## 3. Rules

1. **No hex values in components.** If a colour is needed that no token covers,
   add the token first.
2. **Both themes, always.** Anything you add must be checked in light *and* dark.
   Tokens do this for you; a literal does not.
3. **Fills vs text.** `--color-brand` fills, `--color-brand-text` reads.
   Same split for danger (`--color-danger` fills, `--color-danger-text` reads).
4. **Borders separate, shadows float.** In-flow cards get `--shell-border`.
5. **5px corners** on new surfaces (`--shell-radius`), 4px on small marks.
6. **Icons** come from `Icon` and its registry. Caret icons render at `regular`
   weight automatically; everything else defaults to `bold`.
7. **`md:` is 880px.** Do not assume Tailwind's default breakpoint.
8. **Reduced motion** is respected throughout — any animation you add needs a
   `@media (prefers-reduced-motion: reduce)` escape.

---

## 4. Page patterns

The layout skeletons every rebuilt page follows. Structure only — all styling
comes from sections 1–3.

### 4.1 List / index page

The most common page in the product (orders, invoices, customers, products…).

```
PageHeader          title · subtitle · [Print] [Export] [+ Add New]
─────────────────────────────────────────────────────────────────
Toolbar             [search…]  [date range]  [Filter ▾] [Sort ▾]  [⟳]
─────────────────────────────────────────────────────────────────
Card
  Table             sortable headers · status column · row menu (⋮)
  ─────────────────────────────────────────────────────────────
  Footer            "Showing 10 / page ▾"              ‹ 1 2 3 ›
```

Rules:
- Empty state inside the card, never a bare page.
- Loading shows skeleton rows in the table, not a page-level spinner.
- The row menu (`⋮`) holds the per-row verbs; the header holds the page verbs.
- Money right-aligned; status gets its own column; dates in one consistent format.

### 4.2 Dashboard page

```
PageHeader          title · [date range] [Export]
─────────────────────────────────────────────────────────────────
StatsGrid           4 × StatsCard  (value · icon · delta vs period)
─────────────────────────────────────────────────────────────────
Charts row          primary chart (2 cols) · secondary (1 col)
─────────────────────────────────────────────────────────────────
Panels row          Top Customers · Top Products   (each: card +
                    heading + "View all ›" + 5 rows)
```

Rules:
- Every number is real and scoped to the current business. **No invented figures,
  no placeholder revenue** — an empty state is correct when there is no data.
- Every panel says what period it covers.
- A panel with nothing in it shows an empty state, not a zero.

### 4.3 Detail page

```
Breadcrumb          Orders › #SO-1042
PageHeader          record title · status pill · [actions]
─────────────────────────────────────────────────────────────────
Main (2/3)                              Aside (1/3)
  summary card                            related records
  line items table                        activity / timeline
  totals                                  metadata
```

### 4.4 Form / wizard

Use `.modal-wizard` for multi-step: head and foot fixed, body scrolls. A Continue
button that scrolls out of reach reads as broken.

---

## 5. Foundation gaps — found, and closed

All resolved. Kept here because each one explains why something is the way it is,
and because the same mistakes are easy to make again.

| | Was | Now |
|---|---|---|
| 1 | `.table` / `.table-compact` / `.table-striped` undefined — 30 of 48 pages rendered browser-default tables | defined; `Table.tsx` sets `.is-sortable` / `.is-sorted` / `.is-clickable` |
| 2 | no status pill; `Table.tsx` documented a `StatusBadge` that did not exist | `StatusBadge.tsx` + `.status-*`, one tone table for the whole product |
| 3 | `--color-info` / `--color-info-subtle` referenced by 5 files, never defined | defined, light and dark |
| 4 | 8 more colour tokens referenced across 28 files, all resolving to nothing | files rewritten onto the real token names; **zero undefined tokens remain** |
| 5 | `Table.tsx` loading state used undefined `.skeleton` | uses `.animate-pulse`, which is defined and is what `Skeleton.tsx` already used |
| 6 | `.btn` at 12px beside `.field` at 5px in every toolbar | `.btn` takes `--shell-radius` |

Two further bugs surfaced only once the work was on screen, which is the argument
for looking rather than reasoning:

7. **`--color-success` had no dark value.** `#2e7d32` is a deep bottle green —
   correct on white, nearly invisible on the dark theme's own `#123320` ground. So
   a "Paid" pill and a rising trend figure in `StatsCard` were both unreadable in
   dark mode. Now `#4ade80` there.

8. **The skeleton shimmer hard-coded `#d6eaf8`** — light mode's
   `--color-border-light`, written as a literal. In dark mode that is a near-white
   band sweeping across a navy card, so every loading table flashed brighter than
   the content it stood in for. Now the token, so it is the same colour in light
   and correct in dark.

Both are the rule in section 3 stated twice over: a literal is a bug waiting for
the other theme, and a token with only one value is the same bug wearing a name.

### The token rewrite, for reference

| Was (undefined) | Files | Now |
|---|---|---|
| `--color-surface` | 13 | `--color-card-bg` |
| `--color-neutral-subtle` | 12 | `--shell-tint` |
| `--color-bg-subtle` | 3 | `--color-card-raised` |
| `--color-neutral` | 3 | `--color-text-muted` |
| `--color-surface-raised` | 3 | `--color-card-raised` |
| `--color-border` | 2 | `--color-border-light` |
| `--color-border-subtle` | 2 | `--color-border-light` |
| `--color-neutral-hover` | 2 | `--shell-hover` |

Rewritten rather than aliased. A second set of names for the same colours is how
this happened in the first place; adding aliases would have preserved it.

To check this has not regressed, compare the tokens used in `resources/js` against
those defined in `app.css` — the difference should be empty.

## 6. Building a page on this

The foundation in section 5 is done, so a page rebuild is now mostly assembly:

1. **Layout** from the matching pattern in section 4.
2. **Data** from a real endpoint, scoped to the current business. An empty state
   is the correct output when there is nothing — never a placeholder figure.
3. **Table** via `Table`, inside a `.card`.
4. **Status** via `StatusBadge`. If a state is missing, add it to the tone table
   in `StatusBadge.tsx` — not to the page.
5. **Colour** from tokens only. If nothing fits, add a token with both a light
   and a dark value.
6. **Check both themes on screen before calling it done.** Items 7 and 8 in
   section 5 were invisible in the code and obvious in a screenshot.

The Overview dashboard is the reference implementation — build it first, and match
it afterwards.
