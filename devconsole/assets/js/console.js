/**
 * SokoHub Developer Console Client Utilities
 */

document.addEventListener('DOMContentLoaded', function() {
    // Re-instantiate Lucide icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Copy to clipboard helper
    document.querySelectorAll('[data-copy]').forEach(el => {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            const text = this.getAttribute('data-copy');
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                const orig = this.innerHTML;
                this.innerText = 'Copied!';
                setTimeout(() => { this.innerHTML = orig; if (typeof lucide !== 'undefined') lucide.createIcons(); }, 1500);
            });
        });
    });

    // Risky action confirmation modal/prompt
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            const msg = this.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Auto-dismiss alerts after 5 seconds if specified
    document.querySelectorAll('.alert-auto-dismiss').forEach(el => {
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.4s ease';
            setTimeout(() => el.remove(), 400);
        }, 5000);
    });
});
