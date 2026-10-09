# Printify Delivery Status 1.0.5

1.0.5 makes Save and Sync now run immediately, automatically groups multiple source shipments matching one WooCommerce order, and shows only status badges. Clicking a badge opens that shipment's TrackOrder URL in a new tab. The refund-record fix from 1.0.4 is retained: only actual shop orders are indexed and linked; refunded parent orders remain eligible.

Default source tab: `printify_trackorder_sync`. On upgrade, the previous default `printify_multi_orders` is migrated once; other custom tab names are preserved.

## Installation
1. Upload the plugin ZIP in WordPress → Plugins → Add New → Upload Plugin, then activate.
2. Put the service account JSON outside the public web directory on the WordPress server. Do not include it in the ZIP. The Windows path on your computer is not the path on your hosting server.
3. Open WooCommerce → Printify Delivery and enter the absolute server path to the JSON.
4. Enter your spreadsheet ID and verify the tab, then click **Save & sync now**. This saves the configuration and completes the sync in the same request. New installations have no default spreadsheet ID; existing saved settings are preserved.
5. The page shows the result and last attempt time with seconds. Multiple sources uniquely matching one WooCommerce order are grouped automatically. Review only unmatched, ambiguous or conflicting sources.

## Behavior
- Hourly background sync using Action Scheduler, with WP-Cron fallback.
- Save and Sync now cancel this plugin's pending sync jobs, restore the next hourly run, and sync directly. Other plugins' jobs and active sync locks are preserved.
- If a sync is already active, the newest refresh request is retained. The settings page refreshes automatically and runs it as soon as the lock is released; no manual task cleanup is needed. Other authorized admin GET requests also resume it. Locks older than 15 minutes are recovered.
- After a plugin version update, configured installations refresh on the next admin GET request by a user who can manage WooCommerce. Saved settings are used without clicking Sync now again.
- Shipping recipient name plus shipping phone (billing phone fallback), filtered by available Address 1, Postcode, Country Code and nonempty source Address 2.
- Street abbreviations and postcode spaces/hyphens are normalized; house and apartment numbers are preserved. Missing source address fields do not block name/phone fallback. A populated source field conflicting with WooCommerce rejects that candidate.
- Existing automatic links with newly conflicting addresses stop updating and appear in the review results. Confirmed manual links override matching. Older links created manually before 1.0.2 should be re-saved to mark them as manual overrides.
- Case and whitespace normalization; US/CA leading 1 normalization. No fuzzy name matching.
- A source matching multiple WooCommerce orders remains ambiguous and is not automatically assigned. Multiple distinct sources uniquely matching the same WooCommerce order are linked automatically, each retaining its own tracking and status.
- Existing links persist by Shop ID + Printify Order ID, including when rows are sorted.
- No match is displayed until linked. Each linked source has its own status badge and TrackOrder link, even when statuses are identical. A missing or invalid URL leaves a plain badge.
- The delivery column and order panel display status badges only. Tracking numbers, carrier names, checked times and supplementary labels are hidden; tracking data remains stored internally. The existing shipment-tracking plugin is unaffected.
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
Test on staging with unique matches, returning customers with duplicate phone/name, US and international phones, split orders with distinct clickable badges, immediate saves, a save during active sync, automatic refresh after update, manual relinking, Google permission failures, reordered sheet columns, and HPOS/legacy lists. Configure a real server cron if WP-Cron does not run reliably.

## Limits
This release scans WooCommerce orders in pages of 100 during sync. Manual sync waits for the Google requests and scan to complete, so large stores can still encounter hosting request timeouts and may require batch jobs and a dedicated indexed matching table. Status filter, bulk manual linking, and automated source completeness checks are not included. Runtime validation on a WordPress staging site is required.
