# SokoHub Pre-Launch & Security Readiness Checklist

Review and complete every item before opening SokoHub to live public transactions.

---

### Phase 1: Authentication & Credentials Rotation
- [ ] **Rotate Developer Keys**: The Daraja sandbox keys and passkeys shared during initial testing must be rotated immediately. Obtain live production keys directly from the Daraja portal.
- [ ] **Change Admin Passwords**: Update the default admin password (`SokoHub@2026`) to a strong, unique, 16+ character password.
- [ ] **Database Credentials**: Ensure the production database user has minimum required privileges (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) on `sokohub_prod` and NO `GRANT OPTION`.
- [ ] **Remove Demo Accounts**: Remove or disable any seed testing accounts (`buyer@sokohub.co.ke`, `seller@sokohub.co.ke`, `rider@sokohub.co.ke`) that have public demo passwords.

---

### Phase 2: Environment & Debugging Posture
- [ ] **Disable Display Errors**: Verify that `APP_ENV=production` is set in `.env` and `display_errors` is confirmed `0` via `pages/dashboard/system_health.php`.
- [ ] **Disable Simulation Endpoints**: Verify that `MPESA_ALLOW_SIMULATION=false` and `is_live=true` so that simulation fallbacks cannot be reached by external callers.
- [ ] **Verify HTTP Security Headers**: Test site via `curl -I https://sokohub.co.ke` and verify presence of:
  - `Content-Security-Policy`
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `Strict-Transport-Security`

---

### Phase 3: Access Control & Directory Security
- [ ] **Hidden Files Blocked**: Test requesting `https://sokohub.co.ke/.env` and `https://sokohub.co.ke/.git` &mdash; confirm server returns HTTP 403 or 404.
- [ ] **Upload Folder Execution Blocked**: Upload test `.php` file into `assets/img/uploads/` &mdash; confirm direct HTTP execution is completely denied.
- [ ] **KYC Document Access Protection**: Verify that direct requests to `assets/img/uploads/kyc/` return 403 Forbidden, and all document views require authentication through `/pages/api/kyc_document.php?id=...`.

---

### Phase 4: Financial & Settlement Verification
- [ ] **Test Real 10 KSh STK Push**: Conduct an end-to-end live test transaction with Safaricom Daraja using a real Kenyan mobile number.
- [ ] **Verify Callback Processing**: Check that Safaricom callback updates the payment status to `paid` and records the real M-Pesa receipt number.
- [ ] **Verify 2% Fee Settlement**: Check the `payouts` settlement ledger to verify:
  - Platform receives exactly 2% (`platform_fee_amount`)
  - Seller net receives exactly 98% (`net_amount`)
  - Delivery rider fee is credited in full
- [ ] **Test Cash on Delivery (COD)**: Verify that COD checkout marks payment status as `pending_cod`, records rider dispatch, and only marks paid when the rider confirms delivery.

---

### Phase 5: Backups & Monitoring
- [ ] **Automated Daily Backups**: Verify that `0 2 * * * php /var/www/sokohub/scripts/backup.php` is running in cron and backup files are generating in `/backups/`.
- [ ] **Payment Reconciliation Cron**: Verify `*/5 * * * * php /var/www/sokohub/scripts/reconcile_payments.php` is active.
- [ ] **Log Monitoring**: Confirm error logs are writing to `/logs/app_error.log` and no warnings are being emitted.
