/**
 * Alpine.data('passwordToggle') - Toggle password field visibility
 *
 * The component body lives in `password-toggle-component.js` so it can be tested
 * without booting Alpine; this file only registers it.
 */
import Alpine from '@alpinejs/csp';

import { passwordToggle } from './password-toggle-component.js';

Alpine.data('passwordToggle', passwordToggle);
