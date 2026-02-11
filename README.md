# G-Ads Circuit Breaker (AdFuse)

**An automated "Circuit Breaker" for Google Ads campaigns to prevent budget drainage from invalid traffic or click farms.**

## 🚀 Overview

**G-Ads Circuit Breaker** is a protective mechanism that sits between your WordPress landing pages and your Google Ads account. It functions as a **Workaround** to invalidate invalid clicks *before* Google's detection algorithms kick in (which can take hours or days).

**How it works:**
1.  **Monitor:** The WordPress plugin tracks incoming traffic containing specific Google Ads URL parameters.
2.  **Detect:** If the number of clicks exceeds a defined **Threshold** within a specific **Time Window** (e.g., 50 clicks in 1 minute), the Circuit Breaker trips.
3.  **Act:** The system automatically **PAUSES** the specific campaign via the Google Ads API to stop budget loss immediately.
4.  **Alert:** An instant notification is sent to Telegram via a secure Relay.
5.  **Reset:** After a configurable cooldown period (e.g., 60 minutes), the system automatically **ENABLES** the campaign again.

---

## 🏗 Architecture

The system consists of three decoupled components for security and stability:

1.  **The Sensor (WordPress Plugin):** Installed on the landing page. Counts visits and holds the logic for triggering the alarm.
2.  **The Relay (PHP Middleware):** Hosted on an external or internal server. Acts as a bridge and a spam filter for notifications. It prevents the WordPress site from having direct access to the Google Ads API keys.
3.  **The Engine (Python API):** A Flask-based service running permanently. It holds the Google Ads API credentials and executes the `PAUSE` or `ENABLE` commands.

---

## 🧩 Flexibility & Customization

This system is designed to be modular. You can use the components based on your specific needs:

### 1. Optional Telegram Notifications
The Telegram notification system is entirely optional. If you do not require alerts:
*   Simply leave the `$bot_token` and `$chat_id` variables empty in the `relay.php` file.
*   The system will continue to protect your budget by pausing campaigns, but it will do so silently.

### 2. Monitoring-Only Mode (Disable Relay)
If you want to use the plugin strictly for **counting clicks** and detecting attacks without triggering any automated actions (pausing ads) on Google's side:
*   In the WordPress Plugin Settings, leave the **Relay URL** field **empty**.
*   The plugin will still count visits, enforce thresholds, and fire developer hooks (`wpvt_gads_threshold_reached`), but it will **not** attempt to contact the API or pause your campaigns.
*   *Advanced Users:* With minor code modifications in the plugin, you can also bypass the PHP Relay entirely and connect directly to the Python API if your security architecture allows it.

---

## 📋 Prerequisites

Before setting this up, ensure you have the following:

1.  **Google Ads API Access:** You must have a Google Ads Manager Account (MCC) and a Developer Token.
    *   *Note: This project assumes you already have a valid `google-ads.yaml` configuration file. If you are new to Google Ads API, please refer to the [Official Google Ads API Quickstart](https://developers.google.com/google-ads/api/docs/first-call/overview).*
2.  **Python 3.8+:** To run the API engine.
3.  **PHP 7.4+:** For the WordPress plugin and the Relay script.
4.  **Telegram Bot (Optional):** Required only if you want notifications.

---

## 🛠 Installation & Setup

### Step 1: The Engine (Python API)
*This component communicates directly with Google.*

1.  Navigate to the `/api` directory.
2.  Install dependencies:
```bash
pip install flask google-ads

