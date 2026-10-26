import Alpine from 'alpinejs';
import productSearch from './product-search';

Alpine.data('productSearch', productSearch);

window.Alpine = Alpine;
Alpine.start();
