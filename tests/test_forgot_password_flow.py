import re
import urllib.request
import urllib.parse
import http.cookiejar

BASE_URL = "http://127.0.0.1:8088"

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

print("=== 1. Loading forgot_password.php ===")
req = urllib.request.Request(f"{BASE_URL}/pages/auth/forgot_password.php")
resp = opener.open(req)
html = resp.read().decode("utf-8")
assert resp.status == 200, f"Expected 200, got {resp.status}"

# Extract CSRF token
csrf_match = re.search(r'name="csrf_token"\s+value="([a-f0-9]+)"', html)
assert csrf_match, "Could not find CSRF token on forgot_password.php"
csrf_token = csrf_match.group(1)
print(f"CSRF token obtained: {csrf_token[:16]}...")

print("\n=== 2. Submitting password reset request for buyer@sokohub.co.ke ===")
post_data = urllib.parse.urlencode({
    "csrf_token": csrf_token,
    "email": "buyer@sokohub.co.ke"
}).encode("utf-8")

req = urllib.request.Request(f"{BASE_URL}/pages/auth/forgot_password.php", data=post_data, method="POST")
resp = opener.open(req)
html = resp.read().decode("utf-8")
assert resp.status == 200, f"Expected 200 on submit, got {resp.status}"

# Check for reset link in response
link_match = re.search(r'href="([^"]*reset_password\.php\?token=[a-f0-9]+)"', html)
assert link_match, f"Reset link not found in response HTML! Content:\n{html[:1000]}"
reset_url = link_match.group(1)
if not reset_url.startswith("http"):
    reset_url = BASE_URL + ("/" if not reset_url.startswith("/") else "") + reset_url
print(f"Reset URL successfully generated: {reset_url}")

print("\n=== 3. Navigating to reset_password.php ===")
req = urllib.request.Request(reset_url)
resp = opener.open(req)
html = resp.read().decode("utf-8")
assert resp.status == 200, f"Expected 200 on reset page, got {resp.status}"
assert "Resetting password for" in html or "Set New Password" in html

# Extract new CSRF token and token param
csrf_match2 = re.search(r'name="csrf_token"\s+value="([a-f0-9]+)"', html)
assert csrf_match2, "Could not find CSRF token on reset_password.php"
csrf_token2 = csrf_match2.group(1)

raw_token_match = re.search(r'name="token"\s+value="([a-f0-9]+)"', html)
assert raw_token_match, "Could not find token input on reset_password.php"
raw_token = raw_token_match.group(1)

print(f"Reset page verified! Token: {raw_token[:16]}...")

print("\n=== 4. Submitting new password (SokoHub@2026New) ===")
post_data2 = urllib.parse.urlencode({
    "csrf_token": csrf_token2,
    "token": raw_token,
    "new_password": "SokoHub@2026New",
    "confirm_password": "SokoHub@2026New"
}).encode("utf-8")

req = urllib.request.Request(reset_url, data=post_data2, method="POST")
resp = opener.open(req)
html = resp.read().decode("utf-8")
print(f"Password reset response URL: {resp.url}, Status: {resp.status}")
print("Response length:", len(html))
print("Response body snippet:")
for line in html.splitlines():
    if "Link Error" in line or "alert" in line or "Security token" in line or "Password" in line or "Reset" in line or "error" in line.lower():
        print("  >", line.strip())
assert "login.php" in resp.url or "Sign In" in html or "successfully reset" in html
print("Password reset successful!")

print("\n=== 5. Testing Login with New Password ===")
# Get login page to extract CSRF token
req = urllib.request.Request(f"{BASE_URL}/pages/auth/login.php")
resp = opener.open(req)
html = resp.read().decode("utf-8")
csrf_match3 = re.search(r'name="csrf_token"\s+value="([a-f0-9]+)"', html)
assert csrf_match3
csrf_token3 = csrf_match3.group(1)

login_data = urllib.parse.urlencode({
    "csrf_token": csrf_token3,
    "email": "buyer@sokohub.co.ke",
    "password": "SokoHub@2026New"
}).encode("utf-8")

req = urllib.request.Request(f"{BASE_URL}/pages/auth/login.php", data=login_data, method="POST")
resp = opener.open(req)
html = resp.read().decode("utf-8")
print(f"Login response URL: {resp.url}")
assert "index.php" in resp.url or "buyer" in html or "Sign Out" in html or "Welcome" in html
print("Login with NEW password succeeded!")

print("\n=== 6. Resetting Password Back to SokoHub@2026 for consistency ===")
# Reset back via direct DB or flow
import subprocess
subprocess.run([
    'C:\\xampp\\php\\php.exe', '-r',
    'require "Project-SokoHub/config/db.php"; $pdo = get_db_connection(); $h = password_hash("SokoHub@2026", PASSWORD_DEFAULT); $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?")->execute([$h, "buyer@sokohub.co.ke"]); echo "Buyer password restored to default SokoHub@2026\n";'
])

print("\n=== ALL FORGOT PASSWORD CHECKS PASSED 100%! ===")
