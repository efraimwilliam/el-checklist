// Halaman checklist: setiap pilihan tersimpan otomatis ke server (tanpa tombol simpan), tampilan kartu / daftar,
// catatan otomatis untuk Ongoing / Pending, progres + skor sementara, dan tugas tambahan.
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
        finish: root.querySelector('[data-finish]'),
    };

    const statusOf = (item) => item.querySelector('input[type=radio]:checked')?.value ?? null;
    const noteInput = (item) => item.querySelector('[data-note-input]');
    const itemUrl = (id) => root.dataset.itemUrl.replace('__ITEM__', id);

    // ---- Tampilan: kartu (satu tugas per layar, geser kiri / kanan) atau daftar, diingat di perangkat ini ----
    const toggles = [...root.querySelectorAll('[data-view-toggle]')];
    const setView = (view) => {
        root.dataset.view = view;
        toggles.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewToggle === view)));
        try {
            localStorage.setItem('checklist-view', view);
        } catch {
            /* penyimpanan browser tidak tersedia: tampilan tetap berganti, hanya tidak diingat */
        }
        clearTimeout(scrollTimer); // gulir sisa dari tampilan sebelumnya tidak boleh mengubah kartu aktif
        if (view === 'card') requestAnimationFrame(() => goTo(current, false));
        else paintDeck();
    };
    toggles.forEach((button) => button.addEventListener('click', () => setView(button.dataset.viewToggle)));

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
            const isForm = body instanceof FormData;
            response = await fetch(url, {
                method,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(isForm ? {} : { 'Content-Type': 'application/json' }) },
                body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
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

    const photoRequirement = root.dataset.photoRequirement;
    const photoMax = Number(root.dataset.photoMax) || 3;

    function refresh() {
        let filled = 0;
        let done = 0;
        let missingReasons = 0;
        let missingPhotos = 0;
        for (const item of items) {
            const status = statusOf(item);
            const input = noteInput(item);
            const note = item.querySelector('[data-note]');
            item.dataset.status = status ?? '';
            if (status) filled++;
            if (status === 'done') done++;
            const needsReason = pendingNeedsNote && status === 'pending' && input.value.trim() === '';
            if (needsReason) missingReasons++;

            // Foto wajib menurut Pengaturan: semua tugas, atau hanya yang Pending.
            const photoCount = item.querySelectorAll('[data-photo]').length;
            const needsPhoto = photoCount === 0 && (photoRequirement === 'all' || (photoRequirement === 'pending' && status === 'pending'));
            if (needsPhoto) missingPhotos++;
            item.querySelector('[data-photo-hint]')?.classList.toggle('hidden', !needsPhoto);
            item.querySelectorAll('[data-photo-add]').forEach((control) => control.classList.toggle('hidden', photoCount >= photoMax));

            // Catatan muncul otomatis untuk Ongoing dan Pending; untuk Done hanya bila diminta atau sudah ada isinya.
            const show = status === 'ongoing' || status === 'pending' || input.value.trim() !== '' || note.dataset.open === '1';
            note.classList.toggle('hidden', !show);
            const noteToggle = item.querySelector('[data-note-toggle]');
            noteToggle?.setAttribute('aria-pressed', String(show));
            noteToggle?.classList.toggle('has-note', input.value.trim() !== '');
            item.querySelector('[data-note-hint]')?.classList.toggle('hidden', !needsReason);
            input.placeholder = status === 'pending' ? 'Alasan wajib diisi, mis. menunggu barang dari store' : 'Catatan (opsional)';
        }
        const percent = items.length ? Math.round((done / items.length) * 100) : 0;
        ui.progress.textContent = `${filled} / ${items.length}`;
        ui.score.textContent = `${percent}%`;
        ui.score.style.color = scoreColor(percent);
        ui.complete.classList.toggle('hidden', !(items.length && filled === items.length && missingReasons === 0 && missingPhotos === 0));
        // "Selesai" disembunyikan sampai skor sementara 100% (semua tugas Done).
        ui.finish.classList.toggle('hidden', !(items.length > 0 && percent === 100));
        paintDeck();
    }

    // ---- Tugas ----
    const sendItem = (item) => () => request('PATCH', itemUrl(item.dataset.id), { status: statusOf(item), note: noteInput(item).value });

    // Menekan pilihan yang sedang terpilih sekali lagi = pilihan dibatalkan (radio bawaan browser tidak bisa).
    const lastStatus = new Map(items.map((item) => [item, statusOf(item)]));

    root.addEventListener('click', (event) => {
        const radio = event.target.closest('[data-item] input[type=radio]');
        if (!radio) return;
        const item = radio.closest('[data-item]');
        if (lastStatus.get(item) === radio.value) {
            radio.checked = false;
            lastStatus.set(item, null);
            refresh();
            enqueue(`item:${item.dataset.id}`, sendItem(item));
        }
    });

    root.addEventListener('change', (event) => {
        const radio = event.target.closest('[data-item] input[type=radio]');
        if (radio) {
            const item = radio.closest('[data-item]');
            lastStatus.set(item, radio.value);
            refresh();
            enqueue(`item:${item.dataset.id}`, sendItem(item));
            // Done = tugas beres: kartu berikutnya terbuka sendiri, tanpa pilihan (Ongoing / Pending butuh catatan, jadi tidak pindah).
            if (isCard() && radio.value === 'done') {
                setTimeout(() => slides[current] === item && goTo(current + 1), 450);
            }
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
        const note = item.querySelector('[data-note]');
        const required = ['ongoing', 'pending'].includes(statusOf(item)) || noteInput(item).value.trim() !== '';
        if (note.classList.contains('hidden')) {
            note.dataset.open = '1';
            refresh();
        } else if (!required) {
            note.dataset.open = ''; // dibuka manual dan masih kosong: tombol Catatan menutupnya lagi
            refresh();
            return;
        }
        noteInput(item).focus();
    });

    // ---- Foto bukti per tugas ----
    // Dikecilkan di HP dulu (sisi terpanjang 1280 px, JPEG): upload cepat di Wi-Fi hotel, dan orientasi kamera ikut benar.
    async function compress(file) {
        let source;
        try {
            source = await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            source = await new Promise((resolve, reject) => {
                const image = new Image();
                image.onload = () => resolve(image);
                image.onerror = reject;
                image.src = URL.createObjectURL(file);
            });
        }
        const width = source.width || source.naturalWidth;
        const height = source.height || source.naturalHeight;
        const scale = Math.min(1, 1280 / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
        if (!blob) throw new SaveError('Foto tidak bisa diproses. Coba foto lain.');

        return blob;
    }

    function addThumb(item, photo) {
        const list = item.querySelector('[data-photo-list]');
        const thumb = document.createElement('div');
        thumb.className = 'cl-photo';
        thumb.dataset.photo = '';
        thumb.dataset.deleteUrl = photo.delete_url;
        thumb.innerHTML = '<a target="_blank" rel="noopener"><img loading="lazy" alt="Foto tugas"></a><button type="button" data-photo-remove aria-label="Hapus foto">✕</button>';
        thumb.querySelector('a').href = photo.url;
        thumb.querySelector('img').src = photo.url;
        list.append(thumb);
    }

    // Mengirim satu foto (sudah dikecilkan) ke server; mengembalikan true bila berhasil.
    async function postPhoto(item, blob) {
        inflight++;
        setState('saving', 'Mengunggah foto…');
        try {
            const form = new FormData();
            form.append('photo', blob, 'foto.jpg');
            const data = await request('POST', root.dataset.photoUrl.replace('__ITEM__', item.dataset.id), form);
            addThumb(item, data.photo);
            refresh();
            setState('saved', `Foto tersimpan · ${new Date().toTimeString().slice(0, 8)}`);

            return true;
        } catch (error) {
            setState('error', error.message || 'Foto gagal diunggah.');

            return false;
        } finally {
            inflight--;
        }
    }

    // Foto dari galeri atau kamera bawaan HP: dikecilkan dulu, lalu dikirim satu per satu.
    async function uploadPhotos(item, files) {
        const room = photoMax - item.querySelectorAll('[data-photo]').length;
        if (files.length > room) setState('error', `Maksimal ${photoMax} foto per tugas.`);
        for (const file of files.slice(0, Math.max(room, 0))) {
            try {
                await postPhoto(item, await compress(file));
            } catch (error) {
                setState('error', error.message || 'Foto tidak bisa diproses. Coba foto lain.');
            }
        }
    }

    // ---- Kamera di halaman ----
    // Pratinjau langsung lewat getUserMedia (butuh HTTPS, atau localhost). Bila tidak tersedia atau izin ditolak,
    // dipakai kamera bawaan HP lewat <input capture>, sehingga tombol Kamera selalu menghasilkan sesuatu.
    const camera = root.querySelector('[data-camera]');
    const video = camera.querySelector('[data-camera-video]');
    const cameraMessage = camera.querySelector('[data-camera-message]');
    const shutter = camera.querySelector('[data-camera-shoot]');
    let cameraItem = null;
    let cameraStream = null;
    let facing = 'environment';

    function stopCamera() {
        cameraStream?.getTracks().forEach((track) => track.stop());
        cameraStream = null;
        video.srcObject = null;
    }

    async function startCamera() {
        stopCamera();
        shutter.disabled = true;
        cameraMessage.textContent = 'Menyalakan kamera…';
        camera.dataset.facing = facing;
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } },
            audio: false,
        });
        video.srcObject = cameraStream;
        await video.play().catch(() => {});
        shutter.disabled = false;
        cameraMessage.textContent = `Foto ${cameraItem.querySelectorAll('[data-photo]').length} dari ${photoMax}`;
    }

    async function openCamera(item) {
        cameraItem = item;
        if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) return item.querySelector('[data-photo-native]').click();
        try {
            camera.showModal();
            await startCamera();
        } catch {
            camera.close();
            stopCamera();
            setState('error', 'Kamera di halaman tidak bisa dipakai (izin ditolak atau butuh HTTPS). Memakai kamera bawaan HP.');
            item.querySelector('[data-photo-native]').click();
        }
    }

    shutter.addEventListener('click', async () => {
        if (!cameraStream || !video.videoWidth) return;
        shutter.disabled = true;
        const scale = Math.min(1, 1280 / Math.max(video.videoWidth, video.videoHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        const taken = blob && (await postPhoto(cameraItem, blob));
        const count = cameraItem.querySelectorAll('[data-photo]').length;
        if (count >= photoMax) {
            camera.close();
        } else {
            shutter.disabled = false;
            cameraMessage.textContent = taken ? `Tersimpan. Foto ${count} dari ${photoMax}` : 'Foto gagal disimpan. Coba lagi.';
        }
    });

    camera.querySelector('[data-camera-flip]').addEventListener('click', async () => {
        facing = facing === 'environment' ? 'user' : 'environment';
        try {
            await startCamera();
        } catch {
            facing = facing === 'environment' ? 'user' : 'environment';
            cameraMessage.textContent = 'Kamera lain tidak tersedia.';
            await startCamera().catch(() => {});
        }
    });
    camera.querySelector('[data-camera-close]').addEventListener('click', () => camera.close());
    camera.addEventListener('close', stopCamera);
    // Ketuk di luar kotak kamera = tutup.
    camera.addEventListener('click', (event) => event.target === camera && camera.close());

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-photo-camera]');
        if (button) openCamera(button.closest('[data-item]'));
    });

    root.addEventListener('change', (event) => {
        const input = event.target.closest('[data-photo-input], [data-photo-native]');
        if (!input) return;
        const files = [...input.files];
        input.value = '';
        uploadPhotos(input.closest('[data-item]'), files);
    });

    root.addEventListener('click', async (event) => {
        const remove = event.target.closest('[data-photo-remove]');
        if (!remove || !confirm('Hapus foto ini?')) return;
        const thumb = remove.closest('[data-photo]');
        inflight++;
        setState('saving', 'Menghapus foto…');
        try {
            await request('DELETE', thumb.dataset.deleteUrl);
            thumb.remove();
            refresh();
            setState('saved', `Foto dihapus · ${new Date().toTimeString().slice(0, 8)}`);
        } catch (error) {
            setState('error', error.message);
        } finally {
            inflight--;
        }
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

    // ---- Dek kartu ----
    const deck = root.querySelector('[data-deck]');
    const slides = [...deck.querySelectorAll('[data-item], [data-final]')];
    const segments = root.querySelector('[data-segments]');
    const jump = root.querySelector('[data-jump]');
    const jumpList = root.querySelector('[data-jump-list]');
    const countLabel = root.querySelector('[data-deck-count]');
    const prev = root.querySelector('[data-prev]');
    const next = root.querySelector('[data-next]');
    const chips = [];
    let current = 0;
    const isCard = () => root.dataset.view === 'card';

    slides.forEach((slide, index) => {
        const final = slide.matches('[data-final]');
        const segment = document.createElement('button');
        segment.type = 'button';
        segment.setAttribute('aria-label', final ? 'Ringkasan' : `Tugas ${index + 1}`);
        segment.addEventListener('click', () => goTo(index));
        segments.append(segment);
        if (!final) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'cl-chip';
            chip.textContent = String(index + 1);
            chip.addEventListener('click', () => {
                jump.classList.add('hidden');
                jumpToggle.setAttribute('aria-expanded', 'false');
                goTo(index);
            });
            chips[index] = chip;
            jumpList.append(chip);
        }
    });

    function paintDeck() {
        slides.forEach((slide, index) => {
            const final = slide.matches('[data-final]');
            segments.children[index].dataset.status = final ? '' : slide.dataset.status ?? '';
            segments.children[index].setAttribute('aria-current', String(index === current));
            if (chips[index]) chips[index].dataset.status = slide.dataset.status ?? '';
        });
        countLabel.textContent = slides[current].matches('[data-final]') ? 'Ringkasan' : `Tugas ${current + 1} dari ${items.length}`;
        prev.setAttribute('aria-disabled', String(current === 0));
        next.setAttribute('aria-disabled', String(current === slides.length - 1));
        // Tinggi dek mengikuti kartu yang sedang terlihat (catatan, foto, dan tugas tambahan mengubah tingginya).
        deck.style.height = isCard() ? `${slides[current].offsetHeight}px` : '';
    }

    function goTo(index, smooth = true) {
        const target = Math.max(0, Math.min(slides.length - 1, index));
        // Pindah satu-dua kartu digulirkan halus; loncat jauh langsung sampai (gulir halus ke kartu ke-20 terlalu lama).
        const near = Math.abs(target - current) <= 2;
        current = target;
        const slide = slides[current];
        deck.scrollTo({ left: slide.offsetLeft - (deck.clientWidth - slide.offsetWidth) / 2, behavior: smooth && near ? 'smooth' : 'auto' });
        paintDeck();
    }

    // Digeser dengan jari: kartu yang paling dekat ke tengah menjadi kartu aktif setelah gulirannya berhenti.
    let scrollTimer;
    deck.addEventListener('scroll', () => {
        if (!isCard()) return;
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(() => {
            const center = deck.scrollLeft + deck.clientWidth / 2;
            let best = current;
            let distance = Infinity;
            slides.forEach((slide, index) => {
                const gap = Math.abs(slide.offsetLeft + slide.offsetWidth / 2 - center);
                if (gap < distance) {
                    distance = gap;
                    best = index;
                }
            });
            if (best !== current) {
                current = best;
                paintDeck();
            }
        }, 90);
    });

    prev.addEventListener('click', () => goTo(current - 1));
    next.addEventListener('click', () => goTo(current + 1));
    const jumpToggle = root.querySelector('[data-jump-toggle]');
    jumpToggle.addEventListener('click', () => {
        jump.classList.toggle('hidden');
        jumpToggle.setAttribute('aria-expanded', String(!jump.classList.contains('hidden')));
    });
    document.addEventListener('keydown', (event) => {
        if (!isCard() || event.target.closest?.('input, textarea, select, [contenteditable]')) return;
        if (event.key === 'ArrowRight') goTo(current + 1);
        else if (event.key === 'ArrowLeft') goTo(current - 1);
        else return;
        event.preventDefault();
    });
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(() => paintDeck());
        slides.forEach((slide) => observer.observe(slide));
    }
    window.addEventListener('resize', () => isCard() && goTo(current, false));

    refresh();

    // Mulai dari tugas pertama yang belum diisi (atau ringkasan bila semuanya sudah terisi).
    const firstEmpty = items.findIndex((item) => !statusOf(item));
    current = firstEmpty === -1 ? slides.length - 1 : firstEmpty;
    let remembered = null;
    try {
        remembered = localStorage.getItem('checklist-view');
    } catch {
        /* abaikan */
    }
    setView(remembered === 'list' ? 'list' : 'card');
}
