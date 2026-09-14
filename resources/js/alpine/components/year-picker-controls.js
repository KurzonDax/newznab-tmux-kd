/** Keep the three year parameters together while preserving the listing context. */
export function yearPickerUrl(location, selection, from = '', to = '') {
    const url = new URL(location);
    ['year', 'year_from', 'year_to', 'page', '_fragment'].forEach(key => url.searchParams.delete(key));
    if (selection !== '') url.searchParams.set('year', selection);
    if (selection === 'custom') {
        if (from !== '') url.searchParams.set('year_from', from);
        if (to !== '') url.searchParams.set('year_to', to);
    }
    return url;
}

/** Delegation also covers title release fragments inserted after page load. */
export function installYearPickers(document) {
    const apply = picker => {
        const controls = picker.querySelectorAll('input');
        if ([...controls].some(input => !input.reportValidity())) return;
        const url = yearPickerUrl(window.location.href,
            picker.querySelector('[name="year"]').value,
            picker.querySelector('[name="year_from"]').value,
            picker.querySelector('[name="year_to"]').value);
        window.location.assign(url.toString());
    };
    document.addEventListener('change', event => {
        const select = event.target.closest('[data-year-picker-select]');
        if (!select) return;
        const picker = select.closest('[data-year-picker]');
        const custom = select.value === 'custom';
        picker.querySelector('[data-year-custom-range]').classList.toggle('hidden', !custom);
        picker.querySelectorAll('input').forEach(input => { input.disabled = !custom; });
        if (!custom && picker.dataset.yearNavigate === '1') apply(picker);
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-year-apply]');
        if (button) apply(button.closest('[data-year-picker]'));
    });
    document.addEventListener('keydown', event => {
        const picker = event.target.closest('[data-year-picker]');
        if (event.key !== 'Enter' || event.target.tagName !== 'INPUT' || picker?.dataset.yearNavigate !== '1') return;
        event.preventDefault();
        apply(picker);
    });
    document.addEventListener('formdata', event => {
        event.target.querySelectorAll('[data-year-picker-select]').forEach(select => {
            if (select.value === '') event.formData.delete('year');
            if (select.value !== 'custom') {
                event.formData.delete('year_from');
                event.formData.delete('year_to');
            }
        });
    }, { capture: true });
}
