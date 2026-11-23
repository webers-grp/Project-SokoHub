/**
 * SokoHub Front-End Interactivity & Client-Side Form Validation
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile Navigation Drawer Toggle
    const navToggle = document.getElementById('navToggle');
    const navMenu = document.getElementById('navMenu');
    if (navToggle && navMenu) {
        navToggle.addEventListener('click', () => {
            const isOpen = navMenu.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    // 2. Dismissible Flash Alerts
    document.querySelectorAll('.alert-close').forEach((btn) => {
        btn.addEventListener('click', () => {
            const alertEl = btn.closest('.alert');
            if (alertEl) {
                alertEl.remove();
            }
        });
    });

    // 3. Delete / Critical Action Confirmation
    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (event) => {
            const msg = el.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (!window.confirm(msg)) {
                event.preventDefault();
            }
        });
    });

    // 4. Demo Login Account Quick-Fill Buttons
    document.querySelectorAll('[data-demo-email]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const emailInput = document.getElementById('login_email');
            const passInput = document.getElementById('login_password');
            if (emailInput && passInput) {
                emailInput.value = btn.getAttribute('data-demo-email') || '';
                passInput.value = btn.getAttribute('data-demo-pass') || '';
                clearFieldError(emailInput);
                clearFieldError(passInput);
            }
        });
    });

    // 5. Client-Side Validation for All Forms Marked data-validate="true"
    document.querySelectorAll('form[data-validate="true"]').forEach((form) => {
        form.setAttribute('novalidate', 'novalidate');

        form.addEventListener('submit', (event) => {
            let isValid = true;
            const fields = form.querySelectorAll('input, select, textarea');

            fields.forEach((field) => {
                if (!validateField(field, form)) {
                    isValid = false;
                }
            });

            if (!isValid) {
                event.preventDefault();
                const firstErr = form.querySelector('.input-invalid');
                if (firstErr) {
                    firstErr.focus();
                }
            }
        });

        form.querySelectorAll('input, select, textarea').forEach((field) => {
            field.addEventListener('input', () => validateField(field, form));
            field.addEventListener('change', () => validateField(field, form));
        });
    });

    // 6. Render Lightweight SVG Bar Charts on Reports Page
    document.querySelectorAll('canvas[data-chart-values]').forEach((canvas) => {
        renderBarChart(canvas);
    });

    // 7. Initialize Lucide Vector Icons (Strict Compact Default)
    if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
        lucide.createIcons({
            attrs: {
                width: '13',
                height: '13',
                'stroke-width': '1.85'
            }
        });
    }

    // 8. Group 2: Live Autocomplete Search Bar
    initLiveSearch();

    // 9. Group 2: Cart Quantity Spinners & Async AJAX Updates
    initCartAjax();

    // 10. Group 2: Multi-Step Checkout Wizard
    initMultiStepCheckout();

    // 11. Group 2: Interactive 5-Star Rating Widget
    initRatingWidget();

    // 12. Group 2: Wishlist Toggle
    initWishlistToggle();
});

function clearFieldError(field) {
    field.classList.remove('input-invalid');
    const wrapper = field.closest('.form-group, .checkbox-row') || field.parentElement;
    if (!wrapper) return;
    const existing = wrapper.querySelector('.field-error');
    if (existing) existing.remove();
}

function showFieldError(field, message) {
    field.classList.add('input-invalid');
    const wrapper = field.closest('.form-group, .checkbox-row') || field.parentElement;
    if (!wrapper) return;
    let err = wrapper.querySelector('.field-error');
    if (!err) {
        err = document.createElement('span');
        err.className = 'field-error';
        wrapper.appendChild(err);
    }
    err.textContent = message;
}

function validateField(field, form) {
    if (field.disabled || field.type === 'hidden' || field.type === 'submit' || field.type === 'button') {
        return true;
    }

    clearFieldError(field);
    const val = field.type === 'checkbox' ? '' : field.value.trim();

    if (field.required) {
        if (field.type === 'checkbox' && !field.checked) {
            showFieldError(field, 'Please accept this requirement before submitting.');
            return false;
        }
        if (field.type !== 'checkbox' && val === '') {
            showFieldError(field, 'This field is required.');
            return false;
        }
    }

    if (val === '') {
        return true;
    }

    const minLen = parseInt(field.getAttribute('data-minlength') || field.getAttribute('minlength') || '0', 10);
    if (minLen > 0 && val.length < minLen) {
        showFieldError(field, `Please enter at least ${minLen} characters.`);
        return false;
    }

    if (field.type === 'email') {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(val)) {
            showFieldError(field, 'Please enter a valid email address (e.g. name@example.com).');
            return false;
        }
    }

    if (field.type === 'tel') {
        const phoneRegex = /^\+?[0-9\s\-]{9,20}$/;
        if (!phoneRegex.test(val)) {
            showFieldError(field, 'Enter a valid phone number with 9-20 digits (e.g. +254 712 345 678).');
            return false;
        }
    }

    if (field.type === 'number') {
        const num = parseFloat(val);
        const minAttr = field.getAttribute('min');
        if (Number.isNaN(num)) {
            showFieldError(field, 'Please enter a valid number.');
            return false;
        }
        if (minAttr !== null && num < parseFloat(minAttr)) {
            showFieldError(field, `Value must be at least ${minAttr}.`);
            return false;
        }
    }

    const matchTargetId = field.getAttribute('data-match');
    if (matchTargetId) {
        const target = form.querySelector(`#${matchTargetId}`);
        if (target && field.value !== target.value) {
            showFieldError(field, 'Passwords do not match.');
            return false;
        }
    }

    return true;
}

function renderBarChart(canvas) {
    let labels = [];
    let values = [];
    try {
        labels = JSON.parse(canvas.getAttribute('data-chart-labels') || '[]');
        values = JSON.parse(canvas.getAttribute('data-chart-values') || '[]');
    } catch (_) {
        return;
    }

    if (!labels.length || !values.length) {
        return;
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const width = canvas.parentElement ? Math.max(320, canvas.parentElement.clientWidth - 16) : 640;
    const height = 260;
    canvas.width = width;
    canvas.height = height;

    const padLeft = 65;
    const padBottom = 42;
    const padTop = 24;
    const padRight = 20;
    const plotW = width - padLeft - padRight;
    const plotH = height - padTop - padBottom;

    ctx.clearRect(0, 0, width, height);
    ctx.font = '12px Segoe UI, Arial, sans-serif';

    const maxVal = Math.max(...values, 1);

    // Grid lines
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = padTop + (plotH * i) / 4;
        ctx.beginPath();
        ctx.moveTo(padLeft, y);
        ctx.lineTo(width - padRight, y);
        ctx.stroke();

        const tickVal = Math.round(maxVal * (1 - i / 4));
        ctx.fillStyle = '#64748b';
        ctx.textAlign = 'right';
        ctx.fillText(tickVal.toLocaleString(), padLeft - 8, y + 4);
    }

    // Bars
    const slotW = plotW / labels.length;
    const barW = Math.min(54, slotW * 0.56);

    labels.forEach((label, idx) => {
        const val = Number(values[idx] || 0);
        const barH = (val / maxVal) * plotH;
        const x = padLeft + idx * slotW + (slotW - barW) / 2;
        const y = padTop + plotH - barH;

        const grad = ctx.createLinearGradient(0, y, 0, padTop + plotH);
        grad.addColorStop(0, '#27b327');
        grad.addColorStop(1, '#d8860b');

        ctx.fillStyle = grad;
        ctx.fillRect(x, y, barW, barH);

        // Value label above bar
        ctx.fillStyle = '#1e293b';
        ctx.textAlign = 'center';
        ctx.fillText(val.toLocaleString(), x + barW / 2, Math.max(14, y - 6));

        // Category label below axis
        ctx.fillStyle = '#334155';
        const shortLabel = String(label).length > 14 ? String(label).slice(0, 12) + '..' : String(label);
        ctx.fillText(shortLabel, x + barW / 2, height - 16);
    });
}

// =============================================================================
// Group 2 Features (Octavious & Ronny)
// E-Commerce Catalog, Shopping Cart, Checkout, Wishlist & Ratings
// =============================================================================

function debounce(func, delay = 250) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
}

function getBaseUrl() {
    const meta = document.querySelector('meta[name="base-url"]');
    if (meta && meta.content) return meta.content.replace(/\/$/, '');
    return '';
}

/**
 * 1. Instant Live Autocomplete Search Bar with 250ms Debounce
 */
function initLiveSearch() {
    const searchInput = document.getElementById('navSearchInput');
    const dropdown = document.getElementById('navSearchDropdown');
    const clearBtn = document.getElementById('navSearchClear');
    const spinner = document.getElementById('navSearchSpinner');
    const searchWrap = document.getElementById('headerSearchWrap');

    if (!searchInput || !dropdown) return;

    let selectedIndex = -1;
    let currentResults = [];

    const handleSearch = debounce(async (query) => {
        const trimmed = query.trim();
        if (trimmed.length < 1) {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            if (clearBtn) clearBtn.style.display = 'none';
            return;
        }

        if (clearBtn) clearBtn.style.display = 'flex';
        if (spinner) spinner.style.display = 'inline-block';

        try {
            const baseUrl = getBaseUrl();
            const suggestUrl = `${baseUrl}/api/search_suggest.php?q=${encodeURIComponent(trimmed)}`;
            const response = await fetch(suggestUrl, {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) throw new Error('Search request failed');
            const data = await response.json();
            currentResults = Array.isArray(data) ? data : [];
            selectedIndex = -1;

            if (currentResults.length === 0) {
                dropdown.innerHTML = `
                    <div class="search-dropdown-empty">
                        No products found matching "<strong>${escapeHtml(trimmed)}</strong>"
                    </div>
                `;
            } else {
                dropdown.innerHTML = currentResults.map((item, idx) => `
                    <a href="${escapeHtml(item.url)}" class="search-dropdown-item" data-index="${idx}" role="option">
                        <img src="${escapeHtml(item.image_url)}" alt="" class="search-dropdown-thumb" onerror="this.src='${baseUrl}/assets/img/cart-icon.jpg'">
                        <div class="search-dropdown-info">
                            <div class="search-dropdown-title">${escapeHtml(item.name)}</div>
                            <div class="search-dropdown-meta">
                                <span class="search-dropdown-price">${escapeHtml(item.formatted_price)}</span>
                                ${item.rating_count > 0 ? `<span class="small muted">&bull; &#9733; ${item.rating_avg} (${item.rating_count})</span>` : ''}
                            </div>
                        </div>
                    </a>
                `).join('');
            }
            dropdown.style.display = 'block';
        } catch (err) {
            console.error('Search error:', err);
        } finally {
            if (spinner) spinner.style.display = 'none';
        }
    }, 250);

    searchInput.addEventListener('input', (e) => {
        handleSearch(e.target.value);
    });

    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 1 && currentResults.length > 0) {
            dropdown.style.display = 'block';
        }
    });

    // Keyboard navigation (ArrowDown, ArrowUp, Enter, Escape)
    searchInput.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.search-dropdown-item');
        if (!items.length || dropdown.style.display === 'none') return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIndex = (selectedIndex + 1) % items.length;
            updateItemSelection(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIndex = (selectedIndex - 1 + items.length) % items.length;
            updateItemSelection(items);
        } else if (e.key === 'Enter') {
            if (selectedIndex >= 0 && items[selectedIndex]) {
                e.preventDefault();
                items[selectedIndex].click();
            }
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    });

    function updateItemSelection(items) {
        items.forEach((it, idx) => {
            if (idx === selectedIndex) {
                it.classList.add('active');
                it.scrollIntoView({ block: 'nearest' });
            } else {
                it.classList.remove('active');
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            clearBtn.style.display = 'none';
            searchInput.focus();
        });
    }

    // Dismiss on click outside
    document.addEventListener('click', (e) => {
        if (searchWrap && !searchWrap.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * 2. Cart Quantity Spinners with Asynchronous AJAX Updates
 */
function initCartAjax() {
    const cartTable = document.querySelector('.data-table');
    if (!cartTable) return;

    // Spinner buttons (+ and -)
    document.querySelectorAll('.cart-qty-spinner').forEach((spinner) => {
        const pid = parseInt(spinner.getAttribute('data-product-id'), 10);
        const input = spinner.querySelector('.cart-qty-input');
        const btnDec = spinner.querySelector('.btn-spinner-dec');
        const btnInc = spinner.querySelector('.btn-spinner-inc');
        if (!input || !pid) return;

        if (btnDec) {
            btnDec.addEventListener('click', (e) => {
                e.preventDefault();
                const cur = parseInt(input.value, 10) || 1;
                if (cur > 1) {
                    input.value = cur - 1;
                    triggerCartUpdate(pid, cur - 1, input, btnDec, btnInc);
                }
            });
        }

        if (btnInc) {
            btnInc.addEventListener('click', (e) => {
                e.preventDefault();
                const cur = parseInt(input.value, 10) || 1;
                const max = parseInt(input.getAttribute('max'), 10) || 999;
                if (cur < max) {
                    input.value = cur + 1;
                    triggerCartUpdate(pid, cur + 1, input, btnDec, btnInc);
                }
            });
        }

        input.addEventListener('change', () => {
            let val = parseInt(input.value, 10) || 1;
            const max = parseInt(input.getAttribute('max'), 10) || 999;
            if (val < 1) val = 1;
            if (val > max) val = max;
            input.value = val;
            triggerCartUpdate(pid, val, input, btnDec, btnInc);
        });
    });

    // Remove buttons via AJAX
    document.querySelectorAll('.btn-cart-remove').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const pid = parseInt(btn.getAttribute('data-product-id'), 10);
            if (!pid) return;

            if (!confirm('Remove this item from your shopping cart?')) return;

            const row = document.getElementById(`cart-row-${pid}`);
            if (row) row.style.opacity = '0.4';

            try {
                const baseUrl = getBaseUrl();
                const csrf = getCsrfToken();
                const response = await fetch(`${baseUrl}/cart_actions.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        action: 'remove',
                        product_id: pid,
                        csrf_token: csrf,
                    }),
                });

                const data = await response.json();
                if (data.success) {
                    if (row) row.remove();
                    updateCartTotals(data);

                    // If cart now empty, reload page to display empty state
                    if (data.cart_count === 0) {
                        window.location.reload();
                    }
                } else {
                    alert(data.message || 'Could not remove item.');
                    if (row) row.style.opacity = '1';
                }
            } catch (err) {
                console.error('Remove cart error:', err);
                if (row) row.style.opacity = '1';
            }
        });
    });
}

async function triggerCartUpdate(productId, newQty, inputEl, decBtn, incBtn) {
    const row = document.getElementById(`cart-row-${productId}`);
    const lineTotalEl = document.getElementById(`line-total-${productId}`);
    const max = parseInt(inputEl.getAttribute('max'), 10) || 999;

    if (decBtn) decBtn.disabled = newQty <= 1;
    if (incBtn) incBtn.disabled = newQty >= max;

    if (lineTotalEl) lineTotalEl.style.opacity = '0.5';

    try {
        const baseUrl = getBaseUrl();
        const csrf = getCsrfToken();
        const response = await fetch(`${baseUrl}/cart_actions.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                action: 'update',
                product_id: productId,
                quantity: newQty,
                csrf_token: csrf,
            }),
        });

        const data = await response.json();
        if (data.success) {
            inputEl.value = data.quantity;
            if (lineTotalEl) {
                lineTotalEl.textContent = data.formatted_line_total;
            }
            updateCartTotals(data);
        } else {
            alert(data.message || 'Failed to update cart.');
        }
    } catch (err) {
        console.error('Cart update error:', err);
    } finally {
        if (lineTotalEl) lineTotalEl.style.opacity = '1';
    }
}

function updateCartTotals(data) {
    const subtotalEl = document.getElementById('cartSubtotal');
    const feeEl = document.getElementById('cartDeliveryFee');
    const grandTotalEl = document.getElementById('cartGrandTotal');
    const badgeEl = document.getElementById('cartBadge');

    if (subtotalEl && data.formatted_subtotal) subtotalEl.textContent = data.formatted_subtotal;
    if (feeEl && data.formatted_delivery_fee) feeEl.textContent = data.formatted_delivery_fee;
    if (grandTotalEl && data.formatted_total) grandTotalEl.textContent = data.formatted_total;
    if (badgeEl && typeof data.cart_count !== 'undefined') badgeEl.textContent = data.cart_count;
}

/**
 * 3. Multi-Step Checkout Form
 */
function initMultiStepCheckout() {
    const form = document.getElementById('checkoutForm');
    if (!form) return;

    const stepper = document.getElementById('checkoutStepper');
    const panes = [
        document.getElementById('stepPane1'),
        document.getElementById('stepPane2'),
        document.getElementById('stepPane3')
    ];

    function goToStep(stepNum) {
        panes.forEach((pane, idx) => {
            if (pane) {
                pane.style.display = (idx + 1 === stepNum) ? 'block' : 'none';
                if (idx + 1 === stepNum) pane.classList.add('active');
                else pane.classList.remove('active');
            }
        });

        if (stepper) {
            stepper.querySelectorAll('.step-item').forEach((item) => {
                const s = parseInt(item.getAttribute('data-step'), 10);
                if (s === stepNum) {
                    item.classList.add('active');
                    item.classList.remove('completed');
                } else if (s < stepNum) {
                    item.classList.add('completed');
                    item.classList.remove('active');
                } else {
                    item.classList.remove('active', 'completed');
                }
            });
        }

        // When moving to Step 3, populate review summaries
        if (stepNum === 3) {
            updateReviewSummaries();
        }

        window.scrollTo({ top: 120, behavior: 'smooth' });
    }

    // Step button listeners
    document.querySelectorAll('.btn-next-step').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = parseInt(btn.getAttribute('data-target'), 10);
            if (target === 2) {
                // Validate Step 1
                if (!validateStep1()) return;
            }
            goToStep(target);
        });
    });

    document.querySelectorAll('.btn-prev-step').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = parseInt(btn.getAttribute('data-target'), 10);
            goToStep(target);
        });
    });

    // Saved Addresses Radio Cards selection
    const addressRadios = form.querySelectorAll('input[name="address_id"]');
    const newAddressFields = document.getElementById('newAddressFields');

    addressRadios.forEach((radio) => {
        radio.addEventListener('change', () => {
            form.querySelectorAll('.address-card-radio').forEach((card) => {
                card.classList.remove('selected');
            });
            const parentCard = radio.closest('.address-card-radio');
            if (parentCard) parentCard.classList.add('selected');

            if (radio.value === '0') {
                if (newAddressFields) {
                    newAddressFields.style.display = 'block';
                    const nameInput = document.getElementById('recipient_name');
                    if (nameInput) nameInput.focus();
                }
            } else {
                if (newAddressFields) newAddressFields.style.display = 'none';
            }
        });
    });

    // Payment Methods Card selection
    const payRadios = form.querySelectorAll('input[name="payment_method"]');
    payRadios.forEach((radio) => {
        radio.addEventListener('change', () => {
            form.querySelectorAll('.payment-method-card').forEach((card) => card.classList.remove('selected'));
            const parentCard = radio.closest('.payment-method-card');
            if (parentCard) parentCard.classList.add('selected');
        });
    });

    function validateStep1() {
        const checkedAddr = form.querySelector('input[name="address_id"]:checked');
        if (!checkedAddr || checkedAddr.value === '0') {
            const name = document.getElementById('recipient_name')?.value.trim();
            const phone = document.getElementById('phone')?.value.trim();
            const town = document.getElementById('town')?.value.trim();
            const street = document.getElementById('street_address')?.value.trim();

            if (!name || name.length < 2) {
                alert('Please enter a valid recipient name.');
                document.getElementById('recipient_name')?.focus();
                return false;
            }
            if (!phone || phone.length < 9) {
                alert('Please enter a valid phone number for delivery contact.');
                document.getElementById('phone')?.focus();
                return false;
            }
            if (!town) {
                alert('Please select your delivery town.');
                document.getElementById('town')?.focus();
                return false;
            }
            if (!street || street.length < 5) {
                alert('Please provide a specific street address or landmark (at least 5 characters).');
                document.getElementById('street_address')?.focus();
                return false;
            }
        }
        return true;
    }

    function updateReviewSummaries() {
        const addrSummaryEl = document.getElementById('reviewAddressSummary');
        const paySummaryEl = document.getElementById('reviewPaymentSummary');

        // Address
        const checkedAddr = form.querySelector('input[name="address_id"]:checked');
        if (checkedAddr && checkedAddr.value !== '0') {
            const rName = checkedAddr.getAttribute('data-recipient') || '';
            const rPhone = checkedAddr.getAttribute('data-phone') || '';
            const rStreet = checkedAddr.getAttribute('data-street') || '';
            const rTown = checkedAddr.getAttribute('data-town') || '';
            const rCounty = checkedAddr.getAttribute('data-county') || '';
            if (addrSummaryEl) {
                addrSummaryEl.innerHTML = `<strong>${escapeHtml(rName)}</strong> (${escapeHtml(rPhone)})<br>${escapeHtml(rStreet)}, ${escapeHtml(rTown)}, ${escapeHtml(rCounty)}`;
            }
        } else {
            const name = document.getElementById('recipient_name')?.value.trim() || '';
            const phone = document.getElementById('phone')?.value.trim() || '';
            const street = document.getElementById('street_address')?.value.trim() || '';
            const town = document.getElementById('town')?.value.trim() || '';
            const county = document.getElementById('county')?.value.trim() || 'Kirinyaga';
            if (addrSummaryEl) {
                addrSummaryEl.innerHTML = `<strong>${escapeHtml(name)}</strong> (${escapeHtml(phone)})<br>${escapeHtml(street)}, ${escapeHtml(town)}, ${escapeHtml(county)}`;
            }
        }

        // Payment
        const checkedPay = form.querySelector('input[name="payment_method"]:checked')?.value || 'M-Pesa';
        const notes = document.getElementById('notes')?.value.trim();
        if (paySummaryEl) {
            paySummaryEl.innerHTML = `Selected Method: <strong>${escapeHtml(checkedPay)}</strong>${notes ? `<br><span class="muted">Note: ${escapeHtml(notes)}</span>` : ''}`;
        }
    }
}

/**
 * 4. Interactive 5-Star Rating Submission Widget
 */
function initRatingWidget() {
    const starWidget = document.getElementById('starWidget');
    if (!starWidget) return;

    const ratingInput = document.getElementById('reviewRatingInput');
    const ratingLabel = document.getElementById('ratingText');
    const starButtons = starWidget.querySelectorAll('.star-btn');

    const labels = {
        1: '1 Star (Poor)',
        2: '2 Stars (Fair)',
        3: '3 Stars (Good)',
        4: '4 Stars (Very Good)',
        5: '5 Stars (Excellent)'
    };

    let currentRating = parseInt(starWidget.getAttribute('data-current') || ratingInput.value || '5', 10);

    function renderStars(ratingValue, isHover = false) {
        starButtons.forEach((btn) => {
            const val = parseInt(btn.getAttribute('data-value'), 10);
            if (val <= ratingValue) {
                if (isHover) {
                    btn.classList.add('hover');
                } else {
                    btn.classList.add('active');
                    btn.classList.remove('hover');
                }
            } else {
                btn.classList.remove('active', 'hover');
            }
        });
        if (ratingLabel && labels[ratingValue]) {
            ratingLabel.textContent = labels[ratingValue];
        }
    }

    renderStars(currentRating);

    starButtons.forEach((btn) => {
        btn.addEventListener('mouseenter', () => {
            const hoverVal = parseInt(btn.getAttribute('data-value'), 10);
            renderStars(hoverVal, true);
        });

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            currentRating = parseInt(btn.getAttribute('data-value'), 10);
            if (ratingInput) ratingInput.value = currentRating;
            renderStars(currentRating, false);
        });
    });

    starWidget.addEventListener('mouseleave', () => {
        renderStars(currentRating, false);
    });
}

/**
 * 5. Wishlist Toggle Button (Instant AJAX)
 */
function initWishlistToggle() {
    document.querySelectorAll('.btn-wishlist-toggle, .btn-wishlist-remove').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const pid = parseInt(btn.getAttribute('data-product-id'), 10);
            if (!pid) return;

            btn.disabled = true;

            try {
                const baseUrl = getBaseUrl();
                const csrf = getCsrfToken();
                const response = await fetch(`${baseUrl}/api/wishlist_toggle.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        product_id: pid,
                        csrf_token: csrf,
                    }),
                });

                const data = await response.json();
                if (data.require_login) {
                    window.location.href = data.login_url || `${baseUrl}/pages/auth/login.php`;
                    return;
                }

                if (data.success) {
                    if (data.in_wishlist) {
                        btn.classList.add('active');
                        btn.title = 'Remove from wishlist';
                    } else {
                        btn.classList.remove('active');
                        btn.title = 'Add to wishlist';

                        // If on dedicated wishlist page, remove the card smoothly
                        const card = document.getElementById(`wishlist-card-${pid}`);
                        if (card) {
                            card.style.opacity = '0';
                            setTimeout(() => card.remove(), 200);
                        }
                    }
                } else {
                    alert(data.message || 'Could not update wishlist.');
                }
            } catch (err) {
                console.error('Wishlist error:', err);
            } finally {
                btn.disabled = false;
            }
        });
    });
}

