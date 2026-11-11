// Tombol obrolan melayang (kanan bawah).
// - HP / layar sempit: membuka halaman Obrolan biasa (tanpa iframe). Kembali memakai riwayat browser, jadi halaman sebelumnya
//   dipulihkan dari cache browser (posisi gulir tetap, tanpa memuat ulang bila browser mendukung).
// - Komputer: jendela kecil di kanan bawah berisi /obrolan (iframe), halaman tetap bisa dipakai; menutupnya hanya menyembunyikan
//   (percakapan dan tulisan yang belum terkirim tetap).
import './push.js';

const root = document.querySelector('[data-chat-widget]');

// Halaman biasa dibuka: lupakan halaman asal obrolan sebelumnya (diisi lagi saat tombol obrolan ditekan).
try { sessionStorage.removeItem('chat-return'); } catch { /* penyimpanan sesi tidak tersedia */ }

if (root) {
    const fab = root.querySelector('[data-chat-fab]');
    const badge = root.querySelector('[data-chat-fab-badge]');
    const panel = root.querySelector('[data-chat-panel]');
    const frame = root.querySelector('[data-chat-frame]');
    const loading = root.querySelector('[data-chat-loading]');
    const narrow = window.matchMedia('(max-width: 639px)');
    const STORE = 'chat-widget';

    const remember = (value) => {
        try {
            if (value) sessionStorage.setItem(STORE, JSON.stringify(value));
            else sessionStorage.removeItem(STORE);
        } catch { /* penyimpanan sesi tidak tersedia */ }
    };
    const recall = () => {
        try { return JSON.parse(sessionStorage.getItem(STORE) || 'null'); } catch { return null; }
    };

    // Alamat yang sedang dibuka di panel (hanya halaman /obrolan di situs yang sama).
    const frameUrl = () => {
        try {
            const url = new URL(frame.contentWindow.location.href);
            return url.origin === location.origin && url.pathname.startsWith('/obrolan') ? url.href : null;
        } catch { return null; }
    };
    const setPaused = (value) => {
        try { frame.contentWindow.__chatPaused = value; } catch { /* panel belum dimuat */ }
    };

    const isOpen = () => panel.dataset.open === '1';
    const setBadge = (count) => {
        badge.hidden = !count;
        badge.textContent = count > 99 ? '99+' : String(count);
    };
    const refreshUnread = async () => {
        if (document.hidden || isOpen()) return;
        try {
            const response = await fetch(root.dataset.unreadUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.ok) setBadge((await response.json()).unread);
        } catch { /* dicoba lagi nanti */ }
    };

    // ---- Komputer: panel jendela kecil ----
    const load = (url) => {
        const target = url || recall()?.url || root.dataset.chatUrl;
        if (!frame.getAttribute('src') || (url && url !== frameUrl())) {
            if (loading) loading.hidden = false;
            frame.src = target;
        }
    };

    let closeTimer = null;
    const open = (url) => {
        clearTimeout(closeTimer);
        load(url);
        panel.hidden = false;
        void panel.offsetWidth; // mulai transisi masuk dari keadaan tertutup
        panel.dataset.open = '1';
        fab.setAttribute('aria-expanded', 'true');
        setPaused(false);
        remember({ open: true, url: frameUrl() || url || root.dataset.chatUrl });
    };
    const close = () => {
        if (!isOpen()) return;
        remember({ open: false, url: frameUrl() });
        setPaused(true);
        delete panel.dataset.open;
        fab.setAttribute('aria-expanded', 'false');
        closeTimer = setTimeout(() => { if (!isOpen()) panel.hidden = true; }, 260);
        fab.focus({ preventScroll: true });
        refreshUnread();
    };

    fab.addEventListener('click', () => {
        // HP: halaman Obrolan biasa. Gulir, papan ketik, dan tombol kembali bekerja seperti halaman lain.
        if (narrow.matches) {
            try { sessionStorage.setItem('chat-return', window.location.href); } catch { /* tanpa tombol kembali */ }
            window.location.href = root.dataset.chatUrl;
            return;
        }
        if (isOpen()) close(); else open(recall()?.url);
    });
    // Komputer: mulai memuat saat kursor mendekati tombol supaya panel langsung siap.
    ['mouseenter', 'focus'].forEach((type) => fab.addEventListener(type, () => { if (!narrow.matches) load(); }, { once: true }));
    root.querySelectorAll('[data-chat-close]').forEach((button) => button.addEventListener('click', close));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && isOpen()) close(); });
    frame.addEventListener('load', () => {
        if (loading) loading.hidden = true;
        setPaused(!isOpen());
        if (isOpen()) remember({ open: true, url: frameUrl() });
    });
    // Layar diperkecil sampai ukuran HP saat panel terbuka: tutup panel (HP memakai halaman Obrolan).
    narrow.addEventListener('change', () => { if (narrow.matches) close(); });

    // Naik di atas bilah Simpan / progres yang menempel di bawah halaman, supaya tombolnya tidak tertutup.
    const bar = document.querySelector('[data-bottom-bar]');
    if (bar) {
        const lift = () => {
            const bottom = Math.round(bar.getBoundingClientRect().height) + 16;
            fab.style.bottom = `${bottom}px`;
            panel.style.bottom = `${bottom + 56 + 16}px`;
            panel.style.height = `min(42rem, calc(100dvh - ${bottom + 56 + 16 + 24}px))`;
        };
        lift();
        new ResizeObserver(lift).observe(bar);
    }

    setInterval(refreshUnread, 30000);
    document.addEventListener('visibilitychange', refreshUnread);
    // Kembali ke halaman ini dari cache browser (tombol kembali): perbarui angka belum dibaca.
    window.addEventListener('pageshow', (event) => { if (event.persisted) refreshUnread(); });

    // Komputer: panel yang terbuka tetap terbuka (di obrolan yang sama) setelah pindah halaman.
    const saved = recall();
    if (saved?.open && !narrow.matches) open(saved.url);
}
