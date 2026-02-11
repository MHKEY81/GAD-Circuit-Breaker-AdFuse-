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
```
3.  Place your `google-ads.yaml` file in the same directory.
4.  Edit api.py and set a strong API_KEY (this key authorizes the Relay to talk to this API).
5.  Run the service (recommended to run via systemd or screen for persistence):

```bash
python api.py
```
*Runs on port 5000 by default.*
Step 2: The Relay (PHP Middleware)
This component sits between WordPress and the Python API.

1.  Upload relay.php to your server (can be the same server as WP or a different one).
2.  Edit the file to configure:
RELAY_KEY: A secret key shared between the WordPress Plugin and this Relay.
`$ads_api_key`: The key you defined in Step 1 (Python API).
`$ads_base_url`: The IP/URL where api.py is running (e.g., http://YOUR_SERVER_IP:5000).
(Optional) Telegram bot_token and chat_id.


### Step 3: The Sensor (WordPress Plugin)
This component tracks the clicks.

Zip the wp-gads-circuit-breaker folder and install it as a standard WordPress plugin.
Activate the plugin.
Go to Settings > Anti Attack GAds.
Fill in the configuration:
Google Ads Customer ID: Your account ID (e.g., 1234567890).
Relay URL: The full URL to your relay.php file (Required for auto-pause).
Security Key: Must match RELAY_KEY from Step 2.
URL Parameter: The parameter used for tracking (e.g., gad_campaignid).


### ⚙️ Configuration Guide
Defining Campaigns
In the WordPress plugin settings, define your campaigns in the text area using the following format (one per line):

unique_slug | Campaign Title | ID_1,ID_2 | Threshold (Optional)

*   **unique_slug:** An internal identifier for logs.
*   **Campaign Title:** Human-readable name for alerts.
*   **ID_1, ID_2:** The Campaign IDs or AdGroup IDs passed in the URL parameter.
*   **Threshold:** (Optional) Overrides the global click threshold for this specific campaign.

**Examples:**

text
# Use global threshold (e.g., 50 clicks)
samsung_fridge | Samsung Fridge Sale | 23071363905,23071364000

# Use custom threshold (e.g., 20 clicks)
iphone_repair | iPhone Repair Service | 8844112233 | 20

### URL Parameter
Your Google Ads Final URL suffix should include the ID you mapped above.
Example: `https://yoursite.com/landing-page/?gad_campaignid=23071363905`

---

## 🔒 Security Best Practices

1.  **Protect `api.py`:** Use a firewall (UFW/IPTables) to restrict access to port 5000. Only allow the IP address of the server hosting `relay.php`.
2.  **HTTPS:** Ideally, serve `relay.php` over HTTPS.
3.  **Secrets:** Never commit `google-ads.yaml` or files containing real API keys to public repositories.

---

## ⚠️ Disclaimer

This tool is a **mitigation strategy** and does not guarantee 100% protection against invalid traffic. It is designed to minimize budget loss by reacting faster than standard reporting tools. Use at your own risk. The authors are not responsible for any campaign suspensions or lost revenue.
