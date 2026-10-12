/**
 * Alpine.js CSP-safe initialization
 *
 * Core components are imported eagerly (used on every/most pages).
 * Page-specific and heavy components are lazy-loaded: only the modules
 * whose x-data names appear in the current page's DOM are fetched.
 * This keeps the initial JS bundle small and improves TTI.
 */
import Alpine from '@alpinejs/csp';

// Make Alpine globally available
window.Alpine = Alpine;

// --- Stores (must register before components that use them) ---
import './stores/theme.js';
import './stores/toast.js';
import './stores/cart.js';

// --- Core UI components (used on every/most pages) ---
import './components/theme-toggle.js';
import './components/back-to-top.js';
import './components/public-navigation.js';
import './components/confirm-modal.js';
import './components/confirm-link.js';
import './components/toast-notification.js';
import './components/dismissible.js';

// --- Lazy-load page-specific components, then start Alpine ---
import { loadAndStart } from './lazy-loader.js';
loadAndStart();
