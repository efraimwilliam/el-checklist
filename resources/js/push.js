// Notifikasi obrolan (Web Push) di sisi browser: tombol bel + banner ajakan di daftar obrolan, dan membersihkan langganan saat Keluar.
// Konfigurasi dari server ada di window.__push (hanya bila obrolan menyala). Dimuat oleh chat.js dan chat-widget.js.
const config = window.__push;
const t = (text) => (window.__i18n && window.__i18n[text]) || text;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const standalone = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
const DISMISS_KEY = 'push-banner-dismissed';

const api = (method, url, body) => fetch(url, {
    method, credentials: 'same-origin', keepalive: true,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify(body),
});

const keyBytes = (base64Url) => {
    const padded = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
};

const sameKey = (subscription, bytes) => {
    const current = subscription.options?.applicationServerKey;
    if (!current) return true;
    const a = new Uint8Array(current);
    return a.length === bytes.length && a.every((value, index) => value === bytes[index]);
};

const registration = async () => (await navigator.serviceWorker.getRegistration('/')) || navigator.serviceWorker.ready;
const currentSubscription = async () => (await registration()).pushManager.getSubscription();

/** off | on | denied | ios-install | unsupported */
const stateNow = async () => {
    if (!supported) return isIos && !standalone ? 'ios-install' : 'unsupported';
    if (Notification.permission === 'denied') return 'denied';
    if (Notification.permission !== 'granted') return 'off';
    try {
        const subscription = await currentSubscription();
        if (!subscription) return 'off';
        // Perangkat ini mungkin masih terdaftar untuk pengguna lain (HP bersama): hanya "menyala" bila milik pengguna yang login.
        const response = await api('POST', config.status, { endpoint: subscription.endpoint });
        return response.ok && (await response.json()).subscribed ? 'on' : 'off';
    } catch {
        return 'off';
    }
};

const enable = async () => {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error(permission === 'denied' ? 'denied' : 'dismissed');
    const reg = await registration();
    const bytes = keyBytes(config.vapid);
    let subscription = await reg.pushManager.getSubscription();
    if (subscription && !sameKey(subscription, bytes)) {
        await subscription.unsubscribe(); // kunci server berganti: langganan lama tidak berlaku
        subscription = null;
    }
    subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes });
    const json = subscription.toJSON();
    const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
    const response = await api('POST', config.subscribe, {
        endpoint: json.endpoint,
        keys: json.keys,
        contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
    });
    if (!response.ok) throw new Error('server');
};

const disable = async () => {
    const subscription = await currentSubscription();
    if (!subscription) return;
    await api('DELETE', config.unsubscribe, { endpoint: subscription.endpoint }).catch(() => {});
    await subscription.unsubscribe();
};

if (config) {
    const toggle = document.querySelector('[data-push-toggle]');
    const banner = document.querySelector('[data-push-banner]');
    const note = document.querySelector('[data-list-note]');
    const say = (text) => {
        if (!note) return;
        note.textContent = text;
        note.hidden = false;
        clearTimeout(say.timer);
        say.timer = setTimeout(() => { note.hidden = true; }, 6000);
    };
    const dismissed = () => { try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch { return false; } };

    let state = 'unsupported';
    const render = () => {
        if (toggle) {
            toggle.hidden = state === 'unsupported';
            toggle.dataset.state = state;
            const label = {
                on: t('Notifikasi menyala. Ketuk untuk mematikan.'),
                off: t('Nyalakan notifikasi'),
                denied: t('Notifikasi diblokir di pengaturan browser'),
                'ios-install': t('Nyalakan notifikasi'),
            }[state] || '';
            toggle.title = label;
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('aria-pressed', String(state === 'on'));
        }
        if (banner) banner.hidden = !(state === 'off' && !dismissed());
    };
    const refresh = async () => { state = await stateNow(); render(); };

    const turnOn = async () => {
        if (state === 'ios-install') {
            say(t('Di iPhone, pasang aplikasi ke Layar Utama dulu (Bagikan → Tambah ke Layar Utama), lalu buka dari ikon itu dan nyalakan notifikasi.'));
            return;
        }
        if (state === 'denied') {
            say(t('Notifikasi diblokir. Izinkan lewat pengaturan situs di browser, lalu coba lagi.'));
            return;
        }
        try {
            await enable();
            say(t('Notifikasi menyala di perangkat ini.'));
        } catch (error) {
            if (error.message === 'denied') say(t('Notifikasi diblokir. Izinkan lewat pengaturan situs di browser, lalu coba lagi.'));
            else if (error.message !== 'dismissed') say(t('Notifikasi belum bisa dinyalakan. Coba lagi.'));
        }
        await refresh();
    };

    toggle?.addEventListener('click', async () => {
        if (state === 'on') {
            await disable().catch(() => {});
            say(t('Notifikasi dimatikan di perangkat ini.'));
            await refresh();
        } else {
            await turnOn();
        }
    });
    banner?.querySelector('[data-push-enable]').addEventListener('click', turnOn);
    banner?.querySelector('[data-push-later]').addEventListener('click', () => {
        try { localStorage.setItem(DISMISS_KEY, '1'); } catch { /* penyimpanan tidak tersedia */ }
        banner.hidden = true;
    });

    if (toggle || banner) {
        refresh();
        // Kembali dari pengaturan browser (izin diubah) atau dari tab lain: keadaan bel diperbarui.
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    }

    // Keluar: lepas perangkat ini dari notifikasi dulu (HP bersama tidak menerima pesan pengguna sebelumnya), lalu keluar seperti biasa.
    let cleaned = false;
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (cleaned || !supported || !(form instanceof HTMLFormElement) || !/\/logout$/.test(new URL(form.action, location.href).pathname)) return;
        event.preventDefault();
        const finish = () => { cleaned = true; form.submit(); };
        Promise.race([disable().catch(() => {}), new Promise((resolve) => setTimeout(resolve, 2000))]).finally(finish);
    }, true);
}
