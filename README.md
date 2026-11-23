# SokoHub – Local Digital Marketplace & Delivery Coordination Platform

**SPI 2301: Web Application Development II — Kirinyaga University Group Semester Project**

SokoHub is a clean, modular **PHP 8+ and MySQL/MariaDB** e-commerce marketplace connecting local sellers, buyers, and delivery personnel across Kirinyaga (Kutus, Kerugoya, Kagio, Sagana) and beyond.

---

## Clean Project Folder Structure

```text
Project-SokoHub/
├── index.php                  # Storefront Home Page (entry point)
├── health.php                 # Week-1 Deployment & DB Health Check
├── README.md                  # Quickstart, folder map & demo credentials
├── Dockerfile                 # Render.com container deployment
├── .htaccess                  # Protects config/, database/, includes/, docs/, tests/
├── .gitignore                 # Excludes local DB credentials & user uploads
│
├── config/                    # Configuration & Database Connection
│   ├── config.php             # Dynamic url() helper & DB config resolver
│   ├── db.php                 # PDO MySQL connection & health probe
│   └── db.local.php.example   # Template for InfinityFree / 000webhost credentials
│
├── includes/                  # Shared PHP Templates & Core Logic
│   ├── auth.php               # session_start(), cookies, password_verify(), RBAC guards
│   ├── functions.php          # PHP arrays, control structures, validation, CSRF, flash
│   ├── header.php             # Shared <head>, responsive navbar & flash alerts
│   └── footer.php             # Shared footer, visit cookie note & JS bundle
│
├── pages/                     # All Pages Organized by Feature Module
│   ├── public/                # Storefront Catalog, Product Details, About & Contact
│   ├── auth/                  # Role Hub, Buyer/Seller/Delivery Register, Login & Logout
│   ├── buyer/                 # Shopping Cart, Atomic Checkout & Order Tracking
│   └── dashboard/             # Role Dashboard, Product CRUD, Deliveries, Inquiries & Reports
│
├── assets/                    # Static Front-End Files
│   ├── css/style.css          # Unified responsive design system
│   ├── js/app.js              # Client-side validation, mobile menu & Canvas charts
│   └── img/                   # Marketplace images & uploads/ directory
│
├── database/                  # MySQL Scripts
│   ├── schema.sql             # 9-table 3NF InnoDB schema
│   └── seed.sql               # Seed users, categories, 10 products, orders & deliveries
│
├── docs/                      # Complete Step-by-Step Documentation & Screenshots
│   ├── 01-PROJECT-PROPOSAL.md
│   ├── 02-SYSTEM-DESIGN.md
│   ├── 03-STEP-BY-STEP-DEVELOPMENT-GUIDE.md
│   ├── 04-TESTING-AND-QA.md
│   ├── 05-DEPLOYMENT-GUIDE.md
│   ├── 06-USER-MANUAL.md
│   ├── 07-FINAL-REPORT-COMPILATION.md
│   └── screenshots/
│
└── tests/                     # Automated QA & Browser Verification Suite
    ├── run_tests.php          # PHP + MariaDB integration tests
    └── browser_verify.py      # Playwright E2E checks & screenshot generator
```

---

## Team Collaboration & Git Branching Workflow

This project is divided across four feature teams. All members branch off `Web-Dev`, work independently, and submit Pull Requests for review.

### Feature Branch Assignments
| Team Members | Feature Branch | Ownership Scope | Primary Files / Folders |
| :--- | :--- | :--- | :--- |
| **Ian & Elsie** | `feature/auth-otp` | Email OTP, 2FA, Login Throttling, Password Reset, Jobs Worker | `pages/auth/`, `cron/worker.php`, `otp_codes`, `jobs` |
| **Octavious & Ronny** | `feature/cart-checkout` | Cart, Checkout, Stock Reservation, Wishlist, Reviews, Autocomplete Search | `pages/buyer/`, `api/search_suggest.php`, `reviews`, `wishlists` |
| **Chris, Abel & Njambi** | `feature/mpesa-delivery` | M-Pesa Daraja STK Push, Callbacks, Cancellation & Refunds, Rider Route & Proof | `pages/dashboard/deliveries.php`, `mpesa/`, `payments`, `delivery_proofs` |
| **Kennedy** | `feature/seller-kyc-admin` | Seller KYC Approval, Admin Audit Logs, Low-stock Alerts, Payouts, Bulk CSV Upload | `pages/dashboard/reports.php`, `admin/`, `seller/`, `audit_logs` |

### How Collaborators Start Working:
```bash
# 1. Clone repository
git clone -b Web-Dev git@github.com:kennedykalawa/Project-SokoHub.git
cd Project-SokoHub

# 2. Create your assigned feature branch
git checkout -b feature/your-feature-name

# 3. Setup local database
# - Open phpMyAdmin, create 'sokohub_db'
# - Import database/schema.sql and database/seed.sql
# - (Optional) Copy config/db.local.php.example to config/db.local.php if your MySQL password is not blank

# 4. Run automated tests to verify clean baseline
php tests/run_tests.php

# 5. Commit your feature code and push
git add .
git commit -m "feat(module): describe changes"
git push -u origin feature/your-feature-name
# 6. Open a Pull Request into Web-Dev on GitHub
```

---

## Quick Local Setup (XAMPP / Linux PHP + MySQL)

1. Place this folder inside `htdocs/sokohub` (XAMPP) or serve it with `php -S 127.0.0.1:8088`.
2. Open phpMyAdmin, create database `sokohub_db` (`utf8mb4_unicode_ci`), and import:
   - `database/schema.sql`
   - `database/seed.sql`
3. Open `http://localhost/sokohub/health.php` to verify PDO connectivity and all 9 tables.
4. Click **Sign In** (`pages/auth/login.php`) and test any demo account (password: `SokoHub@2026`):
   - **Buyer:** `buyer@sokohub.co.ke`
   - **Seller:** `seller@sokohub.co.ke`
   - **Delivery Rider:** `rider@sokohub.co.ke`
   - **Admin:** `admin@sokohub.co.ke`

---

## Step-by-Step Documentation Suite (`docs/`)

1. [`docs/01-PROJECT-PROPOSAL.md`](docs/01-PROJECT-PROPOSAL.md) — Proposal, Objectives-to-Module Matrix, Methodology, Requirements & UML Diagrams.
2. [`docs/02-SYSTEM-DESIGN.md`](docs/02-SYSTEM-DESIGN.md) — Flowcharts, 9-Table 3NF ERD & Schema, and Page Wireframes.
3. [`docs/03-STEP-BY-STEP-DEVELOPMENT-GUIDE.md`](docs/03-STEP-BY-STEP-DEVELOPMENT-GUIDE.md) — File-by-file build walkthrough covering PHP fundamentals, sessions, cookies, PDO CRUD, JS validation & 4 reports.
4. [`docs/04-TESTING-AND-QA.md`](docs/04-TESTING-AND-QA.md) — 8 formal test cases and automated PHP + Playwright browser verification results.
5. [`docs/05-DEPLOYMENT-GUIDE.md`](docs/05-DEPLOYMENT-GUIDE.md) — GitHub workflow and live hosting instructions for InfinityFree, 000webhost, and Render.
6. [`docs/06-USER-MANUAL.md`](docs/06-USER-MANUAL.md) — Role-by-role user guide with real UI screenshots.
7. [`docs/07-FINAL-REPORT-COMPILATION.md`](docs/07-FINAL-REPORT-COMPILATION.md) — Ready-to-submit SPI 2301 rubric map and title page template.
8. [`DEVCONSOLE.md`](DEVCONSOLE.md) — Isolated Developer Console Architecture, Tools Catalogue, 2FA TOTP Enrollment, and Deployment Guide.
