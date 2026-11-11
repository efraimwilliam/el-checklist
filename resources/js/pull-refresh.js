// Tarik ke bawah dari paling atas halaman = muat ulang aplikasi (layar sentuh saja). Menggantikan tarik-untuk-muat-ulang bawaan browser,
// yang tidak ada di aplikasi yang dipasang ke layar utama (iPhone) dan dimatikan lewat overscroll-behavior di app.css.
// Tidak aktif di: percakapan (gulir pesan), dialog, kolom isian, tumpukan kartu checklist (geser mendatar), dan daftar bergulir lain
// kecuali daftar obrolan, supaya tidak memuat ulang tanpa sengaja.
const touchOnly = window.matchMedia('(pointer: coarse)');
const THRESHOLD = 72; // jarak tarik (setelah diredam) yang memicu muat ulang
const MAX = 110;
const DAMPING = 0.5;
const t = (text) => (window.__i18n && window.__i18n[text]) || text;

let state = null;
let indicator = null;

const scrollerOf = (element) => {
    for (let node = element; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
        const style = getComputedStyle(node);
        if (/(auto|scroll)/.test(style.overflowY) && node.scrollHeight > node.clientHeight + 1) return node;
    }
    return null;
};

const canStart = (target) => {
    if (!touchOnly.matches || !(target instanceof Element)) return false;
    if (document.querySelector('[data-chat]')) return false; // halaman percakapan
    if (target.closest('dialog[open], input, textarea, select, [contenteditable], [data-no-pull], [data-deck], [data-log]')) return false;
    const scroller = scrollerOf(target);
    if (scroller) return scroller.matches('[data-thread-list]') && scroller.scrollTop <= 0;

    return (document.scrollingElement?.scrollTop ?? window.scrollY) <= 0;
};

const ensureIndicator = () => {
    if (indicator) return indicator;
    if (!document.getElementById('pull-refresh-style')) {
        const style = document.createElement('style');
        style.id = 'pull-refresh-style';
        style.textContent = '@keyframes pull-refresh-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
    }
    indicator = document.createElement('div');
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');
    indicator.style.cssText = 'position:fixed;left:50%;top:0;z-index:70;width:40px;height:40px;margin-left:-20px;display:grid;place-items:center;border-radius:9999px;'
        + 'background:#fff;color:#8a6a22;box-shadow:0 4px 14px rgb(0 0 0 / .2);pointer-events:none;opacity:0;transform:translateY(-48px);';
    indicator.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-arrow>'
        + '<path d="M12 5v14M5 12l7 7 7-7"/></svg>';
    document.body.appendChild(indicator);

    return indicator;
};

const show = (pull) => {
    const el = ensureIndicator();
    const ready = pull >= THRESHOLD;
    const inset = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--safe-top')) || 0;
    el.style.transition = 'none';
    el.style.opacity = String(Math.min(1, pull / 40));
    el.style.transform = `translateY(${pull - 48 + inset}px)`;
    el.style.background = ready ? '#b08a3e' : '#fff';
    el.style.color = ready ? '#fff' : '#8a6a22';
    const arrow = el.querySelector('[data-arrow]');
    arrow.style.transform = `rotate(${ready ? 180 : 0}deg)`;
    arrow.style.transition = 'transform .15s';
    el.setAttribute('aria-label', ready ? t('Lepas untuk memuat ulang') : t('Tarik untuk memuat ulang'));
};

const hide = () => {
    if (!indicator) return;
    indicator.style.transition = 'transform .2s ease-out, opacity .2s';
    indicator.style.opacity = '0';
    indicator.style.transform = 'translateY(-48px)';
};

const refresh = () => {
    const el = ensureIndicator();
    el.style.transition = 'transform .15s';
    el.style.opacity = '1';
    el.style.transform = 'translateY(16px)';
    el.style.background = '#b08a3e';
    el.style.color = '#fff';
    el.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" style="animation:pull-refresh-spin .8s linear infinite"><path d="M21 12a9 9 0 1 1-6.2-8.56"/></svg>';
    el.setAttribute('aria-label', t('Memuat ulang…'));
    setTimeout(() => window.location.reload(), 120);
};

document.addEventListener('touchstart', (event) => {
    state = event.touches.length === 1 && canStart(event.target)
        ? { x: event.touches[0].clientX, y: event.touches[0].clientY, pull: 0, active: false }
        : null;
}, { passive: true });

document.addEventListener('touchmove', (event) => {
    if (!state) return;
    if (event.touches.length !== 1) { state = null; hide(); return; }
    const dy = event.touches[0].clientY - state.y;
    const dx = event.touches[0].clientX - state.x;
    if (!state.active) {
        if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return; // arah belum jelas
        if (dy <= 0 || Math.abs(dx) > dy) { state = null; return; } // gulir ke atas / geser mendatar: bukan tarikan
        state.active = true;
    }
    state.pull = Math.min(MAX, dy * DAMPING);
    show(state.pull);
}, { passive: true });

const finish = () => {
    if (!state) return;
    const { active, pull } = state;
    state = null;
    if (!active) return;
    if (pull >= THRESHOLD) refresh();
    else hide();
};
document.addEventListener('touchend', finish);
document.addEventListener('touchcancel', () => { state = null; hide(); });
