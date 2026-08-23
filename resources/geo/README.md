# Places

Lists of countries, sub-divisions and areas, keyed by the codes shops store.

Read through `App\Domain\Integrations\Support\Geography`, never by opening these
files directly — it handles the messy input real orders carry (stray spaces,
lower case, a district arriving without its country) and returns `null` rather
than throwing for a code nothing knows.

## Why files rather than an API call

WooCommerce will serve its country list on request. Doing that for every screen
showing an address would be one round trip to somebody else's shop, per page, to
learn something that changes when a country does.

Held here, they work for a shop that is offline, for a platform with no such
endpoint at all, and for an order read out of the database long after the
connection it arrived through was deleted.

## The three levels

```
BD           Bangladesh          countries.json
BD-58        Satkhira            states/bd.json
BD-58-05     Satkhira Sadar      areas/bd.json
```

A code carries its own country as a prefix, which is what lets a district
resolve when an order names one without a country — not a hypothetical, since
this is exactly what the connected shop's orders send.

## What is here

| Path | Holds | Count |
|---|---|---|
| `countries.json` | code → name | 250 |
| `states/<cc>.json` | code → name, one file per country | 69 files, 2,040 total |
| `areas/<cc>.json` | district code → { area code → name } | 581 for Bangladesh |

181 of the 250 countries have no sub-divisions. That is represented by the file
simply not existing, and `Geography` reads a missing file as an empty list
rather than a fault.

## Where each came from

**`countries.json` and `states/`** — read from a live WooCommerce shop's
`GET /wp-json/wc/v3/data/countries`. Taken from WooCommerce rather than an ISO
list on purpose: these have to match what actually arrives on an order, and
where WooCommerce differs from ISO, WooCommerce is the one that is right for
this job.

**`areas/bd.json`** — from `woocommerce-address-field-manager`, the address
plugin this business wrote. No platform models a third level; where a shop wants
a thana, an upazila, a barangay or a ward, somebody has added a plugin and that
plugin invented its own codes. Which is why this file is per-country and why
another shop's third level would be a different file, or none.

## Refreshing

The country and state lists change when a country does — rarely, and worth doing
deliberately rather than on a timer. Re-read them from a connected WooCommerce
shop and rewrite the files; the shape is `{"CODE": "Name"}` sorted by code, and
`Geography` sorts by name for display.

The area list follows its plugin. Copy `data/thana.json` from the plugin, sort
it, and write it as `areas/bd.json` — the shape is already what is wanted.
