document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('posts-filter');
    const destination = document.getElementById('vilahub-destination');
    if (!form || !destination) return;
    const oldLabel = form.querySelector('label[for="vilahub-destination"]');
    if (oldLabel) oldLabel.remove();
    form.querySelectorAll('.bulkactions').forEach(function (bar, index) {
        const action = bar.querySelector('select[name="action"], select[name="action2"]');
        const apply = bar.querySelector('input[type="submit"], button[type="submit"]');
        if (!action || !apply) return;
        const select = index === 0 ? destination : destination.cloneNode(true);
        select.id = index === 0 ? 'vilahub-destination' : 'vilahub-destination-bottom';
        select.name = index === 0 ? 'vilahub_connection' : 'vilahub_connection_bottom';
        select.setAttribute('aria-label', 'Marketplace e conta de destino');
        bar.insertBefore(select, apply);
        function update() {
            const sending = action.value === 'vilahub_publish';
            select.hidden = !sending;
            select.disabled = !sending;
        }
        action.addEventListener('change', update);
        update();
        apply.addEventListener('click', function (event) {
            if (action.value !== 'vilahub_publish') return;
            form.querySelectorAll('.bulkactions select[name="action"], .bulkactions select[name="action2"]').forEach(function (other) {
                if (other !== action) { other.value = '-1'; other.dispatchEvent(new Event('change')); }
            });
            if (!select.value) {
                event.preventDefault();
                window.alert('Escolha o marketplace e a conta que receberão os produtos.');
                select.focus();
            } else if (!form.querySelector('input[name="post[]"]:checked')) {
                event.preventDefault();
                window.alert('Selecione os produtos que deseja enviar.');
            }
        });
    });
});
