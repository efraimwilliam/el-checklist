// Halaman checklist: setiap pilihan tersimpan otomatis ke server (tanpa tombol simpan), tampilan kartu / daftar,
// catatan otomatis untuk Ongoing / Pending, progres + skor sementara, "Sisa tugas = Done", "Kosongkan" dan tugas tambahan.
// Server tetap menjadi sumber kebenaran: setiap permintaan mengirim keadaan terbaru dari layar, dan gagal simpan ditampilkan jelas.
const root = document.querySelector('[data-checklist-root]');

class SaveError extends Error {
    constructor(message, fatal = false) {
        super(message);
        this.fatal = fatal;
    }
}

if (root) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const items = [...root.querySelectorAll('[data-item]')];
    const pendingNeedsNote = root.dataset.pendingNote === '1';
    const ui = {
        progress: root.querySelector('[data-progress]'),
        score: root.querySelector('[data-score]'),
        complete: root.querySelector('[data-complete]'),
        state: root.querySelector('[data-save-state]'),
        retry: root.querySelector('[data-retry]'),
    };

    const statusOf = (item) => item.querySelector('input[type=radio]:checked')?.value ?? null;
    const noteInput = (item) => item.querySelector('[data-note-input]');
    const itemUrl = (id) => root.dataset.itemUrl.replace('__ITEM__', id);

    // ---- Tampilan: kartu atau daftar, diingat di perangkat ini ----
    const toggles = [...root.querySelectorAll('[data-view-toggle]')];
    const setView = (view) => {
        root.dataset.view = view;
        toggles.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewToggle === view)));
        try {
            localStorage.setItem('checklist-view', view);
        } catch {
            /* penyimpanan browser tidak tersedia: tampilan tetap berganti, hanya tidak diingat */
        }
    };
    toggles.forEach((button) => button.addEventListener('click', () => setView(button.dataset.viewToggle)));
    let remembered = null;
    try {
        remembered = localStorage.getItem('checklist-view');
    } catch {
        /* abaikan */
    }
    setView(remembered === 'list' ? 'list' : 'card');

    // ---- Penyimpanan otomatis ----
    // Setiap kunci (satu tugas, atau "meta" = catatan umum + dicek oleh + tugas tambahan) punya antrean sendiri sehingga urutannya terjaga;
    // fungsi kirim membaca keadaan terbaru dari layar, jadi beberapa perubahan beruntun cukup satu permintaan.
    const chains = new Map();
    const senders = new Map();
    const dirty = new Set();
    const timers = new Map();
    let inflight = 0;

    const setState = (state, text) => {
        ui.state.dataset.state = state;
        ui.state.textContent = text;
        ui.retry.classList.toggle('hidden', state !== 'error');
    };

    async function request(method, url, body) {
        let response;
        try {
            response = await fetch(url, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            throw new SaveError('Tidak bisa terhubung. Periksa koneksi, lalu coba lagi.');
        }
        if (response.status === 401 || response.status === 419) throw new SaveError('Sesi berakhir. Muat ulang halaman dan login lagi.', true);
        if (response.status === 403) throw new SaveError('Checklist ini terkunci atau Anda tidak boleh mengubahnya.', true);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new SaveError(data.message ?? 'Gagal menyimpan.');
        return data;
    }

    function enqueue(key, send) {
        senders.set(key, send);
        dirty.add(key);
        chains.set(
            key,
            (chains.get(key) ?? Promise.resolve()).then(async () => {
                if (!dirty.has(key)) return; // sudah terkirim oleh antrean sebelumnya
                dirty.delete(key);
                inflight++;
                setState('saving', 'Menyimpan…');
                try {
                    const data = await send();
                    inflight--;
                    if (!inflight && !dirty.size && !timers.size) setState('saved', `Tersimpan otomatis · ${data.saved_at}`);
                } catch (error) {
                    inflight--;
                    dirty.add(key);
                    setState('error', error.message);
                }
            }),
        );
    }

    // Tunda sebentar untuk ketikan (catatan), kirim segera saat berpindah isian.
    function schedule(key, send, delay = 700) {
        senders.set(key, send);
        dirty.add(key);
        clearTimeout(timers.get(key));
        timers.set(
            key,
            setTimeout(() => {
                timers.delete(key);
                enqueue(key, send);
            }, delay),
        );
        setState('saving', 'Menyimpan…');
    }

    function flush(key) {
        if (!timers.has(key)) return;
        clearTimeout(timers.get(key));
        timers.delete(key);
        enqueue(key, senders.get(key));
    }

    const flushAll = () => [...timers.keys()].forEach(flush);
    const settle = () => Promise.all([...chains.values()]);

    ui.retry.addEventListener('click', () => [...dirty].forEach((key) => enqueue(key, senders.get(key))));
    window.addEventListener('beforeunload', (event) => {
        flushAll();
        if (inflight || dirty.size) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flushAll());

    // ---- Tampilan tugas, progres dan skor ----
    const scoreColor = (percent) => (percent >= 90 ? '#8fd3a7' : percent >= 70 ? '#e2c47c' : '#f0a090');

    function refresh() {
        let filled = 0;
        let done = 0;
        let missingReasons = 0;
        for (const item of items) {
            const status = statusOf(item);
            const input = noteInput(item);
            const note = item.querySelector('[data-note]');
            item.dataset.status = status ?? '';
            if (status) filled++;
            if (status === 'done') done++;
            const needsReason = pendingNeedsNote && status === 'pending' && input.value.trim() === '';
            if (needsReason) missingReasons++;

            // Catatan muncul otomatis untuk Ongoing dan Pending; untuk Done hanya bila diminta atau sudah ada isinya.
            const show = status === 'ongoing' || status === 'pending' || input.value.trim() !== '' || note.dataset.open === '1';
            note.classList.toggle('hidden', !show);
            item.querySelector('[data-note-toggle]')?.classList.toggle('hidden', show);
            item.querySelector('[data-note-hint]')?.classList.toggle('hidden', !needsReason);
            input.placeholder = status === 'pending' ? 'Alasan wajib diisi, mis. menunggu barang dari store' : 'Catatan (opsional)';
        }
        const percent = items.length ? Math.round((done / items.length) * 100) : 0;
        ui.progress.textContent = `${filled} / ${items.length}`;
        ui.score.textContent = `${percent}%`;
        ui.score.style.color = scoreColor(percent);
        ui.complete.classList.toggle('hidden', !(items.length && filled === items.length && missingReasons === 0));
    }

    // Keadaan dari server (setelah "Sisa tugas = Done" / "Kosongkan") dipasang ke layar.
    function applyItems(states) {
        for (const item of items) {
            const state = states[item.dataset.id];
            if (!state) continue;
            item.querySelectorAll('input[type=radio]').forEach((radio) => (radio.checked = radio.value === state.status));
            noteInput(item).value = state.note ?? '';
        }
        refresh();
    }

    async function bulk(url) {
        flushAll();
        await settle();
        inflight++;
        setState('saving', 'Menyimpan…');
        try {
            const data = await request('POST', url);
            applyItems(data.items);
            setState('saved', `Tersimpan otomatis · ${data.saved_at}`);
        } catch (error) {
            setState('error', error.message);
        } finally {
            inflight--;
        }
    }

    // ---- Tugas ----
    const sendItem = (item) => () => request('PATCH', itemUrl(item.dataset.id), { status: statusOf(item), note: noteInput(item).value });

    root.addEventListener('change', (event) => {
        const radio = event.target.closest('[data-item] input[type=radio]');
        if (radio) {
            const item = radio.closest('[data-item]');
            refresh();
            enqueue(`item:${item.dataset.id}`, sendItem(item));
        } else if (event.target.matches('[data-extra-status]')) {
            enqueue('meta', sendMeta);
        }
    });

    root.addEventListener('input', (event) => {
        const input = event.target.closest('[data-note-input]');
        if (input) {
            const item = input.closest('[data-item]');
            refresh();
            schedule(`item:${item.dataset.id}`, sendItem(item));
        } else if (event.target.matches('[data-meta], [data-extra-title], [data-extra-note]')) {
            schedule('meta', sendMeta, 900);
        }
    });

    // Berpindah dari kolom catatan = kirim sekarang, tidak menunggu jeda ketikan.
    root.addEventListener('focusout', (event) => {
        const input = event.target.closest('[data-note-input]');
        if (input) flush(`item:${input.closest('[data-item]').dataset.id}`);
        else if (event.target.matches('[data-meta], [data-extra-title], [data-extra-note]')) flush('meta');
    });

    root.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-note-toggle]');
        if (!toggle) return;
        const item = toggle.closest('[data-item]');
        item.querySelector('[data-note]').dataset.open = '1';
        refresh();
        noteInput(item).focus();
    });

    root.querySelector('[data-rest-done]')?.addEventListener('click', () => bulk(root.dataset.restDoneUrl));
    root.querySelector('[data-clear]')?.addEventListener('click', () => {
        if (confirm('Kosongkan semua status dan catatan tugas di checklist ini?')) bulk(root.dataset.clearUrl);
    });

    // ---- Catatan umum, dicek oleh dan tugas tambahan ----
    const extras = root.querySelector('[data-extras]');
    const extraList = extras.querySelector('[data-extra-list]');
    const extraTemplate = extras.querySelector('[data-extra-template]');

    function sendMeta() {
        const body = {
            extras: [...extraList.querySelectorAll('[data-extra-row]')].map((row) => ({
                title: row.querySelector('[data-extra-title]').value,
                status: row.querySelector('[data-extra-status]').value,
                note: row.querySelector('[data-extra-note]').value,
            })),
        };
        for (const field of root.querySelectorAll('[data-meta]')) body[field.dataset.meta] = field.value;

        return request('PATCH', root.dataset.metaUrl, body);
    }

    extras.querySelector('[data-extra-add]').addEventListener('click', () => {
        extraList.insertAdjacentHTML('beforeend', extraTemplate.innerHTML);
        extraList.lastElementChild.querySelector('input').focus();
    });
    extraList.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-extra-remove]');
        if (!remove) return;
        remove.closest('[data-extra-row]').remove();
        enqueue('meta', sendMeta);
    });

    refresh();
}
