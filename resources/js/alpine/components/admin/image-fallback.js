/**
 * Alpine.data('imageFallback') - Show a placeholder when an image cannot be loaded
 *
 * The component body lives in `image-fallback-component.js` so it can be tested
 * without booting Alpine; this file only registers it.
 */
import Alpine from '@alpinejs/csp';

import { imageFallback } from './image-fallback-component.js';

Alpine.data('imageFallback', imageFallback);
