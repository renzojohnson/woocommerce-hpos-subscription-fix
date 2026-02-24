# WooCommerce HPOS Subscription Fix

Fixes the WooCommerce HPOS bug ([#50944](https://github.com/woocommerce/woocommerce/issues/50944)) that causes orphaned subscriptions with `parent_order_id = 0` and `customer_id = 0` in the `wp_wc_orders` table.

When HPOS (High-Performance Order Storage) is enabled, new subscriptions created during checkout can lose their parent order and customer linkage. This breaks subscription management, customer dashboards, and any plugin that relies on the subscription-to-order relationship (including license managers like WooCommerce API Manager).

## The Problem

During checkout with WooCommerce Subscriptions + HPOS enabled:

1. A parent order is created correctly in `wp_wc_orders`
2. A subscription is created as a child of that order
3. **Bug**: The HPOS datastore writes `parent_order_id = 0` and `customer_id = 0` to `wp_wc_orders` for the subscription row, even though the in-memory `WC_Subscription` object has the correct values
4. The `wp_posts` placeholder row has the correct `post_parent` (the order ID), but the authoritative HPOS table does not

This results in:
- Subscriptions showing "No parent order" in WooCommerce admin
- Subscriptions missing from customer "My Account" pages
- License keys not generating (API Manager queries HPOS `parent_order_id` to find subscriptions)
- Subscription renewals failing to associate with the correct customer

## How It Works

Three-layer defense strategy that catches the bug at every stage:

### Strategy A: Pre-INSERT Normalizer

Hooks `woocommerce_orders_table_datastore_db_rows_for_order` at priority 999 to intercept the HPOS row data **before** it hits the database. If `parent_order_id` or `customer_id` are zero for a subscription being created, it resolves the correct values from the in-memory order object and `wp_posts.post_parent`.

This is the primary fix. It prevents the bad data from ever being written.

### Strategy B: Post-Create Safety Net

Hooks `woocommerce_checkout_subscription_created` at priority 5 to immediately verify the subscription after creation. If Strategy A missed (e.g., different code path, plugin conflict), this reads the correct parent from the checkout order and repairs the subscription.

### Strategy C: Status-Change Backstop

Hooks `woocommerce_order_status_completed` and `woocommerce_order_status_processing` to catch any remaining orphans when the parent order transitions status. Also triggers WooCommerce API Manager license generation if the API Manager plugin is present and no license exists yet.

### Bonus: Manual Pair UI

For subscriptions that were orphaned **before** this plugin was installed, adds a "Pair" link in the WooCommerce Subscriptions list table. Click to link an orphan subscription to its matching order, set the customer, activate the subscription, and generate the license key. Uses a REST endpoint with full auth (capability check + nonce verification).

## Requirements

- WordPress 6.0+
- PHP 8.1+
- WooCommerce with HPOS enabled
- WooCommerce Subscriptions

Optional:
- WooCommerce API Manager (for license recovery in Strategy C and manual pairing)

## Installation

1. Download `woocommerce-hpos-subscription-fix.php`
2. Upload to `/wp-content/mu-plugins/`
3. Done. MU-plugins load automatically.

No configuration needed. The plugin detects WooCommerce Subscriptions and WooCommerce API Manager automatically and only hooks what's available.

```bash
# Via WP-CLI
wp plugin install --activate woocommerce-hpos-subscription-fix.php
# Or simply copy to mu-plugins:
cp woocommerce-hpos-subscription-fix.php /path/to/wp-content/mu-plugins/
```

## Logs

All actions are logged via `wc_get_logger()` with source `hpos-subscription-fix`:

- **Strategy A** (info): "normalized HPOS row before INSERT"
- **Strategy B** (warning): "repaired subscription post-create"
- **Strategy C** (warning): "repaired subscription on status change"

Check logs at **WooCommerce > Status > Logs** and filter by `hpos-subscription-fix`.

## Compatibility

Tested with:
- WooCommerce 9.x with HPOS enabled
- WooCommerce Subscriptions 6.x
- WooCommerce API Manager 3.x
- PHP 8.1, 8.2, 8.3, 8.4, 8.5
- WordPress 6.x

The plugin is self-guarding: if WooCommerce Subscriptions is not installed, it does nothing. If WooCommerce API Manager is not installed, Strategy C skips the license recovery step.

## FAQ

**Does this fix existing orphaned subscriptions?**
No. This prevents **new** orphans from being created. For existing orphans, use the "Pair" link in the subscriptions list table, or fix them manually via WP-CLI / database.

**Is this safe for multisite?**
Yes. Each site loads its own MU-plugins independently.

**Will this conflict with other HPOS plugins?**
Unlikely. Strategy A uses priority 999 on the HPOS filter (runs last), and only modifies rows where `parent_order_id = 0` for subscriptions being created. It does not touch orders, other post types, or updates.

**When will WooCommerce fix this upstream?**
Track progress at [woocommerce/woocommerce#50944](https://github.com/woocommerce/woocommerce/issues/50944). Once fixed in core, you can remove this MU-plugin.

## Related

- [WooCommerce Issue #50944](https://github.com/woocommerce/woocommerce/issues/50944) - HPOS: Subscriptions created with parent_order_id=0
- [WooCommerce HPOS Documentation](https://developer.woocommerce.com/docs/hpos-extension-recipe-book/)
- [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/)

## Author

[Renzo Johnson](https://renzojohnson.com) - WordPress & WooCommerce developer.

Found a bug or need help? [Get in touch](https://renzojohnson.com/contact).

## License

GPL-2.0-or-later
