=== SD WooCommerce REST API modifier v 0.4===
Requires at least: 5.5
Tested up to: 6.0.2	
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==
Woocommerce REST API Modification and filter to import orders in Tally Software.

== v 0.1 ==
Woocommerce own REST API modified for single order

== v 0.2 ==
Built a custom API to list orders as per the Tally importable format.

== v 0.3 ==
Into custome API structure
- Added multiple combination of order filters on API
- Now Orders can be fetched using customer name, order status, order dates, tally company, and any meta key value pair
- API Info button added to to get overview of how to use the APIs

== v 0.4 ==
- Removed the Custome API structure for orders
- Admin Orders - Custom Meta box added for some new meta fields
- Woocommerce own REST API for orders, added two new custom fileds regarding sales agent (id & name)
- Woocommerce Order REST API added filter based on meta values - so that the selected orders can only be found to import.
- Custom WC endpoint build - to updated orders by marking them as they are imported into Tally.

== v 1.0 ==
Add a new wc v3 route - sd_orders
The new REST API is route is developed for the tally guys who needed a restructured orders list in REST API

== v 1.1 ==
Route - sd_orders
Introduced some new request params to fetch orders within two date ranges & customer name

== v 3 ==
Tally import sales log - fixed

== v 3.1 ==
New Route - sd_create_pr_logs
This is POST REST API to create Payment Receipt log in Admin Order page as well as update the timestamp into ACF repeater field.

== v 3.2 ==
Tally import sales log creation - restriction when identical data will come via API

== v 3.3 ==
Order api added new key "import_type" which will specify tally users what they need to import 

== v 3.4 ==
Payment receipts bugs fixed
Payment receipts - only true data will be appeared on API
