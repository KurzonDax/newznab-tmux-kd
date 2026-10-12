/**
 * Alpine.data('adminInvitations') - Select-all box of the admin invitations list
 *
 * The component body lives in `invitations-component.js` so it can be tested
 * without booting Alpine; this file only registers it.
 */
import Alpine from '@alpinejs/csp';

import { adminInvitations } from './invitations-component.js';

Alpine.data('adminInvitations', adminInvitations);
