<?php
/**
 * SokoHub Account Registration Hub
 * Supports Buyer, Seller, and Delivery registration with client-side
 * password strength meter, confirm-password validator, and 6-digit OTP verification.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$selectedRole = strtolower(trim((string) ($_GET['role'] ?? 'buyer')));
if (!in_array($selectedRole, ['buyer', 'seller', 'delivery'], true)) {
    $selectedRole = 'buyer';
}

$pageTitle = 'SokoHub | Register Account';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Join SokoHub Marketplace</h2>
            <p style="margin-top: 0.75rem;">
                Empowering Kirinyaga businesses, shoppers, and riders with secure digital transactions, instant verification, and real-time fulfillment.
            </p>
            <div style="margin-top: 2rem; background: rgba(255,255,255,0.12); padding: 1.25rem; border-radius: 12px; backdrop-filter: blur(4px);">
                <div style="font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <?= icon('shield-check', 'lucide-sm') ?>
                    <span>Safe &amp; Verified Community</span>
                </div>
                <p class="small" style="margin: 0; opacity: 0.95;">
                    Every new account is protected with two-factor cryptographic verification and proactive brute-force throttling.
                </p>
            </div>
        </div>

        <div class="auth-body">
            <h1>Create Your Account</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Select your role to get started with SokoHub</p>

            <!-- Role Selector Tabs -->
            <div class="role-selector-tabs">
                <button type="button" class="role-tab-btn <?= $selectedRole === 'buyer' ? 'active' : '' ?>" data-role="buyer">
                    <?= icon('shopping-bag', 'lucide-xs') ?>
                    <span>Buyer</span>
                </button>
                <button type="button" class="role-tab-btn <?= $selectedRole === 'seller' ? 'active' : '' ?>" data-role="seller">
                    <?= icon('store', 'lucide-xs') ?>
                    <span>Seller</span>
                </button>
                <button type="button" class="role-tab-btn <?= $selectedRole === 'delivery' ? 'active' : '' ?>" data-role="delivery">
                    <?= icon('truck', 'lucide-xs') ?>
                    <span>Delivery</span>
                </button>
            </div>

            <!-- Registration Form -->
            <form action="<?= e(url('pages/auth/register_process.php')) ?>" method="POST" id="mainRegisterForm" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="role" id="formRoleInput" value="<?= e($selectedRole) ?>">

                <div class="form-group">
                    <label for="reg_full_name">Full Name</label>
                    <input type="text"
                           id="reg_full_name"
                           name="full_name"
                           class="form-control"
                           required
                           minlength="3"
                           placeholder="e.g. Kelvin Mwangi">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="reg_email">Email Address</label>
                        <input type="email"
                               id="reg_email"
                               name="email"
                               class="form-control"
                               required
                               placeholder="name@example.com">
                    </div>

                    <div class="form-group">
                        <label for="reg_phone">Phone Number (M-Pesa)</label>
                        <input type="tel"
                               id="reg_phone"
                               name="phone"
                               class="form-control"
                               required
                               placeholder="+254 712 345 678">
                    </div>
                </div>

                <!-- Seller-specific Extra Fields -->
                <div id="sellerExtraFields" style="display: <?= $selectedRole === 'seller' ? 'block' : 'none' ?>; margin-top: 0.75rem; padding: 1.25rem; background: var(--bg-surface); border: 1.5px solid var(--border-color); border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 0.4rem; font-weight: 700; color: var(--amber-dark); margin-bottom: 0.85rem;">
                        <?= icon('store', 'lucide-xs') ?>
                        <span>Store Identity &amp; Campus Location</span>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="reg_bname">Store / Business Name *</label>
                            <input type="text" id="reg_bname" name="business_name" class="form-control" placeholder="e.g. Soko Fresh Grocers">
                        </div>
                        <div class="form-group">
                            <label for="reg_btype">Store Category *</label>
                            <select id="reg_btype" name="business_type" class="form-control">
                                <option value="retail">Retail / General Merchant</option>
                                <option value="food_beverage">Fresh Produce &amp; Groceries</option>
                                <option value="crafts">Apparel &amp; Handcrafts</option>
                                <option value="services">Electronics &amp; Hardware</option>
                                <option value="other">Other Specialties</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="reg_town">Primary Town / Market *</label>
                            <input type="text" id="reg_town" name="town" class="form-control" value="Kutus" placeholder="e.g. Kutus, Kerugoya, Kagio">
                        </div>

                        <div class="form-group">
                            <label for="reg_stall_location">Campus / Stall / Street Location</label>
                            <input type="text" id="reg_stall_location" name="stall_location" class="form-control" placeholder="e.g. KyU Gate B Stall 4, Hostel 3 Complex">
                            <span class="small muted">Helps dispatch riders and campus shoppers find your store.</span>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="reg_operating_hours">Daily Operating Hours</label>
                            <input type="text" id="reg_operating_hours" name="operating_hours" class="form-control" value="8:00 AM - 8:00 PM" placeholder="e.g. 8:00 AM - 8:00 PM">
                        </div>

                        <div class="form-group">
                            <label for="reg_whatsapp_phone">WhatsApp Business Line (Optional)</label>
                            <input type="tel" id="reg_whatsapp_phone" name="whatsapp_phone" class="form-control" placeholder="e.g. +254 712 345 678">
                        </div>
                    </div>

                    <!-- Kirinyaga University Student / Staff Entrepreneur Affiliation -->
                    <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; padding: 0.85rem 1rem; margin: 0.85rem 0;">
                        <div class="checkbox-row" style="margin: 0;">
                            <input id="reg_is_student_vendor" name="is_student_vendor" type="checkbox" value="1">
                            <label for="reg_is_student_vendor" style="margin: 0; font-weight: 600; color: var(--green-dark); display: flex; align-items: center; gap: 0.4rem;">
                                <?= icon('graduation-cap', 'lucide-xs', 'emerald') ?>
                                I am a Kirinyaga University (KyU) Student or Staff Entrepreneur
                            </label>
                        </div>

                        <div id="regStudentRegContainer" style="margin-top: 0.65rem; display: none;">
                            <label for="reg_student_reg_no" class="small" style="font-weight: 600; color: var(--green-dark);">Student / Staff Registration Number *</label>
                            <input type="text" id="reg_student_reg_no" name="student_reg_no" class="form-control" placeholder="e.g. EB1/12345/22 or Staff ID">
                            <div class="small" style="color: var(--green-dark); margin-top: 0.35rem;">
                                <?= icon('award', 'lucide-xs') ?> Verified campus vendors receive an exclusive <strong>KyU Student Entrepreneur</strong> badge on listings.
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="reg_description">Short Store Description (Optional)</label>
                        <input type="text" id="reg_description" name="description" class="form-control" placeholder="What products or services do you specialize in?">
                    </div>

                    <!-- KYC Verification Notice -->
                    <div style="margin-top: 0.85rem; padding: 0.65rem 0.85rem; border-radius: 6px; background: rgba(56, 189, 248, 0.1); border: 1px dashed rgba(56, 189, 248, 0.4); display: flex; align-items: center; gap: 0.5rem;">
                        <?= icon('shield-check', 'lucide-xs', 'blue') ?>
                        <span class="small muted" style="line-height: 1.35;">
                            <strong>KYC Verification &amp; Payouts:</strong> Official verification (National ID upload &amp; M-Pesa Payout Account) will be completed from your Seller Dashboard after activation.
                        </span>
                    </div>
                </div>

                <!-- Delivery-specific Extra Fields -->
                <div id="deliveryExtraFields" style="display: <?= $selectedRole === 'delivery' ? 'block' : 'none' ?>; margin-top: 0.75rem; padding: 1rem; background: var(--bg-surface); border: 1.5px solid var(--border-color); border-radius: 8px;">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="reg_transport">Transport Method</label>
                            <select id="reg_transport" name="transport_method" class="form-control">
                                <option value="motorbike">Motorbike / Boda Boda</option>
                                <option value="tuk_tuk">Tuk-Tuk</option>
                                <option value="bicycle">Bicycle</option>
                                <option value="pickup_truck">Pickup Truck</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reg_plate">Vehicle Plate / Registration</label>
                            <input type="text" id="reg_plate" name="vehicle_plate_number" class="form-control" placeholder="e.g. KMCL 123A">
                        </div>
                    </div>
                </div>

                <!-- Password and Confirm Password with Live Strength Meter -->
                <div class="form-grid-2" style="margin-top: 1rem;">
                    <div class="form-group">
                        <label for="reg_password">Password</label>
                        <input type="password"
                               id="reg_password"
                               name="password"
                               class="form-control"
                               required
                               minlength="8"
                               placeholder="Minimum 8 characters">
                    </div>

                    <div class="form-group">
                        <label for="reg_confirm_password">Confirm Password</label>
                        <input type="password"
                               id="reg_confirm_password"
                               name="confirm_password"
                               class="form-control"
                               required
                               placeholder="Re-enter your password">
                    </div>
                </div>

                <!-- Client-Side Password Strength Meter Widget -->
                <div style="margin: 0.5rem 0 1rem;">
                    <div style="height: 6px; width: 100%; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                        <div id="regStrengthBar" style="height: 100%; width: 0%; background: #ef4444; transition: width 0.3s ease, background-color 0.3s ease;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.35rem;">
                        <span class="small muted">
                            Password Strength: <strong id="regStrengthLabel" style="color: #ef4444;">Too weak</strong>
                        </span>
                        <div id="regMatchNotice" class="small" style="font-weight: 600; display: none;"></div>
                    </div>

                    <!-- Rule checklist items -->
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.35rem; margin-top: 0.6rem; font-size: 0.8rem;" class="muted">
                        <div id="ruleLength" style="display: flex; align-items: center; gap: 0.35rem;">
                            <?= icon('circle', 'lucide-xs') ?> <span>At least 8 characters</span>
                        </div>
                        <div id="ruleLetter" style="display: flex; align-items: center; gap: 0.35rem;">
                            <?= icon('circle', 'lucide-xs') ?> <span>Uppercase &amp; lowercase</span>
                        </div>
                        <div id="ruleNumber" style="display: flex; align-items: center; gap: 0.35rem;">
                            <?= icon('circle', 'lucide-xs') ?> <span>At least one number</span>
                        </div>
                        <div id="ruleSpecial" style="display: flex; align-items: center; gap: 0.35rem;">
                            <?= icon('circle', 'lucide-xs') ?> <span>At least one symbol</span>
                        </div>
                    </div>
                </div>

                <button type="submit" id="btnSubmitRegister" class="btn btn-amber btn-block" style="padding: 0.85rem; font-size: 1.05rem; margin-top: 1rem;">
                    <?= icon('user-plus') ?>
                    <span id="submitBtnText">Create Buyer Account</span>
                </button>
            </form>

            <p class="small muted" style="margin-top: 1.25rem; text-align: center;">
                Already have an account? <a href="<?= e(url('pages/auth/login.php')) ?>"><strong><?= icon('log-in', 'lucide-sm') ?> Sign In Here</strong></a>
            </p>
        </div>
    </div>
</main>

<!-- Client-side Password Strength Meter & Confirm-Password Validator Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const roleBtns = document.querySelectorAll('.role-tab-btn');
    const roleInput = document.getElementById('formRoleInput');
    const sellerFields = document.getElementById('sellerExtraFields');
    const deliveryFields = document.getElementById('deliveryExtraFields');
    const submitBtn = document.getElementById('btnSubmitRegister');
    const submitText = document.getElementById('submitBtnText');

    const pwd = document.getElementById('reg_password');
    const confirm = document.getElementById('reg_confirm_password');
    const bar = document.getElementById('regStrengthBar');
    const label = document.getElementById('regStrengthLabel');
    const matchNotice = document.getElementById('regMatchNotice');

    const ruleLength = document.getElementById('ruleLength');
    const ruleLetter = document.getElementById('ruleLetter');
    const ruleNumber = document.getElementById('ruleNumber');
    const ruleSpecial = document.getElementById('ruleSpecial');

    // Role Tab Switching
    roleBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const role = this.getAttribute('data-role');
            roleInput.value = role;

            roleBtns.forEach(b => {
                b.classList.remove('active');
                b.style.removeProperty('background');
                b.style.removeProperty('box-shadow');
                b.style.removeProperty('color');
            });

            this.classList.add('active');

            if (role === 'seller') {
                sellerFields.style.display = 'block';
                deliveryFields.style.display = 'none';
                submitBtn.className = 'btn btn-amber btn-block';
                submitText.textContent = 'Create Seller Account';
            } else if (role === 'delivery') {
                sellerFields.style.display = 'none';
                deliveryFields.style.display = 'block';
                submitBtn.className = 'btn btn-primary btn-block';
                submitText.textContent = 'Join Delivery Team';
            } else {
                sellerFields.style.display = 'none';
                deliveryFields.style.display = 'none';
                submitBtn.className = 'btn btn-primary btn-block';
                submitText.textContent = 'Create Buyer Account';
            }
        });
    });

    // Kirinyaga University Student Entrepreneur registration toggle
    const regStudentCheck = document.getElementById('reg_is_student_vendor');
    const regStudentBox = document.getElementById('regStudentRegContainer');
    const regStudentInput = document.getElementById('reg_student_reg_no');
    if (regStudentCheck && regStudentBox) {
        regStudentCheck.addEventListener('change', function () {
            regStudentBox.style.display = this.checked ? 'block' : 'none';
            if (this.checked && regStudentInput) {
                regStudentInput.focus();
            }
        });
    }

    // Password Strength Evaluator
    function evaluatePassword(val) {
        let score = 0;
        const hasLength = val.length >= 8;
        const hasLetter = /[a-z]/.test(val) && /[A-Z]/.test(val);
        const hasNumber = /[0-9]/.test(val);
        const hasSpecial = /[^a-zA-Z0-9]/.test(val);

        updateRuleIcon(ruleLength, hasLength);
        updateRuleIcon(ruleLetter, hasLetter);
        updateRuleIcon(ruleNumber, hasNumber);
        updateRuleIcon(ruleSpecial, hasSpecial);

        if (hasLength) score += 25;
        if (val.length >= 12) score += 15;
        if (hasLetter) score += 25;
        if (hasNumber) score += 20;
        if (hasSpecial) score += 15;

        return Math.min(100, score);
    }

    function updateRuleIcon(element, passed) {
        if (!element) return;
        if (passed) {
            element.style.color = '#059669';
            element.innerHTML = '&#10003; ' + element.querySelector('span').outerHTML;
        } else {
            element.style.color = 'var(--text-muted)';
            element.innerHTML = '&#9675; ' + element.querySelector('span').outerHTML;
        }
    }

    if (pwd) {
        pwd.addEventListener('input', function () {
            const score = evaluatePassword(this.value);
            bar.style.width = score + '%';

            if (score < 40) {
                bar.style.backgroundColor = '#ef4444';
                label.textContent = 'Weak';
                label.style.color = '#ef4444';
            } else if (score < 75) {
                bar.style.backgroundColor = '#f59e0b';
                label.textContent = 'Medium';
                label.style.color = '#d97706';
            } else {
                bar.style.backgroundColor = '#10b981';
                label.textContent = 'Strong';
                label.style.color = '#059669';
            }

            validatePasswordMatch();
        });
    }

    // Confirm-Password Match Validator
    function validatePasswordMatch() {
        if (!confirm || !matchNotice) return;

        if (!confirm.value) {
            matchNotice.style.display = 'none';
            confirm.style.borderColor = '#cbd5e1';
            return;
        }

        matchNotice.style.display = 'block';
        if (pwd.value === confirm.value) {
            matchNotice.textContent = 'Passwords match';
            matchNotice.style.color = '#059669';
            confirm.style.borderColor = '#10b981';
        } else {
            matchNotice.textContent = 'Passwords do not match';
            matchNotice.style.color = '#ef4444';
            confirm.style.borderColor = '#ef4444';
        }
    }

    if (confirm) {
        confirm.addEventListener('input', validatePasswordMatch);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
