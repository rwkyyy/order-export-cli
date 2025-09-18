# Simple Order Export for WooCommerce via WP CLI

Compatibility: Works with both WooCommerce HPOS (High-Performance Order Storage) and legacy postmeta-based order storage.

Execution: WP-CLI only

## 🔍 Description

This WordPress plugin is built for WooCommerce-powered stores and enables WP-CLI-based exports of marketing-relevant customer data—specifically phone numbers and email addresses—from completed orders only.

It is designed to be simple, performant, and secure, creating CSV exports directly into the plugin’s /exports directory. No admin UI, no bloat—just a clean CLI tool for exporting data for marketing campaigns and CRM sync.

It can be easily extended to have multiple fields (if many more are needed).

## 🎯 Purpose

The primary goal of this plugin is to:
- 	Give store admins a quick and reliable way to extract customer contact data.
- 	Work efficiently across small or large datasets without browser timeout concerns.

## ✅ Features
- 	🔁 Works with both HPOS and non-HPOS WooCommerce setups.
- 	📦 Exports only completed orders (order status: completed).
- 	📁 Writes to CSV with only phone and email fields.
- 	🔒 Saves output in a private plugin folder (/exports).
- 	🧪 Adds a CLI progress bar for large exports.
- 	🛠️ CLI-only execution for maximum performance and automation potential.


## 🖥️ Usage

To run the export, use:

` wp simple-marketing export `

After running the command, a file like:

`marketing-export-abc12345-2025-09-18-00-00-00.csv`

will be created in:

`/wp-content/plugins/simple-marketing-export/exports/`

🚫 Limitations / Requirements: 
- Requires WooCommerce to be installed and active.
- Must be run via WP-CLI (no admin interface).
- Only exports from orders with completed status.

🔐 Security Tips:

To protect exported files:
- Make sure the /exports/ directory is not web-accessible (e.g., add index.php).
- Consider .htaccess (Apache) or equivalent nginx rule if your server config allows it.
- or just delete the files after you're done :)
