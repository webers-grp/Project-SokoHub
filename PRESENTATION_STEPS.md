# SokoHub Local Presentation Guide (XAMPP)

This folder (`sokohub_presentation/`) contains a standalone, zero-broken-link presentation version of **SokoHub** demonstrating **HTML, CSS, JavaScript, SQL, and PHP**.

---

## Step 1: Copy Into XAMPP `htdocs`

Copy this entire `sokohub_presentation` folder into your XAMPP `htdocs` directory:
- **Windows:** `C:\xampp\htdocs\sokohub_presentation\`
- **Linux:** `/opt/lampp/htdocs/sokohub_presentation/`
- **macOS:** `/Applications/XAMPP/htdocs/sokohub_presentation/`

---

## Step 2: Start Apache & MySQL

1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

---

## Step 3: Create & Import the SQL Database

1. Open your browser and go to `http://localhost/phpmyadmin`.
2. Click **New** (left sidebar), enter database name **`sokohub_db`**, select collation **`utf8mb4_unicode_ci`**, and click **Create**.
3. Click **`sokohub_db`**, open the **Import** tab, and import these two files in order:
   1. `database/schema.sql` — creates the 9 MySQL tables.
   2. `database/seed.sql` — inserts demo accounts, categories, and 10 products.

*(Default XAMPP settings — host `127.0.0.1`, port `3306`, user `root`, blank password — work automatically via `config/config.php`. If your MySQL has a password, copy `config/db.local.php.example` to `config/db.local.php` and update `host => '127.0.0.1'`, `dbname => 'sokohub_db'`, `user => 'root'`, `pass => 'your_password'`.)*

---

## Step 4: Verify Database Connection

Open:  
`http://localhost/sokohub_presentation/health.php`

Confirm you see:
- **PHP Runtime:** Active
- **Session Engine:** `session_start() OK`
- **MySQL / MariaDB (PDO):** `Connected`
- **Database Tables:** `9 / 9 Schema Ready`

---

## Step 5: Live Presentation Demo Flow

1. **HTML & Responsive CSS (`http://localhost/sokohub_presentation/`):**  
   Walk through the storefront hero banner, live database metrics, category chips, and featured deals. Resize the browser window to `<= 960px` to show the responsive layout and mobile hamburger menu.
2. **SQL Catalog Querying (`pages/public/products.php` & `product_details.php`):**  
   Search by keyword, filter by category (`Gas`, `Food`, `Electronics`, `Clothes`) and condition (`New` / `Second Hand`), sort by price, and open a product detail page.
3. **Client-Side JS + Server-Side PHP Validation (`pages/public/contact.php`):**  
   Submit an empty form to show inline JavaScript validation errors from `assets/js/app.js`, then submit a valid inquiry to persist it into MySQL (`contact_messages`).
4. **Role Registration (`pages/auth/register.php`):**  
   Show the 3 dedicated role forms (`register_buyer.php`, `register_seller.php`, `register_delivery.php`) using `password_hash()`.
5. **PHP Login, Sessions & Cookies (`pages/auth/login.php`):**  
   Click the **Seller** demo button (`seller@sokohub.co.ke` / `SokoHub@2026`), tick **Remember my email**, and sign in (`password_verify()` + `session_start()`).
6. **Full Database CRUD (`pages/dashboard/manage_products.php`):**  
   - **Create** a new product (e.g., choosing the `Wi-Fi Router & Networking` image or uploading a photo).
   - **Read** it in the seller table and public catalog (`products.php`).
   - **Update** its price or stock via **Edit**.
   - **Delete** the test product.
7. **Logout & Cookie Verification (`pages/auth/logout.php`):**  
   Click **Logout** to destroy the session cleanly and show the email still pre-filled by the Remember-Me cookie on `login.php`.
