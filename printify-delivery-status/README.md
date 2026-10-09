# Printify Delivery Status 1.0.4

1.0.4 fixes synchronization stopping on WooCommerce refund records: queries explicitly select `shop_order`, and only actual order objects can be indexed or manually linked. Refunded parent orders remain eligible; separate refund records are skipped. Existing links to invalid/deleted records require review.

Default source tab: `printify_trackorder_sync`. On upgrade, the previous default `printify_multi_orders` is migrated once; other custom tab names are preserved.

## Installation
1. Upload the plugin ZIP in WordPress → Plugins → Add New → Upload Plugin, then activate.
2. Put the service account JSON outside the public web directory on the WordPress server. Do not include it in the ZIP. The Windows path on your computer is not the path on your hosting server.
3. Open WooCommerce → Printify Delivery and enter the absolute server path to the JSON.
4. Enter your spreadsheet ID and verify the tab, save, then click Sync now. New installations have no default spreadsheet ID; existing saved settings are preserved.
5. Refresh after the background job finishes. Review candidate matches and manually approve multiple sources before grouping.

## Behavior
- Hourly background sync using Action Scheduler, with WP-Cron fallback.
- Shipping recipient name plus shipping phone (billing phone fallback), filtered by available Address 1, Postcode, Country Code and nonempty source Address 2.
- Street abbreviations and postcode spaces/hyphens are normalized; house and apartment numbers are preserved. Missing source address fields do not block name/phone fallback. A populated source field conflicting with WooCommerce rejects that candidate.
- Existing automatic links with newly conflicting addresses stop updating and display Needs review. Confirmed manual links override matching. Older links created manually before 1.0.2 should be re-saved to mark them as manual overrides.
- Case and whitespace normalization; US/CA leading 1 normalization. No fuzzy name matching.
- Ambiguous matches are not automatically assigned. Multiple sources require manual approval.
- Existing links persist by Shop ID + Printify Order ID, including when rows are sorted.
- No match is displayed until linked. Source statuses stay separate for multiple linked sources.
- Source checked time older than three hours is marked Stale.
- Last successful data is retained on Google failures or empty/invalid sheets.
- Does not change WooCommerce order status or write Google Sheet cells.
- HPOS and legacy order list supported; delivery column is appended to the existing columns.
- Deactivation removes plugin schedules; stored links and data remain.

## WordPress updates
- Install 1.0.3 manually once: Plugins → Add New → Upload Plugin → choose the release ZIP → Replace current with uploaded. Back up your site first.
- Later releases appear under Plugins and Dashboard → Updates. Click Update now normally. Saved settings, order links and metadata are retained; the service account JSON must stay outside the plugin directory.
- Use Check for updates in the plugin row to refresh immediately. Scheduled WordPress checks also discover new versions; update installation is not forced automatically.
- Requires WordPress 5.8+ and PHP 7.4+. The hosting server must reach api.github.com and GitHub release downloads over HTTPS. No GitHub token is needed.
- Download the attached `printify-delivery-status.zip` release asset, not GitHub's automatically generated source ZIP.

## Validation before production
Test on staging with unique matches, returning customers with duplicate phone/name, US and international phones, split orders, manual relinking, stale data, Google permission failures, reordered sheet columns, and HPOS/legacy lists. Configure a real server cron if WP-Cron does not run reliably.

## Limits
This release scans WooCommerce orders in pages of 100 during sync. Large stores may require batch jobs and a dedicated indexed matching table. Status filter, bulk manual linking, and automated source completeness checks are not included. Runtime validation on a WordPress staging site is required.
