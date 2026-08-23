# Plan: a complete order editor

**Goal.** Everything a connected shop holds against an order is visible and, where
it is safe, editable from here — including the shop's own custom and meta
fields, images and files. Nothing silently missing.

**Scope.** WooCommerce, Shopify, Webflow, and custom REST sites.

---

## 1. We already know what each shop sends

Three sources, all present today. Nothing here needs inventing.

**The catalogue** — 36 predefined order fields (`EntityFields::options('order')`),
each with a label and a type. The fixed vocabulary both sides share.

**The mapping** — per connection, which of the shop's paths feed which of ours,
with a direction (Bring only / Push only / Both ways) and a transform.

**A real captured payload** — every sync and webhook stores the last record it
saw (`Integration::rememberPayload`). Your WooCommerce connection currently holds
an order with **141 distinct paths**. `FieldPath::flatten` already turns that into
a path list — it is what the mapping screen is built from.

That third source is the important one: it is the shop's own answer to "what does
an order look like here", taken from a real order rather than from documentation.

> One wrinkle: the stored payload keeps the webhook envelope, so paths read
> `body.status` rather than `status`. The editor must unwrap it or it will offer
> `body.` prefixed nonsense.

---

## 2. An order has five kinds of content

Taken from your actual order payload.

### a. Plain values — ~60 paths
`status`, `currency`, `payment_method_title`, `transaction_id`, `customer_note`,
`date_paid`, totals. Most already covered by the 36-field catalogue.

### b. Nested objects — 2
`billing` and `shipping`, each ~11 fields. Already covered as flat fields
(`shipping_city` and so on), but **billing has fields our catalogue lacks**:
`company`, `address_2`, `state`. Those exist on the shop and cannot currently be
edited here.

### c. Line items — built ✅
Editable, with a catalogue picker. Done.

### d. Meta fields — 32 on your order
The real prize, and section 4.

### e. Collections — 4
`tax_lines`, `coupon_lines`, `fee_lines`, `refunds`. All empty on your order.
Read-only for now (see section 3) — a refund is not something to invent through a
form.

---

## 3. The rule: not everything should be editable

**This is the most important decision in the plan.** A field the shop computes or
owns must be shown but not offered as an input, because editing it either does
nothing or does damage — and both are worse than not offering it.

Three tiers:

**Editable** — what a person legitimately changes: status, addresses, notes,
payment state, line items, their own meta fields.

**Read-only, shown** — facts worth seeing that we must not write:
`id`, `order_key`, `cart_hash`, `date_created`, `customer_ip_address`,
`payment_url`, `is_editable`, `needs_payment`, `currency_symbol`, and every
computed total. Woo discards writes to these.

> This is not theoretical. We spent today on exactly this: `price` on a product is
> read-only in WooCommerce, we were writing it, and every change was accepted with
> a 200 and ignored. Silence is the failure mode.

**Hidden** — plugin bookkeeping nobody edits by hand: `_ga_tracked`,
`_commission_data`, `rank_math_*`, `elementor_*`. Reachable behind "show
everything", never in the main form.

Where the tier comes from, in order: the field map's direction (Bring only ⇒ not
editable), then `EntityFields::isPlatformOwned()`, then a per-platform read-only
list to be written as part of this work.

---

## 4. Meta fields — the user's real custom fields

Your order carries **32 meta entries**. Split:

- **22 underscore-prefixed** — WordPress convention for internal
- **10 public** — and these are unmistakably your real business fields:

```
billing_thana              shipping_thana
order_expected_delivery    order_customer_priority
order_team_note            order_source
order_link                 is_vat_exempt
customer_payment_amount    billing_first_name
```

Those are the fields that make the difference between running your shop from here
and merely watching it.

**Handling:**

1. **Public meta → editable fields**, grouped as "Shop fields", typed by looking
   at the value: a date-looking string gets a date picker, `yes`/`no` a switch, a
   URL a link field, a long string a textarea.
2. **Private meta → collapsed and read-only** by default. `_commission_data`
   holds a plugin's calculations; writing it by hand could corrupt commission
   records.
3. **De-duplicate the pairs.** Many appear twice — `_billing_thana` *and*
   `billing_thana`. Show the public one; write both only if the shop expects it
   (needs testing per plugin).
4. **Promotable.** A private field somebody knows is safe can be promoted to
   editable and remembered against the connection, so the choice is made once.

Meta writes back as WooCommerce expects: `meta_data: [{key, value}]`.

---

## 5. Media

Order-level media is rare, but reachable through meta — a proof-of-delivery
photo, a receipt, a design file.

The typed renderer built for product custom fields already covers this: `image`,
`image_list`, `file`, `video` go to the media column, everything else to the form.
The work here is **detection** — recognising that a meta value is a URL to an
image or file, since Woo's meta carries no type. Rule: parse the value; if it is a
URL with an image extension, treat as image; other URL, treat as a file link;
otherwise text. Overridable, and the override is remembered.

> Reuse `Transform::reverse` for the outbound shape — that is what we fixed today
> so galleries stop being sent as a joined string.

---

## 6. Per-platform differences

| | WooCommerce | Shopify | Webflow | Custom REST |
|---|---|---|---|---|
| Custom fields | `meta_data[]` key/value | `metafields` (namespace, key, type) | CMS fields | whatever the payload holds |
| Editing an order | `PUT /orders/{id}`, broad | many fields read-only after creation; line edits need the GraphQL order-edit flow | limited | depends |
| Line items | `{id, quantity: 0}` to remove | order-edit flow | n/a | depends |
| Creating an order | supported | supported | limited | depends |

**Shopify is the one to be careful with.** A created order is largely immutable —
addresses and notes yes, line items no, not without the newer order-edit API. The
editor must reflect that per platform rather than offering fields that will be
refused.

For **custom REST**, there is no catalogue to consult: the editor is built
entirely from the captured payload plus mapping. That is the same machinery, with
fewer assumptions — which is why doing this generically is worth more than
hard-coding WooCommerce.

---

## 7. Add is a different problem from Edit

Editing sends a change to a record both sides already know. **Creating** means
inventing an order here and asking the shop to accept it, which brings problems
editing does not:

- Each platform has **required fields** for creation (Woo: line items and a
  billing email at minimum)
- The shop assigns the order number — ours is provisional until it answers
- A failed creation must not leave a local order pointing at nothing, or the next
  push creates a **second** one. We have already been bitten by exactly this: a
  missing link produced duplicate order 9585.

**Recommendation: build Edit first, ship it, then Add.** Add is smaller but
riskier, and it benefits from the field work Edit produces.

---

## 8. Build order

**Stage 1 — Field discovery.** Endpoint returning every path the shop sends,
from the captured payload, unwrapped, joined to the mapping, tiered
editable/read-only/hidden. *Foundation for everything else.*

**Stage 2 — Complete the built-ins.** Add the catalogue's missing pieces:
billing `company`, `address_2`, `state`; `currency`; shipping first/last name.
Small, and closes the gaps against Woo's own billing object.

**Stage 3 — Shop fields.** Public meta as typed, editable fields; private meta
collapsed and read-only; de-duplicated. *Biggest single gain — this is where your
ten real fields live.*

**Stage 4 — Read-only panel.** Everything else the shop holds, plainly shown so
nothing is invisible: ids, timestamps, IP, computed totals, empty collections.

**Stage 5 — Media detection.** Recognise image/file/video meta and route it to
the media column.

**Stage 6 — Per-platform rules.** Read-only lists per platform; Shopify's
post-creation restrictions honoured in the form.

**Stage 7 — Add an order.** Required fields per platform, and a creation path
that cannot leave an unlinked local order behind.

---

## 9. What I would push back on

**"Everything editable" is not the goal — "nothing hidden" is.** Roughly a third
of an order's fields are the shop's own bookkeeping. Making those inputs would
produce a form where some edits work, some are ignored, and some corrupt a
plugin's state, with no way to tell which. Show all of it; let people change the
part that is genuinely theirs.

**Two risks worth naming now.** Writing `meta_data` back can disturb plugins that
own those keys — worth testing one plugin field before trusting the pattern. And
a 141-field form is unusable however well grouped; the built-ins stay hand-laid,
the shop's own fields get their own section, and the long tail lives behind
"show everything".

---

## 10. How a field nobody wrote code for gets the right control

Every shop is different. One business adds *Order Source* and *Expected
Delivery*; the next adds *Gift Wrap* and *Warehouse Bay*. None of them can be
hard-coded, and all of them must appear with the right control — a date picker
for a date, a dropdown with the actual options for a choice, a file picker for a
file.

**The hard part: WooCommerce meta carries no type.** It is a flat list of
`{key, value}`. Nothing in the payload says *Order Priority is a dropdown of
three options*. Woo core keeps no registry of order meta, and the plugins that
add checkout fields each store their definitions privately — so there is nothing
to read. The type has to be worked out.

Three layers, each correcting the one before.

### Layer 1 — Read the value (works on the first order, no setup)

| Value seen | Control |
|---|---|
| `30/08/2026`, `2026-08-30` | date |
| `yes` / `no`, `1` / `0`, `true` | switch |
| `https://…/photo.jpg` | image |
| `https://…/spec.pdf` | file |
| `https://…` | link |
| text with newlines, or > 120 chars | textarea |
| purely numeric | number |
| anything else | text |

Right often enough to be useful immediately, and never blocking: a wrong guess is
a text box where a nicer control belonged, not a broken field.

### Layer 2 — Learn from what has been seen (improves by itself)

A key whose value is drawn from a small fixed set is a dropdown, and the set is
its options. Nobody has to say so — it can be observed.

Every synced order already stores its meta on the link. Counting distinct values
per key across a connection's orders:

- `order_source` → facebook, whatsapp, phone, website ⇒ **select** with those four
- `order_customer_priority` → low, medium, high ⇒ **select**
- `order_team_note` → 200 orders, 200 different values ⇒ **free text**

Rule: **20 or more orders seen, 12 or fewer distinct values, none longer than 40
characters ⇒ offer it as a select** with the observed values, plus whatever is
already on the order being edited so an unseen value is never silently dropped.

Below that threshold it stays as Layer 1 decided. Today you have one order stored,
so this contributes nothing yet — it starts paying as orders accumulate.

### Layer 3 — Say what it is (always wins)

A **Shop fields** screen per connection, listing every meta key seen, with:

- **Label** — `order_expected_delivery` → "Expected Delivery"
- **Type** — the guess, changeable, including image / file / video
- **Options** — for a select, editable list
- **Editable?** — or read-only
- **Where** — form, media column, or hidden

Saved against the connection, so the answer is given once and every future order
uses it. This is what makes the system genuinely per-store: two businesses on
WooCommerce with entirely different fields each configure their own, and neither
needs anything built for them.

### Grouping — by key prefix

`billing_thana` belongs with the billing address, not in a bucket of leftovers.
Keys are grouped on their first segment:

- `billing_*` → into the Billing card
- `shipping_*` → into the Delivery card
- `order_*` → "Order details"
- everything else → "Shop fields"

So your `billing_thana` and `shipping_thana` land inside the address blocks where
somebody looking for them would expect them.

### Your fields, as the three layers would handle them

| Key | Value | Layer 1 guess | After Layer 2 | Where |
|---|---|---|---|---|
| `order_source` | `facebook` | text | **select**: facebook, whatsapp, … | Order details |
| `order_customer_priority` | `medium` | text | **select**: low, medium, high | Order details |
| `order_expected_delivery` | `30/08/2026` | **date** ✓ | date | Order details |
| `order_team_note` | `fdada` | text | free text (many values) | Order details |
| `customer_payment_amount` | `0, COD, N/A;` | text ✓ | free text | Order details |
| `billing_thana` | `BD-58-05` | text ✓ | select once enough seen | **Billing card** |
| `shipping_thana` | `BD-58-05` | text ✓ | select once enough seen | **Delivery card** |
| `commission_data` | *(nested object)* | JSON, read-only | — | hidden |

Four of the eight land correctly with no configuration at all. Two more become
dropdowns on their own as orders accumulate. Only relabelling — turning
`order_expected_delivery` into "Expected Delivery" — genuinely wants a human, and
that is one screen visited once.

### Writing back

WooCommerce takes meta as `meta_data: [{key, value}]`, merged rather than
replaced — sending a partial list must not erase keys the form never showed.

Two cautions:

- Many keys exist twice, `_billing_thana` alongside `billing_thana`. Which one a
  plugin reads varies; write the public one and test one field before trusting
  the pattern across all of them.
- Meta owned by a plugin (`_commission_data`) stays read-only. Writing another
  system's bookkeeping by hand is how its records stop adding up.

---

## 11. What the mapping screen now holds — built

Section 10 proposed three layers. Two of them now exist on the storefront's
**Field mapping** tab, which is where the answer for a given shop is recorded.

### The type

Named as every form builder names them — Text, Textarea, WYSIWYG, Select,
Radio, Checkbox, Switch, Number, Money, Email, Phone, URL, Date, Image,
Gallery, File, Video, Colour, JSON.

The stored keys did not change. `trim` still reads Text and `rich_text` still
reads WYSIWYG, so every mapping saved before this keeps working.

### The choices

Select, Radio and Checkbox open a **Choices** row beneath the mapping, entered
as `Facebook|facebook` — label on the left for people, stored value on the
right for the shop. A bare word means both. A pasted comma or newline list adds
several at once.

This is Layer 2's job done deliberately instead of statistically. Counting
distinct values across orders was going to need twenty orders before it could
offer anything, and would still never see an option no order happened to use.
Typing four choices takes a moment and is right immediately. Observation can
still be added later as a *suggestion* — "seen in orders: whatsapp, add it?" —
which is worth more than a guess that silently drops an unseen value.

### What appears when editing

A checkbox per mapping, **ticked by default**. Untick the bookkeeping meta and
plugin internals; the eight fields somebody actually fills in stay. Display
only — an unticked field still syncs both ways.

### The first guess

New rows found by *Add N custom fields* get a type read from the value the shop
sent, so nothing starts as a bare text box when it can be recognised. Against
the live shop: `order_expected_delivery` → Date, `is_vat_exempt` → Switch,
`order_link` → URL. Text otherwise, including for the fields that are really
dropdowns — one value cannot reveal a list, which is exactly why choices are
typed rather than inferred.

### What the editor reads from this

Everything it needs to place and render a field:

| From the mapping | The editor does |
|---|---|
| `visible: false` | omits it entirely |
| `transform` | picks the control |
| `options` | fills the dropdown or radio group |
| `direction: in` | renders it read-only |
| `source` prefix | `billing_*` → Billing card, `shipping_*` → Delivery, `order_*` → Order details, rest → Shop fields |
| media type | moves it to the right-hand column with the images |

So the editor needs no per-shop knowledge of its own. It reads the mapping and
lays out whatever it finds — which is what makes one screen serve a
WooCommerce shop, a Shopify store and a custom site without a branch for each.
