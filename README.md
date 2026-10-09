# Printify Delivery Status

WooCommerce plugin displaying delivery statuses from a configured Google Sheet in the order list. Supports legacy order storage and HPOS. Reads Google Sheets; does not modify source rows or WooCommerce order status.

Current source: **1.0.7** ignores canceled source orders before matching, including stale tracking on a canceled order. Previously saved canceled tracking and links are removed during a successful sync, while active replacement orders are retained. Immediate Save/Sync, automatic split shipments, clickable status-only badges and the PHP 8 initialization fix are preserved. Download the published installation ZIP from Releases below.

## Installation and updates

1. Download **printify-delivery-status.zip** from [Releases](https://github.com/anhsabmt/printify-delivery-status/releases/latest). Do not use the automatic GitHub source-code ZIP.
2. WordPress → Plugins → Add New → Upload Plugin. If upgrading from 1.0.0–1.0.2, choose **Replace current with uploaded** once.
3. Existing saved settings and order links are preserved. New installations: enter your spreadsheet ID and use tab `printify_trackorder_sync` in WooCommerce → Printify Delivery.
4. Keep the service account JSON outside the webroot and outside the plugin directory. Configure its server path in the plugin settings. Never upload that JSON to this repository.
5. From 1.0.3 onward, use **Update now** in WordPress for future stable releases. **Check for updates** in the plugin row refreshes the cached GitHub check.

WordPress 5.8+, PHP 7.4+, WooCommerce and outbound HTTPS to Google/GitHub are required. Hourly background sync uses Action Scheduler, falling back to WP-Cron. Manual sync runs within the button request and reports the result. If a sync is already running, the plugin keeps the newest refresh request and retries on the next authorized admin page load.

## Publish the next version

1. Edit only plugin source files; update the plugin header and `PDS_Updater::VERSION` to the same version. Update release documentation.
2. Run `powershell -ExecutionPolicy Bypass -File scripts/build-release.ps1 -Version 1.0.7` (replace with the actual version). This runs PHP validation and builds only allowlisted plugin files.
3. Commit reviewed plugin changes to this repository, then create/push tag `v1.0.7` at that commit. The **Plugin release** workflow tests on PHP 8.3, packages and publishes `printify-delivery-status.zip` automatically. An existing tag can also be released using **Actions → Plugin release → Run workflow**.
4. Verify the attached ZIP is present in Releases. WordPress discovers it on its normal update checks, or immediately using **Check for updates**.

Keep minimum WordPress/PHP requirements in the updater and plugin header in sync when changing them. Historical ZIPs are in `releases/`; older versions do not contain the GitHub updater. The public repository contains no service account keys, exported sheet rows or customer data.

## Validation scope

Automated checks cover canceled orders and cleanup, the WordPress init-hook regression with Action Scheduler and WP-Cron, matching rules, refund exclusion, split shipments, immediate sync, pending-job cleanup, concurrent refresh requests, clickable badge rendering, release parsing, safe download URLs, API failures, caching and plugin metadata. A staging WordPress installation is still needed to validate actual update installation, HPOS/legacy screens and Google synchronization on your hosting environment. See [plugin documentation](printify-delivery-status/README.md) for matching behavior and operational limits.
