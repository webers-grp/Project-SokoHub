# SokoHub Project — File Structure & Function Reference

This document provides a comprehensive map of the **SokoHub** project directory and details the specific purpose, functionality, and architectural responsibilities of each file.

---

## 1. High-Level Directory Tree

```text
sokohub/
├── .htaccess                       # Apache rewrite rules, security guards & directory protection
├── health.php                      # Environment diagnostic & database health probe
├── index.php                       # Primary marketplace storefront & landing page
├── PRESENTATION_STEPS.md           # Step-by-step XAMPP local presentation & evaluation guide
├── README.md                       # Project overview, installation instructions & demo credentials
├── FILE_STRUCTURE.md               # Detailed directory tree & file functional reference
│
├── config/                         # Environment & Database Configuration
│   ├── config.php                  # Global constants, dynamic URL helper & DB config resolver
│   ├── db.php                      # PDO MySQL connection manager & connectivity test probe
│   └── db.local.php.example        # Credentials template for custom/local hosting (InfinityFree, XAMPP)
│
├── database/                       # Relational Database SQL Scripts
│   ├── schema.sql                  # 9-table 3NF MySQL/MariaDB InnoDB schema with constraints
│   └── seed.sql                    # Demo data: 4-role users, categories, 10 products, orders & deliveries
│
├── includes/                       # Core PHP Helpers, Security & Shared Layouts
│   ├── auth.php                    # Session handling, password hashing, cookies & RBAC guards
│   ├── functions.php               # Data validation, CSRF tokens, flash messages, image uploads & helpers
│   ├── header.php                  # Global <head>, responsive navigation bar & flash alert renderer
│   └── footer.php                  # Global footer, visit-tracking cookie notice & script loader
│
├── pages/                          # Feature-Grouped Application Pages
│   ├── auth/                       # User Authentication & Role Registration
│   │   ├── login.php               # Sign-in portal with CSRF, Remember-Me cookie & demo credentials
│   │   ├── logout.php              # Session termination & cookie invalidation handler
│   │   ├── register.php            # Role selector hub (Buyer, Seller, Delivery)
│   │   ├── register_buyer.php      # Customer/Buyer registration endpoint
│   │   ├── register_delivery.php   # Delivery rider registration with delivery_profiles creation
│   │   └── register_seller.php     # Vendor registration with seller_profiles creation
│   │
│   ├── dashboard/                  # Authenticated Member Portals
│   │   ├── dashboard.php           # Role-aware portal (KPI metrics, orders, revenue & active tasks)
│   │   └── manage_products.php     # Full CRUD inventory manager for Sellers & Administrators
│   │
│   └── public/                     # General Storefront & Visitor Pages
│       ├── about.php               # Company mission, local impact & live marketplace statistics
│       ├── contact.php             # Customer support inquiry form with dual-layer validation
│       ├── product_details.php     # Single product presentation, discount tags & seller profile
│       └── products.php            # Dynamic product catalog with search, filter, sort & pagination
│
└── assets/                         # Static Front-End Assets
    ├── css/
    │   └── style.css               # Complete responsive CSS styling, CSS variables & media queries
    │
    ├── js/
    │   └── app.js                  # Mobile nav drawer, inline form validation & HTML5 canvas charts
    │
    └── img/                        # Brand & preset marketplace imagery
        ├── about-stall.jpg         # Visual for the About Us vendor mission section
        ├── cart-icon.jpg           # Shopping cart icon and fallback product placeholder
        ├── clothes.jpg             # Preset imagery for apparel and fashion listings
        ├── delivery-rider.jpg      # Preset imagery for courier and dispatch services
        ├── electronics.jpg         # Preset imagery for appliances and electronic gadgets
        ├── fresh-produce.jpg       # Preset imagery for farm-fresh agricultural produce
        ├── gas-mascot.jpg          # Preset imagery for LPG gas accessories & safety kits
        ├── gas-tanks.jpg           # Preset imagery for cooking gas cylinder refills
        ├── hero-market.jpg         # Hero banner imagery representing the Kenyan local market
        ├── indoor-market.jpg       # Preset imagery for artisan gifts and indoor market goods
        ├── login-bg.jpg            # Authentication hero visual for login and registration pages
        ├── organic-food.jpg        # Preset imagery for organic groceries and pantry baskets
        ├── router.jpeg             # Preset imagery for Wi-Fi routers and networking equipment
        ├── sokohub-logo.jpg        # Official SokoHub brand identity logo
        └── uploads/                # Writable destination directory for user-uploaded product images
            └── .gitkeep            # Version control placeholder keeping uploads directory tracked
```

---

## 2. Root Files

### [`.htaccess`](file:///C:/Users/abelk/Projects/sokohub/sokohub/.htaccess)
- **Primary Function:** Apache web server security configuration.
- **Key Responsibilities:**
  - Prevents directory indexing (`Options -Indexes`) so visitors cannot browse raw server folders.
  - Denies direct HTTP web access to sensitive file types (`.sql`, `.md`, `.log`, `.example`) via `<FilesMatch>`.
  - Employs `mod_rewrite` rewrite rules to return HTTP 403 Forbidden on direct access attempts to internal system directories (`config/`, `database/`, `includes/`, `tests/`, `docs/`).

### [`health.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/health.php)
- **Primary Function:** System diagnostics and deployment health verification.
- **Key Responsibilities:**
  - Tests the server PHP runtime version against requirements.
  - Verifies PHP session initialization (`session_start()`) and cookie support.
  - Probes live MySQL/MariaDB database connectivity using PDO via `test_db_connection()`.
  - Inspects the active database schema to count detected tables (expecting all 9 tables from `schema.sql`) and lists their names visually as status badges.

### [`index.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/index.php)
- **Primary Function:** Primary public storefront and homepage.
- **Key Responsibilities:**
  - Initializes user session and records the visitor's last-visit timestamp in an HTTP cookie (`track_visit_cookie()`).
  - Fetches product categories for the category shortcut navigation strip.
  - Queries active, in-stock featured products joined with category names and seller profile names.
  - Pulls live marketplace pulse metrics (active listings count, registered sellers, active delivery riders, and total processed orders).
  - Renders the hero banner, call-to-action buttons, value propositions, and featured product grid.

### [`PRESENTATION_STEPS.md`](file:///C:/Users/abelk/Projects/sokohub/sokohub/PRESENTATION_STEPS.md)
- **Primary Function:** Quick-reference demo guide for academic, classroom, or stakeholder presentations.
- **Key Responsibilities:**
  - Documents how to deploy the application inside XAMPP (`htdocs/sokohub_presentation`).
  - Explains MySQL database creation in phpMyAdmin and the correct order for importing SQL scripts.
  - Outlines a structured 5-step walkthrough demonstrating responsive design, database querying, dual validation, multi-role registration, and session-based authentication.

### [`README.md`](file:///C:/Users/abelk/Projects/sokohub/sokohub/README.md)
- **Primary Function:** Project overview and developer onboarding documentation.
- **Key Responsibilities:**
  - Outlines the SokoHub mission, technology stack (PHP 8+, MySQL/MariaDB, Vanilla JS, Responsive CSS), and system requirements.
  - Provides quickstart setup steps for XAMPP and shared hosting environments (InfinityFree / 000webhost / Render).
  - Supplies built-in demo credentials for all four system roles (Admin, Seller, Buyer, Delivery Rider).

---

## 3. Configuration Directory (`config/`)

### [`config/config.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/config/config.php)
- **Primary Function:** Centralized global constants, URL routing, and environment resolver.
- **Key Responsibilities:**
  - Defines global application constants: `APP_NAME`, `APP_TAGLINE`, `APP_CURRENCY` (`KSh`), `APP_ROOT`, `UPLOAD_DIR`, and `MAX_UPLOAD_BYTES` (2 MB).
  - Implements `app_base_path()` to dynamically determine the URL path prefix, ensuring seamless execution whether hosted at a domain root or in a subfolder (e.g. `http://localhost/sokohub/`).
  - Implements `url($path)` to generate accurate, root-relative hyperlinks throughout all views and components.
  - Implements `get_db_config()` which resolves database credentials in priority order: environment variables (Docker/Cloud), `db.local.php` (local/shared hosting), or standard XAMPP defaults (`127.0.0.1`, user `root`, no password, db `sokohub_db`).

### [`config/db.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/config/db.php)
- **Primary Function:** Database connection provider and health probe using PHP Data Objects (PDO).
- **Key Responsibilities:**
  - Implements `get_db_connection(): PDO` using a singleton pattern to maintain a single efficient database connection per request.
  - Configures PDO with security and reliability options: exception error mode (`ERRMODE_EXCEPTION`), associative array fetch mode (`FETCH_ASSOC`), and disabled emulated prepared statements (`ATTR_EMULATE_PREPARES => false`) to enforce genuine parameterized queries.
  - Implements `test_db_connection(): array` to safely probe database credentials and retrieve table lists via `SHOW TABLES` without crashing the application.

### [`config/db.local.php.example`](file:///C:/Users/abelk/Projects/sokohub/sokohub/config/db.local.php.example)
- **Primary Function:** Configuration template for sensitive local or production database credentials.
- **Key Responsibilities:**
  - Demonstrates how to override default database settings by returning an associative array with keys: `host`, `port`, `dbname`, `user`, and `pass`.
  - Intended to be copied to `db.local.php` (which is excluded from Git to prevent secret leaks).

---

## 4. Database Directory (`database/`)

### [`database/schema.sql`](file:///C:/Users/abelk/Projects/sokohub/sokohub/database/schema.sql)
- **Primary Function:** Full DDL script creating the 3NF relational database schema.
- **Key Responsibilities:**
  - Drops existing tables in reverse dependency order and sets UTF-8 encoding (`utf8mb4`).
  - Defines 9 normalized InnoDB relational tables with foreign key constraints and indexes:
    1. `users`: Core account details, phone, bcrypt password hash, role enum (`buyer`, `seller`, `delivery`, `admin`), and status (`active`, `suspended`).
    2. `seller_profiles`: One-to-one extension for sellers (business name, business type, town, description).
    3. `delivery_profiles`: One-to-one extension for couriers (transport method, license number, service area, availability flag).
    4. `categories`: Product categories with unique slugs and descriptions.
    5. `products`: Catalog items with pricing, original price (for discount computation), stock level, condition (`New`/`Second Hand`), image path, featured flag, and foreign keys to seller and category.
    6. `orders`: Customer orders with unique order codes (`SKH-YYYY-XXXX`), payment method/status, delivery address, delivery town, and order status (`pending`, `confirmed`, `processing`, `shipped`, `delivered`, `cancelled`).
    7. `order_items`: Order line items capturing quantity, historical unit price, and calculated line totals.
    8. `deliveries`: Courier dispatch records capturing delivery rider assignment, pickup location, dropoff address, delivery fee, tracking status, and timestamps.
    9. `contact_messages`: Inquiries submitted via the contact form with resolution status (`new`, `read`, `resolved`).

### [`database/seed.sql`](file:///C:/Users/abelk/Projects/sokohub/sokohub/database/seed.sql)
- **Primary Function:** Database population script providing demonstration and test data.
- **Key Responsibilities:**
  - Seeds 7 pre-configured user accounts covering all roles with pre-hashed passwords (`SokoHub@2026`).
  - Creates linked profile records in `seller_profiles` and `delivery_profiles`.
  - Inserts 5 marketplace categories (`Clothes`, `Gas`, `Food`, `Electronics`, `Others`).
  - Inserts 10 realistic product listings with prices, stock levels, condition attributes, and image paths.
  - Seeds historical and active orders, line items, and delivery records to enable instant testing of dashboard KPIs, sales reports, and courier dispatch views.

---

## 5. Includes Directory (`includes/`)

### [`includes/auth.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/auth.php)
- **Primary Function:** Authentication engine, session management, cookies, and access control.
- **Key Responsibilities:**
  - `init_session()`: Configures secure cookie parameters (`httponly`, `samesite=Lax`, conditional `secure`) and starts the PHP session safely.
  - `track_visit_cookie()`: Sets or refreshes a 7-day `sokohub_last_visit` cookie storing the visitor's latest timestamp.
  - `set_remember_email_cookie()`: Stores or clears a 30-day `sokohub_remember_email` cookie based on user preference.
  - `set_recent_category_cookie()`: Remembers the user's most recently viewed category in a 7-day cookie for personalized navigation.
  - `current_user()`, `is_logged_in()`: Returns session user state.
  - `login_user()`: Validates credentials using `password_verify()`, regenerates session ID (`session_regenerate_id(true)`) to prevent session fixation, and stores authenticated user state in `$_SESSION['user']`.
  - `logout_user()`: Safely terminates the session and invalidates the session cookie.
  - `require_login()`, `require_role()`: Enforces Role-Based Access Control (RBAC) guards on protected pages, redirecting unauthorized users with flash warnings.

### [`includes/functions.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/functions.php)
- **Primary Function:** Global helper library, security sanitizers, validation, and file upload handlers.
- **Key Responsibilities:**
  - `e()`: Sanitizes HTML output with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` to prevent Cross-Site Scripting (XSS).
  - `redirect()`: Performs standard HTTP 302 redirects with clean application path resolution.
  - `format_ksh()`: Formats numeric values as currency strings (e.g. `KSh 1,200.00`).
  - `discount_percent()`: Calculates integer percentage savings between original and current selling prices.
  - `csrf_token()`, `verify_csrf()`: Generates and verifies cryptographically secure CSRF tokens (`random_bytes(32)`) via `hash_equals()` to protect all state-changing POST requests.
  - `set_flash()`, `get_flash()`: Manages one-time session flash notifications (`success`, `error`, `warning`, `info`).
  - `get_role_labels()`, `get_business_types()`, `get_transport_methods()`, `get_status_badge()`: Maps enumerated database values to human-readable labels and color-coded CSS badge classes.
  - `get_nav_items()`: Constructs the navigation menu array dynamically based on the current user's authentication and role.
  - `cart_count()`: Aggregates total item quantities from the session shopping cart.
  - `validate_registration_fields()`, `validate_product_fields()`: Server-side validation helpers checking length, email format, phone format, and numeric boundaries.
  - `handle_product_image_upload()`: Validates uploaded product image files against size limits (2 MB) and MIME types (JPEG, PNG, WebP) using `finfo`, moves files into `assets/img/uploads/`, and generates unique randomized filenames.

### [`includes/header.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/header.php)
- **Primary Function:** Shared HTML document `<head>`, branding, and responsive navigation bar.
- **Key Responsibilities:**
  - Emits HTML5 doctype, viewport meta tags, dynamic `<title>`, and stylesheet links.
  - Renders the brand logo and name linking back to the home page.
  - Provides the accessible mobile drawer toggle button (`#navToggle`) with ARIA attributes.
  - Dynamically renders navigation links, applying the `.active` class to the current page.
  - Displays authenticated user badge with first name and role tag, or "Register" and "Sign In" action buttons for guests.
  - Automatically checks for and renders dismissible session flash notification banners (`.alert`).

### [`includes/footer.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/footer.php)
- **Primary Function:** Shared page footer, cookie metadata disclosure, and JavaScript inclusion.
- **Key Responsibilities:**
  - Displays marketplace summary, contact details, and physical office address in Kutus, Kirinyaga.
  - Reads and displays the `sokohub_last_visit` cookie timestamp if present.
  - Provides quick link columns for storefront navigation and role registration entry points.
  - Displays copyright and academic project attribution notices.
  - Loads the client-side JavaScript bundle (`assets/js/app.js`).

---

## 6. Application Pages Directory (`pages/`)

### 6.1 Authentication Pages (`pages/auth/`)

#### [`pages/auth/login.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/auth/login.php)
- **Primary Function:** User sign-in interface.
- **Key Responsibilities:**
  - Redirects already-authenticated users to their dashboard.
  - Pre-populates the email field if a `sokohub_remember_email` cookie is detected.
  - Provides one-click demo login buttons for instant testing across all 4 roles (Admin, Seller, Buyer, Rider).
  - Validates CSRF tokens and credentials via `login_user()`.
  - Sets flash messages and routes authenticated users directly to their role-aware dashboard.

#### [`pages/auth/logout.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/auth/logout.php)
- **Primary Function:** Authentication teardown endpoint.
- **Key Responsibilities:**
  - Executes `logout_user()` to destroy session variables and invalidate session cookies.
  - Re-initializes a fresh session to deliver an informational flash notification.
  - Redirects the user safely back to the login page.

#### [`pages/auth/register.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/auth/register.php)
- **Primary Function:** Interactive account role selection portal.
- **Key Responsibilities:**
  - Displays visual cards outlining the benefits, features, and capabilities of each account type.
  - Guides users to their appropriate specialized registration form: Buyer, Seller, or Delivery Rider.

#### [`pages/auth/register_buyer.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/auth/register_buyer.php)
- **Primary Function:** Dedicated registration form for retail customers.
- **Key Responsibilities:**
  - Validates full name, email, phone number, and password matching.
  - Checks for existing email conflicts in the `users` table.
  - Hashes passwords securely with `password_hash(..., PASSWORD_BCRYPT)`.
  - Inserts the new user record with role `buyer` and status `active`.
  - Automatically starts an authenticated session and redirects the new customer to the dashboard.

#### [`pages/auth/register_seller.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/auth/register_seller.php)
- **Primary Function:** Dedicated vendor onboarding form.
- **Key Responsibilities:**
  - Captures personal user credentials plus vendor business details (business name, category/type, town, and description).
  - Uses a database transaction (`beginTransaction()`, `commit()`, `rollBack()`) to atomically create the `users` entry and the corresponding `seller_profiles` entry.
  - Establishes the authenticated vendor session upon successful registration.

#### [`pages/auth/register_delivery.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/auth/register_delivery.php)
- **Primary Function:** Dedicated delivery personnel onboarding form.
- **Key Responsibilities:**
  - Captures driver credentials along with courier details (transport mode, driver license number, service area).
  - Uses an atomic database transaction to insert both `users` and `delivery_profiles` rows simultaneously.
  - Logs the rider in automatically and redirects to the dashboard.

---

### 6.2 Dashboard Pages (`pages/dashboard/`)

#### [`pages/dashboard/dashboard.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/dashboard/dashboard.php)
- **Primary Function:** Dynamic, role-aware control panel for authenticated users.
- **Key Responsibilities:**
  - Enforces authentication using `require_login()`.
  - Detects the active user role and computes tailored Key Performance Indicators (KPIs):
    - **Buyer:** Total completed orders, total spending amount (`KSh`), active in-transit deliveries, and cart item count.
    - **Seller:** Active product listings, low-stock warnings (`stock <= 5`), total vendor revenue, and total units sold.
    - **Delivery Rider:** Available dispatch jobs in coverage area, completed deliveries, total delivery earnings, and active assignments.
    - **Admin:** Total registered users, total active catalog products, platform gross merchandise volume, and total active deliveries.
  - Displays recent order and delivery activity tables with color-coded status badges.
  - Renders interactive HTML5 Canvas metric charts for visual analytics.

#### [`pages/dashboard/manage_products.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/dashboard/manage_products.php)
- **Primary Function:** Full CRUD inventory management module.
- **Key Responsibilities:**
  - Restricted to Sellers (managing their own catalog) and Admins (managing platform-wide items) via `require_role(['seller', 'admin'])`.
  - **Create:** Allows adding products with name, category, price, discount/original price, stock quantity, condition (`New`/`Second Hand`), and image selection.
  - **Image Handling:** Supports selecting from pre-packaged asset imagery or uploading custom files with secure validation (`handle_product_image_upload()`).
  - **Read:** Lists existing products in an inventory table with stock indicators, condition tags, prices, and direct links to public listings.
  - **Update:** Pre-fills product data for in-place modifications and updates database records with prepared SQL statements.
  - **Delete & Safe Archiving:** Checks if a product has historical sales references in `order_items`. If prior orders exist, it automatically marks the product as `archived` (`status = 'archived'`) to preserve accounting and referential integrity; if unreferenced, it performs a permanent SQL `DELETE`.

---

### 6.3 Public Marketplace Pages (`pages/public/`)

#### [`pages/public/about.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/public/about.php)
- **Primary Function:** Public company information, vision, and local economic impact.
- **Key Responsibilities:**
  - Presents SokoHub's mission to bridge the gap between neighborhood shops in Kirinyaga and the digital economy.
  - Queries live database statistics: total registered local vendors, active product listings, and successfully delivered orders.
  - Features community marketplace photography and local commerce value pillars.

#### [`pages/public/contact.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/public/contact.php)
- **Primary Function:** Customer service and general inquiry submission.
- **Key Responsibilities:**
  - Pre-populates sender name and email if the visitor is currently authenticated.
  - Employs dual-layer validation: client-side JavaScript rules in `app.js` and server-side validation in PHP.
  - Verifies CSRF tokens to prevent form spam and cross-site request forgery.
  - Inserts valid inquiries into the `contact_messages` table and delivers a success confirmation banner.

#### [`pages/public/products.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/public/products.php)
- **Primary Function:** Dynamic marketplace catalog browser and search engine.
- **Key Responsibilities:**
  - Supports keyword search across product names and descriptions using SQL `LIKE` queries.
  - Provides category filtering and records the selected category in the `sokohub_recent_category` cookie.
  - Supports item condition filtering (`New` vs `Second Hand`).
  - Supports sorting options: newest arrivals, price low-to-high, price high-to-low, and alphabetical order.
  - Implements pagination (6 products per page) with page controls.
  - Renders product cards displaying badges, computed discount percentages, stock indicators, and seller names.

#### [`pages/public/product_details.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/public/product_details.php)
- **Primary Function:** In-depth single product showcase.
- **Key Responsibilities:**
  - Queries detailed product specifications joined with category metadata, seller profile information, and vendor contact numbers.
  - Updates the recent category cookie to match the current product.
  - Displays high-resolution imagery, breadcrumbs, pricing with calculated discount badges, and stock availability status.
  - Displays verified seller profile details (business name, location town, and description) to establish buyer trust.

---

## 7. Static Assets Directory (`assets/`)

### 7.1 Stylesheets (`assets/css/`)

#### [`assets/css/style.css`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/css/style.css)
- **Primary Function:** Complete responsive stylesheet for the entire application.
- **Key Responsibilities:**
  - Defines design tokens using CSS variables: brand colors (forest green `#1b5e20`, warm amber `#ff8f00`, dark slate `#1e293b`), typography, border radiuses, and shadow elevations.
  - Implements responsive layouts using CSS Grid and Flexbox for product catalogs, split-screen features, hero sections, and dashboards.
  - Provides responsive mobile drawer styles for small screens (`@media (max-width: 960px)` and `@media (max-width: 640px)`).
  - Styles forms, validation errors, status badges, buttons, tables, cards, and dismissible alerts.

---

### 7.2 Scripts (`assets/js/`)

#### [`assets/js/app.js`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/js/app.js)
- **Primary Function:** Client-side interaction, form validation, and data visualization.
- **Key Responsibilities:**
  - **Mobile Menu Drawer:** Toggles the navigation drawer on mobile viewports and synchronizes the `aria-expanded` accessibility state.
  - **Dismissible Alerts:** Handles the closing and smooth removal of flash notification banners from the DOM.
  - **Action Confirmation:** Intercepts clicks on elements with `data-confirm` attributes (such as product deletions) to prompt user confirmation.
  - **Demo Login Helper:** Listens to `data-demo-email` and `data-demo-pass` buttons on the login page to auto-fill credentials for quick evaluation.
  - **Client-Side Form Validation:** Enforces real-time input validation on all forms marked `data-validate="true"`, verifying required fields, character lengths, email formatting, phone numbers, and password matching with inline error tags.
  - **HTML5 Canvas Charts:** Draws responsive bar charts on dashboard pages using HTML5 Canvas primitives (axes, gridlines, filled bars, and value labels).

---

### 7.3 Images & Uploads (`assets/img/`)

#### Brand & Preset Imagery
- **[`assets/img/sokohub-logo.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/sokohub-logo.jpg):** Official marketplace logo displayed in the site header, navigation bar, and footer.
- **[`assets/img/hero-market.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/hero-market.jpg):** Wide marketplace photography used as the visual anchor in the storefront hero section.
- **[`assets/img/about-stall.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/about-stall.jpg):** Image depicting local market vendors used on the About Us page.
- **[`assets/img/login-bg.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/login-bg.jpg):** Ambient background visual utilized across authentication cards.
- **[`assets/img/cart-icon.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/cart-icon.jpg):** Default shopping cart visual and fallback image for products without custom photos.
- **[`assets/img/clothes.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/clothes.jpg):** Preset catalog photo for the Clothes category.
- **[`assets/img/gas-tanks.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/gas-tanks.jpg):** Preset catalog photo for cooking gas cylinders and refills.
- **[`assets/img/gas-mascot.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/gas-mascot.jpg):** Preset photo for LPG gas safety equipment and accessories.
- **[`assets/img/fresh-produce.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/fresh-produce.jpg):** Preset photo for farm vegetables, tomatoes, and peppers.
- **[`assets/img/organic-food.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/organic-food.jpg):** Preset photo for packaged organic groceries and food crates.
- **[`assets/img/electronics.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/electronics.jpg):** Preset photo for home appliances and TVs.
- **[`assets/img/router.jpeg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/router.jpeg):** Preset photo for Wi-Fi routers and computing accessories.
- **[`assets/img/indoor-market.jpg`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/indoor-market.jpg):** Preset photo for local crafts, spices, and artisan products.

#### Uploads Directory
- **[`assets/img/uploads/`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/uploads/):** Destination directory where user-uploaded product images are stored with randomized unique filenames (`prod_[hash].[ext]`).
- **[`assets/img/uploads/.gitkeep`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/img/uploads/.gitkeep):** Git placeholder file ensuring the writable upload folder is tracked in repository checkouts.

---

## 8. Summary of Architectural Safeguards

| Feature | Mechanism | Implementation Location |
| :--- | :--- | :--- |
| **SQL Injection Defense** | Parameterized queries with PDO & disabled emulated prepares | [`config/db.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/config/db.php) |
| **Cross-Site Scripting (XSS)** | Mandatory output escaping via `e()` (`htmlspecialchars`) | [`includes/functions.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/functions.php) |
| **Cross-Site Request Forgery (CSRF)** | Session tokens validated on every POST action via `verify_csrf()` | [`includes/functions.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/functions.php) |
| **Session Security** | `session_regenerate_id()`, `HttpOnly`, `SameSite=Lax` cookies | [`includes/auth.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/auth.php) |
| **Password Security** | Bcrypt hashing via `password_hash()` and `password_verify()` | [`includes/auth.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/auth.php) |
| **Role-Based Access Control** | Explicit authorization guards (`require_role()`) | [`includes/auth.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/auth.php) |
| **Data Integrity** | Foreign keys with `ON DELETE CASCADE` / `RESTRICT` and safe archiving | [`database/schema.sql`](file:///C:/Users/abelk/Projects/sokohub/sokohub/database/schema.sql), [`manage_products.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/pages/dashboard/manage_products.php) |
| **Dual Validation** | Real-time JavaScript validation backed by rigorous PHP validation | [`assets/js/app.js`](file:///C:/Users/abelk/Projects/sokohub/sokohub/assets/js/app.js), [`includes/functions.php`](file:///C:/Users/abelk/Projects/sokohub/sokohub/includes/functions.php) |
