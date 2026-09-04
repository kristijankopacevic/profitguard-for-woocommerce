# Native WooCommerce COGS: what the API actually does

Measured on GitHub Actions, not taken from documentation.
WordPress **7.1**, WooCommerce **11.1.0**, PHP 8.3, Ubuntu 24.04.4.
Probe runs `33909360439`, `33909567010`, `33909798826`. Sources: `tests/cogs/probe-*.php`.

## Confirmed as expected

| Fact | Measured |
|---|---|
| `FeaturesController` exists | yes |
| `feature_is_enabled('cost_of_goods_sold')` **default** | `false` — opt-in |
| Product `get/set_cogs_value`, `get_cogs_effective_value` | present |
| Variation `get/set_cogs_value_is_additive` | present |
| Order `has_cogs`, `calculate_cogs_total_value`, `get_cogs_total_value` | present |
| Order item `get_cogs_value`, `calculate_cogs_value_core` | present |
| Simple product round-trip, cost 7.50 | `get_cogs_value()` = `7.5` |
| Parent cost persists | `10.0` after re-fetch |

**Order item COGS is already quantity-multiplied**: 3 × 7.50 gives an item value
of `22.5`, not `7.5`. Multiplying by quantity again double-counts.

## The resolution rules live at the ORDER-ITEM layer, not the product getters

This is the central finding, and it took two probes to state correctly.

With a parent at `10.0` and quantity 2:

| Variation | own | additive | **order item** | core's rule |
|---|---|---|---|---|
| `VPROBE-NONE-DEFAULT` | NULL | false | **20.0** = 10 × 2 | inherits the parent default |
| `VPROBE-OWN-ADDITIVE` | 4.0 | true | **28.0** = (10+4) × 2 | parent **+** own |
| `VPROBE-OWN-REPLACE` | 4.0 | false | **8.0** = 4 × 2 | own **replaces** parent |

The same three variations read through the **product** getters:

| Variation | `get_cogs_value()` | `get_cogs_effective_value()` |
|---|---|---|
| `VPROBE-NONE-DEFAULT` | `NULL` | `0.0` |
| `VPROBE-OWN-ADDITIVE` | `4.0` | `4.0` |
| `VPROBE-OWN-REPLACE` | `4.0` | `4.0` |

**So core applies inheritance and the additive flag when it builds an order
item, and applies neither in the product getters.** An earlier reading of this
probe concluded "the parent default does not propagate" — that is true of the
getters and false as a general statement. The distinction is the whole design:

> ProfitGuard must reproduce core's order-item rule at product level itself,
> because the product getters will not do it — and it must reproduce it
> *exactly*, or product-level margins and WooCommerce's own analytics will
> disagree on every variable product.

The rule to implement, matching the measured table:

```
own    = variation.get_cogs_value()      // nullable
parent = parent.get_cogs_value()         // nullable
if own === null            -> parent            (inherited; may still be null)
if additive && parent!==null -> parent + own    (combined)
otherwise                  -> own               (own replaces)
```

## `get_cogs_effective_value()` fabricates `0.0` and is therefore unusable

```
BARE_SIMPLE  value=NULL  effective=0.0
```

A product with no cost returns `NULL` from `get_cogs_value()` and `0.0` from
`get_cogs_effective_value()`. `0.0` cannot be told apart from a genuine zero
cost, and a zero cost yields a **100% margin** — a confident wrong number, and
exactly the fabrication this plugin exists to refuse.

**Rule: read `get_cogs_value()` only. Never `get_cogs_effective_value()`.**

## Feature disabled: the getter is gated

```
feature_is_enabled_after_disable=false
FALLBACK_get_cogs_value=NULL              (7.5 was stored while it was on)
FALLBACK_get_cogs_effective_value=0.0
```

Turning the feature off makes `get_cogs_value()` return `NULL` for a product
that demonstrably holds a stored value. Reads must therefore be gated on
`feature_is_enabled()`: a store that enabled COGS, entered costs and then
disabled the feature would otherwise look like it has no costs at all. With the
feature off, ProfitGuard falls back to its own meta and to third-party keys, and
must not write into the disabled feature's storage.

## Incidental version facts

- `readme.txt`'s `Tested up to: 7.1` is **true** — WordPress 7.1 is current.
- WooCommerce is **11.1.0**, so the plugin header's `WC tested up to: 11.0` is
  one minor version stale.
- Minor-unit conversion: `Money::parse_decimal_to_minor()` scales by 100 and
  accepts floats, so native (float) ↔ ProfitGuard (integer minor units) is a
  lossless two-decimal round trip.
