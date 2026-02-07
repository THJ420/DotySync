# DotySync for WooCommerce

[![WordPress Plugin](https://img.shields.io/badge/WordPress-6.0+-blue.svg)](https://wordpress.org/plugins/dotysync-for-woocommerce/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-Compatible-orange.svg)](https://woocommerce.com/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-lightgrey.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**DotySync for WooCommerce** is a powerful, enterprise-grade integration tool designed to seamlessly synchronize your product inventory between [Dotypos POS (API V2)](https://www.dotykacka.cz/) and your WooCommerce online store. 

Stop wasting time with manual updates. DotySync ensures your stock levels, prices, and product information are always in perfect harmony, reducing the risk of overselling and improving customer satisfaction.

## 🚀 Key Features

- **🔄 Automatic Real-Time Synchronization:** Support for Webhooks allows for instant updates whenever a product is changed in your Dotypos cloud.
- **🕒 Configurable Sync Intervals:** Choose how often your background sync runs (e.g., every 1, 12, or 24 hours).
- **📂 Smart Category Mapping:** Automatically organizes your products into WooCommerce categories, with a "Recently Stocked" parent category for new items.
- **🛡️ Secure Authentication:** Sensitive API credentials (Client Secret and Refresh Token) are protected with industry-standard **AES-256-CBC encryption**.
- **⚙️ Configurable Product Status:** Decide whether newly synced or updated products should be set to "Draft" or "Published" automatically.
- **🛠️ Built-in Debugging & Manual Sync:** One-click manual sync with real-time logging and a debug tool to verify API connectivity.
- **📦 Reliable Batch Processing:** Handles large product catalogs efficiently using paginated AJAX requests.

## 📋 Prerequisites

To use this plugin, you will need:
- A [Dotypos Cloud](https://www.dotykacka.cz/) account.
- **Client ID** and **Client Secret** obtained from your Dotypos cloud settings.
- WooCommerce installed and active on your WordPress site.

## 🔧 Installation

1.  **Upload:** Upload the `dotysync-for-woocommerce` folder to your `/wp-content/plugins/` directory.
2.  **Activate:** Log in to your WordPress admin dashboard and activate the plugin via the 'Plugins' menu.
3.  **Navigate:** Go to **WooCommerce > DotySync** to begin configuration.

## ⚙️ Configuration

### 1. Connect to Dotypos
- Enter your **Client ID (Cloud ID)** and **Client Secret**.
- Click **Save Changes**.
- Click the **"Connect with DotySync"** button to authorize the plugin and securely retrieve your Refresh Token.

### 2. Real-Time Sync (Webhooks)
To enable instant updates:
- Navigate to the **Real-Time Sync** tab.
- Copy your unique **Webhook Endpoint URL**.
- Paste this URL into your Dotypos Cloud Webhook settings.
- Enable the **Webhook Listener** in the plugin settings.

### 3. Sync Style & Logic
Customize how your products are handled:
- Set your preferred **Sync Interval**.
- Choose default statuses for **New** and **Updated** products.

## 🛠️ Developer & Manual Tools
The plugin includes a **Manual Sync** button with a real-time log viewer. If you encounter issues, use the **Debug: Fetch 1 Product** tool to verify that your API connection is retrieving data correctly.

## 🔒 Security
We take security seriously. All sensitive authentication tokens are encrypted before being stored in your database, ensuring that your POS data remains private and secure.

## 🤝 Contribution & Support
Contributions are welcome! If you find a bug or have a feature request, please open an issue or submit a pull request on GitHub.

- **Author:** Tamim Hasan
- **License:** GPLv2 or later

---
*Optimized for SEO: WooCommerce Dotypos Sync, Dotypos POS Integration, Inventory Synchronization, Real-time Stock Updates, Dotypos API V2 WordPress.*
