// Service worker Checklist: hanya untuk notifikasi obrolan (Web Push). Tidak menyimpan halaman (tanpa cache offline),
// jadi data selalu dari server dan sesi login tetap berlaku. Berkas ini harus ada di akar situs agar cakupannya seluruh aplikasi.
const VERSION = 'push-1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// Pesan push dari server: { title, body, url, tag, mention }.
// Browser (terutama iOS) mewajibkan setiap push menampilkan notifikasi, jadi selalu ditampilkan; server sudah tidak mengirim
// kalau penerima sedang membuka obrolan itu.
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { data = { body: event.data ? event.data.text() : '' }; }

    const title = data.title || 'Obrolan';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/images/icon-192.png',
        badge: '/images/badge-96.png',
        tag: data.tag || 'chat',
        renotify: true,
        data: { url: data.url || '/obrolan' },
    }));
});

// Ketuk notifikasi: buka (atau fokuskan) aplikasi di obrolan yang dimaksud. Bila sesi habis, halaman login muncul lebih dulu
// lalu kembali ke alamat ini.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/obrolan', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin !== self.location.origin) continue;
            await client.focus();
            if ('navigate' in client) { try { await client.navigate(target); } catch { /* halaman lain: buka jendela baru */ } }
            return;
        }
        await self.clients.openWindow(target);
    })());
});
