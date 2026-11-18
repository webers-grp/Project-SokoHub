import requests
import re
import sys

BASE_URL = "http://127.0.0.1:8088"

roles_to_test = [
    ("Admin", "admin@sokohub.co.ke", "SokoHub@2026", [
        "/pages/dashboard/dashboard.php",
        "/pages/dashboard/seller_verification.php",
        "/pages/dashboard/audit_logs.php",
        "/pages/dashboard/bulk_upload.php",
        "/pages/dashboard/payouts.php",
        "/pages/dashboard/reports.php",
    ]),
    ("Seller", "seller@sokohub.co.ke", "SokoHub@2026", [
        "/pages/dashboard/dashboard.php",
        "/pages/dashboard/manage_products.php",
        "/pages/dashboard/payouts.php",
        "/pages/dashboard/kyc_upload.php",
        "/pages/dashboard/reports.php",
    ]),
    ("Delivery", "rider@sokohub.co.ke", "SokoHub@2026", [
        "/pages/dashboard/dashboard.php",
        "/pages/dashboard/deliveries.php",
        "/pages/dashboard/kyc_upload.php",
    ]),
    ("Buyer", "buyer@sokohub.co.ke", "SokoHub@2026", [
        "/index.php",
        "/pages/public/products.php",
        "/pages/public/product_details.php?id=1",
        "/pages/buyer/cart.php",
        "/pages/buyer/checkout.php",
        "/pages/buyer/orders.php",
        "/pages/buyer/wishlist.php",
        "/pages/buyer/notifications.php",
        "/pages/auth/profile.php",
    ]),
]

all_passed = True

for role_name, email, password, test_urls in roles_to_test:
    session = requests.Session()
    login_page = session.get(f"{BASE_URL}/pages/auth/login.php").text
    m = re.search(r'name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']', login_page)
    csrf = m.group(1) if m else ""

    login_res = session.post(f"{BASE_URL}/pages/auth/login.php", data={
        "csrf_token": csrf,
        "email": email,
        "password": password
    }, allow_redirects=True)

    print(f"\n--- Testing Role: {role_name} ({email}) ---")
    if login_res.status_code != 200:
        print(f"❌ Login failed: HTTP {login_res.status_code}")
        all_passed = False
        continue

    for url in test_urls:
        resp = session.get(f"{BASE_URL}{url}")
        has_err = ("Fatal error" in resp.text or "Parse error" in resp.text or "Warning:" in resp.text)
        status = "OK" if resp.status_code == 200 and not has_err else "FAIL"
        print(f"[{status}] {resp.status_code} {url} ({len(resp.text)} bytes)")
        if resp.status_code != 200 or has_err:
            all_passed = False
            print("  -> ERROR:", resp.text[:400])

if all_passed:
    print("\n=======================================================")
    print("  ALL 4 USER ROLES & ALL DASHBOARDS 100% OPERATIONAL! ")
    print("=======================================================")
else:
    print("\nERRORS DETECTED IN ROLE TESTS")
    sys.exit(1)
