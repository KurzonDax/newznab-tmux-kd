import Alpine from '@alpinejs/csp';
import { searchFeed, searchFilters } from './search-filters-component.js';

Alpine.data('searchFilters', searchFilters);
Alpine.data('searchFeed', searchFeed);
