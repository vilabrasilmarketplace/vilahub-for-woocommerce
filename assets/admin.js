document.addEventListener('change', function (event) {
    if (!(event.target instanceof HTMLSelectElement)) { return; }
    const form = event.target.closest('form.vilahub-destination');
    if (form && event.target.name === 'connection_id' && event.target.value) {
        // GET preview only. Selecting a destination never submits publication.
        form.requestSubmit();
    }
});

document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-vilahub-category]');
    if (!button) return;
    const section = button.closest('section');
    const select = section && section.querySelector('select[name="category"]');
    if (!select) return;
    select.value = button.dataset.vilahubCategory;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.focus();
    section.querySelectorAll('[data-vilahub-category]').forEach(function (option) { option.setAttribute('aria-pressed', option === button ? 'true' : 'false'); });
});

document.addEventListener('change', function (event) {
    const select = event.target;
    if (!(select instanceof HTMLSelectElement) || select.name !== 'category') return;
    const name = select.closest('form')?.querySelector('input[name="category_name"]');
    if (name) name.value = select.selectedOptions[0]?.textContent || '';
});
