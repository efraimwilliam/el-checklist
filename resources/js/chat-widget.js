// Tombol obrolan melayang (kanan bawah): membuka /obrolan di panel (iframe) tanpa meninggalkan halaman yang sedang dikerjakan.
// Komputer: jendela kecil di kanan bawah, halaman tetap bisa dipakai. HP: layar penuh, ditutup kembali ke halaman semula.
import './push.js';

const root = document.querySelector('[data-chat-widget]');

if (root) {
    const fab = root.querySelector('[data-chat-fab]');
    const badge = root.querySelector('[data-chat-fab-badge]');
    const panel = root.querySelector('[data-chat-panel]');
    const frame = root.querySelector('[data-chat-frame]');
    const loading = root.querySelector('[data-chat-loading]');
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

    const setBadge = (count) => {
        badge.hidden = !count;
        badge.textContent = count > 99 ? '99+' : String(count);
    };
    const isOpen = () => panel.dataset.open === '1';
    const refreshUnread = async () => {
        if (document.hidden || isOpen()) return;
        try {
            const response = await fetch(root.dataset.unreadUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.ok) setBadge((await response.json()).unread);
        } catch { /* dicoba lagi nanti */ }
    };

    // Layar penuh di HP: halaman di belakang dikunci (tidak ikut tergulir) dan panel mengikuti tinggi area terlihat
    // (papan ketik). Di layar lebar panel hanya jendela kecil dan halaman tetap bisa digulir.
    const phone = window.matchMedia('(max-width: 639px)');
    let lockedAt = null;
    const fitPanel = () => {
        const vv = window.visualViewport;
        if (!isOpen() || !phone.matches || !vv) { panel.style.top = ''; if (phone.matches) panel.style.height = ''; return; }
        panel.style.top = `${Math.round(vv.offsetTop)}px`;
        panel.style.height = `${Math.round(vv.height)}px`;
        panel.style.bottom = 'auto';
    };
    const backdrop = root.querySelector('[data-chat-backdrop]');
    const syncBackdrop = () => { if (backdrop) backdrop.hidden = !isOpen() || !phone.matches; };
    const lockPage = () => {
        if (lockedAt !== null || !phone.matches) return;
        lockedAt = window.scrollY;
        document.body.style.top = `-${lockedAt}px`;
        document.body.classList.add('chat-locked');
    };
    const unlockPage = () => {
        if (lockedAt === null) return;
        const y = lockedAt;
        lockedAt = null;
        document.body.classList.remove('chat-locked');
        document.body.style.top = '';
        window.scrollTo(0, y);
    };
    window.visualViewport?.addEventListener('resize', fitPanel);
    window.visualViewport?.addEventListener('scroll', fitPanel);
    phone.addEventListener('change', () => { if (!phone.matches) { unlockPage(); panel.style.top = ''; panel.style.bottom = ''; } else if (isOpen()) { lockPage(); } fitPanel(); syncBackdrop(); });

    // Obrolan dimuat sekali dan dibiarkan hidup di latar: menutup panel hanya menyembunyikannya (percakapan, gulir, dan tulisan yang
    // belum terkirim tetap ada), membukanya lagi seketika. Dimuat lebih awal begitu jari / kursor menyentuh tombolnya.
    const load = (url) => {
        const target = url || recall()?.url || root.dataset.chatUrl;
        if (!frame.getAttribute('src') || (url && url !== frameUrl())) {
            if (loading) loading.hidden = false;
            frame.src = target;
        }
    };
    ['pointerdown', 'touchstart', 'mouseenter', 'focus'].forEach((type) => fab.addEventListener(type, () => load(), { passive: true, once: true }));

    let closeTimer = null;
    const open = (url) => {
        clearTimeout(closeTimer);
        load(url);
        panel.hidden = false;
        void panel.offsetWidth; // mulai transisi masuk dari keadaan tertutup
        panel.dataset.open = '1';
        lockPage();
        fitPanel();
        syncBackdrop();
        fab.setAttribute('aria-expanded', 'true');
        fab.classList.add('max-sm:hidden');
        setPaused(false);
        remember({ open: true, url: frameUrl() || url || root.dataset.chatUrl });
    };
    const close = () => {
        if (!isOpen()) return;
        remember({ open: false, url: frameUrl() });
        setPaused(true);
        delete panel.dataset.open;
        unlockPage();
        fitPanel();
        syncBackdrop();
        fab.setAttribute('aria-expanded', 'false');
        fab.classList.remove('max-sm:hidden');
        closeTimer = setTimeout(() => { if (!isOpen()) panel.hidden = true; }, 260);
        fab.focus({ preventScroll: true });
        refreshUnread();
    };

    fab.addEventListener('click', () => (isOpen() ? close() : open(recall()?.url)));
    root.querySelectorAll('[data-chat-close]').forEach((button) => button.addEventListener('click', close));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && isOpen()) close(); });
    frame.addEventListener('load', () => {
        if (loading) loading.hidden = true;
        setPaused(!isOpen());
        if (isOpen()) remember({ open: true, url: frameUrl() });
    });

    // Naik di atas bilah Simpan / progres yang menempel di bawah halaman, supaya tombolnya tidak tertutup.
    // Panel (layar lebar) ikut naik supaya tidak tertutup tombolnya; di HP panel tetap layar penuh.
    const bar = document.querySelector('[data-bottom-bar]');
    if (bar) {
        const wide = window.matchMedia('(min-width: 640px)');
        const lift = () => {
            const bottom = Math.round(bar.getBoundingClientRect().height) + 16;
            fab.style.bottom = `${bottom}px`;
            panel.style.bottom = wide.matches ? `${bottom + 56 + 16}px` : '';
            panel.style.height = wide.matches ? `min(42rem, calc(100dvh - ${bottom + 56 + 16 + 24}px))` : '';
        };
        lift();
        new ResizeObserver(lift).observe(bar);
        wide.addEventListener('change', lift);
    }

    setInterval(refreshUnread, 30000);
    document.addEventListener('visibilitychange', refreshUnread);

    // Panel yang terbuka tetap terbuka (di obrolan yang sama) setelah pindah halaman.
    const saved = recall();
    if (saved?.open) open(saved.url);
}
