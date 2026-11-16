# SokoHub M-Pesa Integration & Refunds Setup Guide

> **Developer:** Abel  
> **Assigned Features:** M-Pesa Daraja Integration & Order Cancellation with Stock Restoration / Refunds  
> **Git Feature Branch:** `feature/mpesa-refunds-stock-restore`  
> **Repository:** [https://github.com/webers-grp/Project-SokoHub](https://github.com/webers-grp/Project-SokoHub)

---

## 1. Overview of Delivered Features

### 1.1 M-Pesa Daraja API Integration (Lipa na M-Pesa Online / STK Push)
- **Automatic Phone Normalization:** Accepts phone numbers in any Kenyan format (`0712345678`, `0112345678`, `+254...`, `254...`) and standardizes them into Daraja's required format (`2547XXXXXXXX`).
- **OAuth 2.0 Bearer Token Management:** Implements automated token acquisition from Safaricom with secure local file caching to minimize latency.
- **STK Push Dispatch:** Sends automated Lipa na M-Pesa prompts directly to the customer's phone during checkout or via a 1-click button on unpaid orders.
- **Real-Time Payment Status Polling:** Client-side AJAX polling checks `pages/api/mpesa_status.php` every 2.5 seconds to detect PIN entry instantly.
- **Daraja STK Query Fallback:** If webhook callbacks are delayed, the status checker automatically queries Safaricom's query endpoint (`/stkpushquery/v1/query`) directly.
- **Webhook Callback Handler:** `pages/api/mpesa_callback.php` parses Safaricom notifications, saves M-Pesa receipt numbers, marks orders as `paid`, and prevents duplicate callback replay using SHA-256 deduplication.
- **Presentation Simulation Mode:** A built-in developer simulation button allows complete end-to-end demonstrations on localhost without needing active Safaricom network tunnels.

### 1.2 Order Cancellations & Atomic Stock Restoration
- **Permissions:**
  - **Buyers:** Can cancel their own orders while in `pending` or `confirmed` status (before dispatch).
  - **Sellers:** Can cancel unfulfilled orders containing their products.
  - **Admins:** Can cancel any unfulfilled order.
- **Atomic Stock Restoration:** Within a single database transaction (`FOR UPDATE` locking), every item in the cancelled order is immediately credited back to vendor inventory (`stock_qty = stock_qty + quantity`), updating the `stock_movements` ledger.
- **Automatic Refund Request:** If an order was already paid via M-Pesa, cancelling it automatically generates a refund request record in `refunds` with status `requested`.
- **Refund Processing:** Sellers and Admins can approve or complete refunds directly in the dashboard, storing the M-Pesa reversal transaction reference and updating order payment status to `refunded`.

---

## 2. How to Obtain Your Safaricom Daraja API Keys

To obtain your own live or sandbox M-Pesa API credentials:

1. **Create a Developer Account:**
   - Go to the [Safaricom Developer Portal](https://developer.safaricom.co.ke).
   - Sign up or log in.

2. **Create a New App:**
   - In your dashboard, click **My Apps** -> **Create New App**.
   - Enter an App Name (e.g. `SokoHub-Marketplace`).
   - Check the **Lipa na M-Pesa Sandbox** checkbox.
   - Click **Create App**.

3. **Copy Your Credentials:**
   - Click on your new app to reveal:
     - **Consumer Key** (a long alphanumeric string)
     - **Consumer Secret** (a long alphanumeric string)

4. **Default Sandbox Test ShortCode & Passkey:**
   - In Safaricom's Sandbox environment, use Safaricom's official public test Paybill and Passkey:
     - **Business ShortCode:** `174379`
     - **Passkey:** `bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919`

---

## 3. How to Configure Your M-Pesa API Keys in SokoHub

### Option A: Local File Configuration (Recommended for Local Dev)

1. Open your terminal or file manager and navigate to the `config/` directory.
2. Copy the template file `mpesa.local.php.example` to `mpesa.local.php`:
   ```bash
   cp config/mpesa.local.php.example config/mpesa.local.php
   ```
3. Open `config/mpesa.local.php` in your editor and enter your credentials:
   ```php
   <?php
   return [
       'env'             => 'sandbox', // 'sandbox' or 'live'
       'consumer_key'    => 'PASTE_YOUR_CONSUMER_KEY_HERE',
       'consumer_secret' => 'PASTE_YOUR_CONSUMER_SECRET_HERE',
       'shortcode'       => '174379',
       'passkey'         => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
       'callback_url'    => '', // Leave blank to auto-detect, or set your ngrok URL
       'allow_simulation'=> true,
   ];
   ```
   *(Note: `config/mpesa.local.php` is listed in `.gitignore` so your private API keys will never be committed to Git).*

### Option B: Environment Variables (For Docker, Render, or Production)

You can also set environment variables in your server environment:
- `MPESA_ENV`: `sandbox` or `live`
- `MPESA_CONSUMER_KEY`: Your Daraja Consumer Key
- `MPESA_CONSUMER_SECRET`: Your Daraja Consumer Secret
- `MPESA_SHORTCODE`: `174379` (or your production Paybill/Till)
- `MPESA_PASSKEY`: Your Daraja online passkey
- `MPESA_CALLBACK_URL`: `https://yourdomain.com/pages/api/mpesa_callback.php`

---

## 4. Testing Webhook Callbacks on Localhost (Using ngrok)

Because Safaricom's servers cannot reach a private IP like `http://localhost/`, use an HTTP tunnel like [ngrok](https://ngrok.com) when you want real callbacks delivered to your local machine:

1. Download and start ngrok on port 80 (or your Apache port):
   ```bash
   ngrok http 80
   ```
2. Copy the forwarding HTTPS URL provided by ngrok, e.g.:
   `https://abc1-234-56-78.ngrok-free.app`
3. Update `callback_url` in `config/mpesa.local.php`:
   ```php
   'callback_url' => 'https://abc1-234-56-78.ngrok-free.app/sokohub/pages/api/mpesa_callback.php',
   ```
4. Now, when Safaricom processes a sandbox STK push, the callback will arrive directly at your local `pages/api/mpesa_callback.php`.

---

## 5. Offline Presentation / Demo Mode

When presenting in class or demonstrating locally without active internet or ngrok:

1. Place an order on `pages/buyer/checkout.php` using **M-Pesa Mobile Money**.
2. An interactive green **Lipa na M-Pesa** waiting modal appears with a live countdown and polling indicator.
3. Click the yellow **⚡ Simulate PIN Approval (Demo)** button.
4. The system immediately simulates customer PIN confirmation, marks the order as **Paid**, generates a simulated M-Pesa receipt (e.g. `SKH7F9A2B`), and updates the UI in real time!

---

## 6. How Cancellation and Stock Restoration Works

1. Open **My Orders** (`pages/buyer/orders.php`).
2. Click **Cancel Order** on any pending order.
3. A modal opens showing:
   - Notice of exact item count that will be returned to stock.
   - If the order was paid via M-Pesa, a notice stating that a refund request will be opened.
   - Reason dropdown (e.g., "Changed my mind", "Found better price", etc.) and optional notes.
4. When confirmed:
   - The order status changes to `cancelled`.
   - The stock for each item in the order is automatically incremented in `products.stock_qty`.
   - An entry is logged in `stock_movements`.
   - If paid, a row is inserted in `refunds` with status `requested`.
5. Sellers and Administrators can click **Process Refund** to approve the refund and enter an M-Pesa reversal code, updating the order to `refunded`.

---

## 7. Database Migration

For existing databases, import `database/migrations/002_mpesa_and_refunds.sql` in phpMyAdmin. Note that SokoHub also auto-creates all necessary tables (`payments`, `mpesa_callbacks`, `refunds`, `stock_movements`, `order_status_history`) automatically via `ensure_order_schema_tables()`.

---

## 8. Summary of Created & Updated Files

| File | Purpose |
| :--- | :--- |
| `config/mpesa.php` | M-Pesa configuration loader, URL routing & credential resolver |
| `config/mpesa.local.php.example` | Template for Abel to plug in his Daraja Consumer Key & Secret |
| `includes/mpesa.php` | Daraja OAuth, STK Push, STK query status, callback webhook & simulator |
| `includes/orders.php` | Atomic order cancellation with stock restore, refund processor & auto-schema |
| `pages/api/mpesa_callback.php` | Public webhook receiver for Safaricom Daraja STK Push responses |
| `pages/api/mpesa_status.php` | AJAX polling endpoint for live STK Push verification |
| `pages/api/mpesa_simulate.php` | Local development helper to simulate PIN approval |
| `pages/buyer/checkout.php` | M-Pesa phone number validation, STK push initiation & redirection |
| `pages/buyer/orders.php` | Cancellation modal, stock restore tracking, refund actions & STK waiting modal |
| `database/schema.sql` | Updated 14-table schema with payments, callbacks, refunds, and ledgers |
| `database/migrations/002_mpesa_and_refunds.sql` | Standalone SQL migration script for existing installations |
| `tests/run_tests.php` | Automated test suite with test cases TC-08 through TC-12 |
| `MPESA_SETUP.md` | Complete setup & Daraja key instructions for Abel |
