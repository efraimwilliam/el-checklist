// Form Chronology Report: baris tamu dan poin kronologi yang bisa ditambah, dihapus, dan digeser (poin bernomor mengikuti urutan layar).
// Nama kolom disusun ulang (guests[0][name], entries[2][body], ...) setiap ada perubahan, jadi urutan kirim = urutan di layar.

document.querySelectorAll('[data-repeater]').forEach((repeater) => {
    const list = repeater.querySelector('[data-repeater-list]');
    const template = repeater.querySelector('template');
    const min = Number(repeater.dataset.min || 1);

    const rows = () => [...list.querySelectorAll('[data-row]')];

    const reindex = () => {
        const all = rows();
        all.forEach((row, index) => {
            row.querySelectorAll('[data-name]').forEach((field) => { field.name = field.dataset.name.replace('{i}', index); });
            const number = row.querySelector('[data-row-number]');
            if (number) number.textContent = index + 1;
            const up = row.querySelector('[data-row-up]');
            const down = row.querySelector('[data-row-down]');
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === all.length - 1;
            const remove = row.querySelector('[data-row-remove]');
            if (remove) remove.disabled = all.length <= min;
        });
    };

    repeater.querySelector('[data-repeater-add]')?.addEventListener('click', () => {
        list.append(template.content.cloneNode(true));
        reindex();
        rows().at(-1)?.querySelector('input:not([type="hidden"]), textarea')?.focus();
    });

    list.addEventListener('click', (event) => {
        const row = event.target.closest('[data-row]');
        if (!row) return;
        if (event.target.closest('[data-row-remove]') && rows().length > min) row.remove();
        if (event.target.closest('[data-row-up]') && row.previousElementSibling) row.previousElementSibling.before(row);
        if (event.target.closest('[data-row-down]') && row.nextElementSibling) row.nextElementSibling.after(row);
        reindex();
    });

    reindex();
});
