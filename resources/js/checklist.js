// Form checklist: status per tugas, catatan otomatis, progres & skor sementara, "Sisa tugas = Done" dan "Kosongkan".
// Semua isian tetap dikirim lewat form biasa; skrip ini hanya merapikan tampilan. Validasi sebenarnya di server.
const form = document.querySelector('[data-checklist-form]');

if (form) {
    const items = [...form.querySelectorAll('[data-item]')];
    const progress = document.querySelector('[data-progress]');
    const score = document.querySelector('[data-score]');
    const problem = document.querySelector('[data-problem]');
    const pendingNeedsNote = form.dataset.pendingNote === '1';

    const statusOf = (item) => item.querySelector('input[type=radio]:checked')?.value ?? null;
    const noteOf = (item) => item.querySelector('[data-note] input');

    // Skor: 90% ke atas baik, 70-89% perlu perhatian, di bawah 70% perlu tindakan.
    const scoreColor = (percent) => (percent >= 90 ? '#8fd3a7' : percent >= 70 ? '#e2c47c' : '#f0a090');

    const refresh = () => {
        let filled = 0;
        let done = 0;
        for (const item of items) {
            const status = statusOf(item);
            const note = item.querySelector('[data-note]');
            const input = noteOf(item);
            if (status) filled++;
            if (status === 'done') done++;

            item.querySelector('[data-num]').classList.toggle('text-rose-600', !status);
            item.classList.remove('ring-2', 'ring-rose-300');

            // Catatan muncul otomatis untuk Ongoing dan Pending; untuk Done hanya bila diminta atau sudah ada isinya.
            const show = status === 'ongoing' || status === 'pending' || input.value.trim() !== '' || note.dataset.open === '1';
            note.classList.toggle('hidden', !show);
            item.querySelector('[data-note-toggle]')?.classList.toggle('hidden', show);
            input.placeholder = status === 'pending' ? 'Alasan wajib diisi, mis. menunggu barang dari store' : 'Catatan (opsional)';
        }
        const percent = items.length ? Math.round((done / items.length) * 100) : 0;
        progress.textContent = `${filled} / ${items.length}`;
        score.textContent = `${percent}%`;
        score.style.color = scoreColor(percent);
    };

    const flag = (item, message) => {
        item.scrollIntoView({ behavior: 'smooth', block: 'center' });
        item.classList.add('ring-2', 'ring-rose-300');
        problem.textContent = message;
        problem.classList.remove('hidden');
    };

    form.addEventListener('change', refresh);
    form.addEventListener('input', (event) => {
        if (event.target.matches('[data-note] input')) refresh();
    });

    form.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-note-toggle]');
        if (toggle) {
            const item = toggle.closest('[data-item]');
            item.querySelector('[data-note]').dataset.open = '1';
            refresh();
            noteOf(item).focus();
        }
    });

    document.querySelector('[data-fill-done]')?.addEventListener('click', () => {
        for (const item of items) {
            if (!statusOf(item)) item.querySelector('input[value=done]').checked = true;
        }
        refresh();
    });

    document.querySelector('[data-clear]')?.addEventListener('click', () => {
        for (const item of items) {
            item.querySelectorAll('input[type=radio]').forEach((radio) => (radio.checked = false));
        }
        refresh();
    });

    // Simpan: tunjukkan tugas pertama yang belum lengkap sebelum ke server (checklist hanya bisa disimpan lengkap).
    form.addEventListener('submit', (event) => {
        for (const [index, item] of items.entries()) {
            const status = statusOf(item);
            if (!status) {
                event.preventDefault();
                return flag(item, `Tugas ${index + 1} belum diberi status.`);
            }
            if (status === 'pending' && pendingNeedsNote && noteOf(item).value.trim() === '') {
                event.preventDefault();
                return flag(item, `Tulis alasan untuk tugas ${index + 1} yang Pending.`);
            }
        }
    });

    // Tugas tambahan: baris bisa ditambah dan dihapus.
    const extras = form.querySelector('[data-extras]');
    const list = extras.querySelector('[data-extra-list]');
    const template = extras.querySelector('[data-extra-template]');
    let nextIndex = list.querySelectorAll('[data-extra-row]').length;
    extras.querySelector('[data-extra-add]').addEventListener('click', () => {
        list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(nextIndex++)));
        list.lastElementChild.querySelector('input').focus();
    });
    list.addEventListener('click', (event) => event.target.closest('[data-extra-remove]')?.closest('[data-extra-row]').remove());

    refresh();
}
