/**
 * Alpine.data('adminRowDelete') - Delete one row of an admin list through admin/ajax
 *
 * The component body lives in `row-delete-component.js` so it can be tested
 * without booting Alpine; this file only registers it.
 */
import Alpine from '@alpinejs/csp';

import { adminRowDelete } from './row-delete-component.js';

Alpine.data('adminRowDelete', adminRowDelete);
