/**
 * Shared decade / custom-range / single-year picker.
 */
document.querySelectorAll('[data-year-picker]').forEach((picker) => {
    const select = picker.querySelector('[data-year-picker-select]');
    const customRange = picker.querySelector('[data-year-custom-range]');

    if (!select || !customRange) return;

    const updateVisibility = () => {
        customRange.classList.toggle('hidden', select.value !== 'custom');
    };

    select.addEventListener('change', updateVisibility);
    updateVisibility();
});
