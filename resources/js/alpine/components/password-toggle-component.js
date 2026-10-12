/**
 * The `passwordToggle` component body, kept free of any Alpine import so it can
 * be exercised headlessly. `password-toggle.js` is the thin registration shim.
 *
 * The root wraps one password field (`x-ref="field"`) and its eye button.
 */
export function passwordToggle() {
    return {
        visible: false,

        toggle() {
            this.visible = !this.visible;
            const field = this.$refs.field;
            if (field) {
                field.type = this.visible ? 'text' : 'password';
            }
        },

        iconClass() {
            return this.visible ? 'fa-eye-slash' : 'fa-eye';
        },
    };
}
