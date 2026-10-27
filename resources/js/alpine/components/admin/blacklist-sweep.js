/**
 * Alpine.data('blacklistSweep') - Start and follow blacklist sweeps from the admin list
 *
 * The component body lives in `blacklist-sweep-component.js` so it can be tested
 * without booting Alpine; this file only registers it.
 */
import Alpine from '@alpinejs/csp';

import { blacklistSweep } from './blacklist-sweep-component.js';

Alpine.data('blacklistSweep', blacklistSweep);
