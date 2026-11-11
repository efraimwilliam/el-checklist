// Tombol obrolan melayang (kanan bawah): membuka /obrolan di panel (iframe) tanpa meninggalkan halaman yang sedang dikerjakan.
// Komputer: jendela kecil di kanan bawah, halaman tetap bisa dipakai. HP: layar penuh, ditutup kembali ke halaman semula.
import './push.js';

const root = document.querySelector('[data-chat-widget]');

if (root) {
    const fab = root.querySelector('[data-chat-fab]');
    const badge = root.querySelector('[data-chat-fab-badge]');
    const panel = root.querySelector('[data-chat-panel]');
    const frame = root.querySelector('[data-chat-frame]');
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
    const refreshUnread = async () => {
        if (document.hidden || !panel.hidden) return;
        try {
            const response = await fetch(root.dataset.unreadUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.ok) setBadge((await response.json()).unread);
        } catch { /* dicoba lagi nanti */ }
    };

    const open = (url) => {
        if (!frame.src || (url && url !== frameUrl())) frame.src = url || root.dataset.chatUrl;
        panel.hidden = false;
        fab.setAttribute('aria-expanded', 'true');
        fab.classList.add('max-sm:hidden');
        setPaused(false);
        remember({ open: true, url: frameUrl() || url || root.dataset.chatUrl });
    };
    const close = () => {
        remember({ open: false, url: frameUrl() });
        setPaused(true);
        panel.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
        fab.classList.remove('max-sm:hidden');
        fab.focus();
        refreshUnread();
    };

    fab.addEventListener('click', () => (panel.hidden ? open(recall()?.url) : close()));
    root.querySelector('[data-chat-close]').addEventListener('click', close);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !panel.hidden) close(); });
    frame.addEventListener('load', () => {
        setPaused(panel.hidden);
        if (!panel.hidden) remember({ open: true, url: frameUrl() });
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
