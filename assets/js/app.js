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

    // 8. Dark / Light Mode Switcher
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', newTheme);
            try {
                localStorage.setItem('sokohub_theme', newTheme);
            } catch (e) {}
            document.cookie = 'sokohub_theme=' + newTheme + '; path=/; max-age=31536000; SameSite=Lax';

            // Re-render any charts on page to match theme palette
            document.querySelectorAll('canvas[data-chart-values]').forEach((canvas) => {
                renderBarChart(canvas);
            });
        });
    }
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

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const maxVal = Math.max(...values, 1);

    // Grid lines
    ctx.strokeStyle = isDark ? '#334155' : '#e2e8f0';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = padTop + (plotH * i) / 4;
        ctx.beginPath();
        ctx.moveTo(padLeft, y);
        ctx.lineTo(width - padRight, y);
        ctx.stroke();

        const tickVal = Math.round(maxVal * (1 - i / 4));
        ctx.fillStyle = isDark ? '#94a3b8' : '#64748b';
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
        ctx.fillStyle = isDark ? '#f8fafc' : '#1e293b';
        ctx.textAlign = 'center';
        ctx.fillText(val.toLocaleString(), x + barW / 2, Math.max(14, y - 6));

        // Category label below axis
        ctx.fillStyle = isDark ? '#cbd5e1' : '#334155';
        const shortLabel = String(label).length > 14 ? String(label).slice(0, 12) + '..' : String(label);
        ctx.fillText(shortLabel, x + barW / 2, height - 16);
    });
}
