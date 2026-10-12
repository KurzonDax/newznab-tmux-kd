/**
 * Alpine.data('copyToClipboard') - Copy button with visual feedback
 *
 * The component body lives in `profile-edit-component.js` so it can be tested
 * without booting Alpine; this file only registers it.
 */
import Alpine from '@alpinejs/csp';

import { copyToClipboard } from './profile-edit-component.js';

Alpine.data('copyToClipboard', copyToClipboard);
