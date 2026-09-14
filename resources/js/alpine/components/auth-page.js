/**
 * Alpine.data('otpInput') - 2FA OTP auto-submit
 */
import Alpine from '@alpinejs/csp';

Alpine.data('authPage', () => ({}));

Alpine.data('otpInput', () => ({
    value: '',

    onInput() {
        // Remove non-numeric
        this.value = this.value.replace(/[^0-9]/g, '');
        // Auto-submit at 6 digits
        if (this.value.length === 6) {
            setTimeout(() => {
                const form = this.$el.closest('form');
                if (form) form.submit();
            }, 300);
        }
    },

    init() {
        this.$nextTick(() => this.$el.focus());
    }
}));

// Document-level delegation for auth pages without x-data
(function() {
    var otp = document.getElementById('one_time_password');
    if (otp && !otp.closest('[x-data]')) {
        otp.addEventListener('input', function() { this.value = this.value.replace(/[^0-9]/g, ''); if (this.value.length === 6) setTimeout(function() { otp.form.submit(); }, 300); });
        otp.focus();
    }
})();
