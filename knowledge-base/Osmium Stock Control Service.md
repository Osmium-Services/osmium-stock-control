# Osmium Stock Control service

Counts stock down when an order is paid, stops customers adding more than is in stock, and treats a kit's parts as stock of
their own. Repo `Osmium-Services/osmium-stock-control`, v0.8.2<!--v:osmium-stock-control-->, category shop. Built 2026-10-10 on a thin core change
shipped first. See [[Shop and Checkout]] and [[Hooks Reference]].

## Core changes it relies on

Option ids on basket lines, a linkable default option, kit parts frozen on order lines (`CheckoutKitParts`), the
`basket.line.check` and `order.check` veto hooks (with `dataSource` in their payload), `ShopKitAvailability`, and the
`dashboard.widgets` hook. A kit shows as out of stock when an always-shown option type has no choice left, and its restock
date is the latest of the blocked types' earliest usable dates.

## What it does

- **Tables:** `stock_levels` (quantity, reorder level, on-order quantity and expected date, a lasting note) and
  `stock_movements`, a ledger of every change. A product with no figure is untracked.
- **Deduct on `order.paid`:** every line takes its own product plus each kit part times the line quantity.
- **Refuse on `basket.line.check` / `order.check`:** a basket line or order that wants more than is in stock is refused with
  a message the customer sees. The veto hooks **fail open on purpose**: a crashing stock check never blocks a sale, and a
  shortfall is flagged on the order panel, not cancelled (Gavin would rather phone a customer about a delayed order).
- **Statuses:** at zero a product flips to the configured out-of-stock status and back on return. Settings choose the two
  statuses; nothing changes until both are chosen, and a deleted status is guarded.
- **Admin** (top-level Stock item): stock list with set or stop tracking, a reorder level, an Ordering filter (reorder,
  ordered, untracked), CSV export and import (blank means leave alone, never clears), on-order stock with an expected date
  and a Receive stock action, a History page per product, the order page panel (shortfall flag and "Return items to
  stock" on refunded orders), and dashboard cards for stock to reorder and overdue deliveries.

## Lessons

Pages reading core's stashed query string must call `clearStashedQuerystring()` or filters stick. Migration SQL must not
contain a semicolon, even in comments. The Store caches its listing, so press Refresh before installing a new tag.

## Not verified

A real provider webhook calling `markPaid`, and use in the SolarGo2 theme. Stock is enforced in the storefront, basket and checkout (`basket.line.check`, `order.check`); the base has
no product view, so themes need to follow the behaviour above.
