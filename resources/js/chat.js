import './push.js';
// Obrolan ala WhatsApp: daftar obrolan + panel Chat baru / Grup baru (kiri) dan percakapan (kanan).
// Tanpa WebSocket: percakapan menanyakan pesan baru tiap 3 detik, daftar tiap 8 detik; berhenti saat tab tersembunyi.
const t = (text) => (window.__i18n && window.__i18n[text]) || text;
const inFrame = window.self !== window.top;
// Panel tombol melayang ditutup = jangan memanggil server (window.__chatPaused diatur oleh chat-widget.js di halaman induk).
const paused = () => document.hidden || window.__chatPaused === true;

// HP: saat papan ketik muncul, tinggi halaman mengikuti area yang benar-benar terlihat (visualViewport) dan halaman dijaga di
// posisi 0, supaya kotak tulis tidak terdorong keluar layar dan halaman tidak ikut tergeser.
if (!inFrame && document.body.classList.contains('chat-fixed') && window.visualViewport) {
    const phone = window.matchMedia('(max-width: 767px)');
    const fit = () => {
        if (!phone.matches) { document.body.style.height = ''; return; }
        document.body.style.height = `${Math.round(window.visualViewport.height)}px`;
        if (window.scrollY !== 0) window.scrollTo(0, 0);
    };
    window.visualViewport.addEventListener('resize', fit);
    window.visualViewport.addEventListener('scroll', fit);
    phone.addEventListener('change', fit);
    fit();
}

// Di dalam panel: tautan ke luar obrolan (kendala, checklist, laporan) dibuka di halaman induk, bukan di dalam panel.
if (inFrame) {
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented) return;
        const link = event.target.closest('a[href]');
        if (!link || link.target || link.hasAttribute('download')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || url.pathname.startsWith('/obrolan')) return;
        event.preventDefault();
        window.top.location.href = link.href;
    });
}
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

const filterRows = (rows, term) => {
    let shown = 0;
    rows.forEach((row) => {
        const match = term === '' || row.dataset.name.includes(term);
        row.hidden = !match;
        if (match) shown += 1;
    });
    return shown;
};

// ---- Panel kiri -----------------------------------------------------------------------------------------------
const sidebar = document.querySelector('[data-sidebar]');
if (sidebar) {
    const list = sidebar.querySelector('[data-thread-list]');
    const search = sidebar.querySelector('[data-thread-search]');

    const applySearch = () => {
        const term = search.value.trim().toLowerCase();
        const shown = filterRows(list.querySelectorAll('[data-thread]'), term);
        list.querySelector('[data-thread-empty]')?.classList.toggle('hidden', shown > 0 || term === '');
    };
    search.addEventListener('input', applySearch);

    const panels = sidebar.querySelectorAll('[data-panel]');
    const open = (name) => panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== name && !(name === 'group' && panel.dataset.panel === 'new'); });
    sidebar.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-panel-open]');
        if (opener) {
            open(opener.dataset.panelOpen);
            sidebar.querySelector(`[data-panel="${opener.dataset.panelOpen}"] input[type=search], [data-panel="${opener.dataset.panelOpen}"] input[name=subject]`)?.focus();
            return;
        }
        const closer = event.target.closest('[data-panel-close]');
        if (closer) closer.closest('[data-panel]').hidden = true;
    });
    if (sidebar.querySelector('[data-panel][data-open-on-load]')) open('group');

    const contactSearch = sidebar.querySelector('[data-contact-search]');
    contactSearch?.addEventListener('input', () => filterRows(sidebar.querySelectorAll('[data-contact]'), contactSearch.value.trim().toLowerCase()));
    const memberSearch = sidebar.querySelector('[data-member-search]');
    memberSearch?.addEventListener('input', () => filterRows(sidebar.querySelectorAll('[data-member]'), memberSearch.value.trim().toLowerCase()));

    const memberCount = sidebar.querySelector('[data-member-count]');
    const countMembers = () => {
        const n = sidebar.querySelectorAll('[data-member] input:checked').length;
        if (memberCount) memberCount.textContent = n ? `${n} ${t('anggota dipilih')}` : t('Belum ada anggota dipilih');
    };
    sidebar.addEventListener('change', (event) => { if (event.target.closest('[data-member]')) countMembers(); });
    countMembers();

    // ---- Urutkan sematan: tombol ▲ ▼ (HP dan komputer) dan seret (komputer) ----
    let reordering = false;
    let dragged = null;
    const pinRows = () => [...list.querySelectorAll('[data-pinned-row]')];
    const savePinOrder = () => fetch(sidebar.dataset.reorderUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ ids: pinRows().map((row) => Number(row.dataset.pinnedRow)) }),
    }).catch(() => {});
    const setReordering = (on) => {
        reordering = on;
        list.querySelectorAll('[data-reorder-controls]').forEach((controls) => { controls.hidden = !on; });
        pinRows().forEach((row) => { row.draggable = on; });
        const toggle = list.querySelector('[data-pin-reorder]');
        if (toggle) toggle.textContent = on ? toggle.dataset.labelDone : toggle.dataset.labelEdit;
    };
    list.addEventListener('click', (event) => {
        if (event.target.closest('[data-pin-reorder]')) {
            setReordering(!reordering);
            return;
        }
        const move = event.target.closest('[data-move]');
        if (move) {
            event.preventDefault();
            const row = move.closest('[data-pinned-row]');
            const step = Number(move.dataset.move);
            const sibling = step < 0 ? row.previousElementSibling : row.nextElementSibling;
            if (!sibling) return;
            if (step < 0) sibling.before(row); else sibling.after(row);
            row.scrollIntoView({ block: 'nearest' });
            savePinOrder();
            return;
        }
        if (reordering && event.target.closest('[data-thread-link]')) event.preventDefault();
    });
    list.addEventListener('dragstart', (event) => {
        const row = reordering ? event.target.closest?.('[data-pinned-row]') : null;
        if (!row) return;
        dragged = row;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.pinnedRow);
        row.classList.add('opacity-50');
    });
    list.addEventListener('dragover', (event) => {
        const over = event.target.closest?.('[data-pinned-row]');
        if (!dragged || !over || over === dragged) return;
        event.preventDefault();
        const rect = over.getBoundingClientRect();
        if (event.clientY < rect.top + rect.height / 2) over.before(dragged); else over.after(dragged);
    });
    list.addEventListener('dragend', () => {
        if (!dragged) return;
        dragged.classList.remove('opacity-50');
        dragged = null;
        savePinOrder();
    });

    // ---- Aksi cepat per baris: geser ke kanan (HP) memunculkan "Belum dibaca" dan "Sematkan", seperti WhatsApp ----
    const listNote = sidebar.querySelector('[data-list-note]');
    const rowMenu = sidebar.querySelector('[data-row-menu]');
    const REVEAL = 144; // 2 tombol x 4.5rem
    let swipe = null;
    let openRow = null;
    let menuRow = null;

    const surfaceOf = (row) => row.querySelector('[data-row-surface]');
    const setOffset = (row, px, animate) => {
        const surface = surfaceOf(row);
        if (!surface) return;
        surface.style.transition = animate ? 'transform .18s ease-out' : 'none';
        surface.style.transform = px ? `translateX(${px}px)` : '';
        // Aksi di balik baris hanya terlihat saat baris bergeser (menghindari garis tipis di tepi baris).
        const actions = row.querySelector('[data-row-actions]');
        if (actions) {
            clearTimeout(row.hideTimer);
            if (px > 0) actions.style.visibility = 'visible';
            else row.hideTimer = setTimeout(() => { actions.style.visibility = ''; }, 220);
        }
    };
    const closeRow = (row = openRow) => {
        if (!row) return;
        setOffset(row, 0, true);
        row.dataset.open = '0';
        if (openRow === row) openRow = null;
    };
    const closeMenu = () => { rowMenu.hidden = true; menuRow = null; };
    const showNote = (text) => {
        listNote.textContent = text;
        listNote.hidden = false;
        clearTimeout(showNote.timer);
        showNote.timer = setTimeout(() => { listNote.hidden = true; }, 4000);
    };

    const runRowAction = async (row, action) => {
        const unread = row.dataset.unread === '1';
        const pinned = row.dataset.pinned === '1';
        closeRow(row);
        closeMenu();
        try {
            const response = action === 'pin'
                ? await fetch(row.dataset.pinUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } })
                : await fetch(row.dataset.markUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ unread: !unread }),
                });
            if (response.status === 401 || response.status === 419) { location.reload(); return; }
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                showNote(data.message || t('Tindakan gagal. Coba lagi.'));
                return;
            }
            if (action === 'pin' && !pinned) showNote(t('Obrolan disematkan.'));
            sidebar.dispatchEvent(new CustomEvent('chat:refresh'));
        } catch {
            showNote(t('Tindakan gagal. Coba lagi.'));
        }
    };

    list.addEventListener('touchstart', (event) => {
        const row = reordering ? null : event.target.closest('[data-thread][data-pin-url]');
        if (!row || event.target.closest('[data-row-actions], [data-reorder-controls]')) return;
        if (openRow && openRow !== row) closeRow();
        const touch = event.touches[0];
        swipe = { row, x: touch.clientX, y: touch.clientY, base: row.dataset.open === '1' ? REVEAL : 0, offset: 0, axis: null };
    }, { passive: true });
    list.addEventListener('touchmove', (event) => {
        if (!swipe) return;
        const touch = event.touches[0];
        const dx = touch.clientX - swipe.x;
        const dy = touch.clientY - swipe.y;
        if (swipe.axis === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) swipe.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
        if (swipe.axis !== 'x') return;
        swipe.offset = Math.max(0, Math.min(swipe.base + dx, REVEAL + 24));
        setOffset(swipe.row, swipe.offset, false);
    }, { passive: true });
    const endSwipe = () => {
        if (!swipe) return;
        const { row, axis, offset } = swipe;
        swipe = null;
        if (axis !== 'x') return;
        row.dataset.swiped = '1';
        setTimeout(() => { delete row.dataset.swiped; }, 350);
        if (offset > REVEAL / 2) {
            setOffset(row, REVEAL, true);
            row.dataset.open = '1';
            openRow = row;
        } else {
            closeRow(row);
        }
    };
    list.addEventListener('touchend', endSwipe);
    list.addEventListener('touchcancel', endSwipe);

    list.addEventListener('click', (event) => {
        const action = event.target.closest('[data-row-action]');
        if (action) {
            event.preventDefault();
            runRowAction(action.closest('[data-thread]'), action.dataset.rowAction);
            return;
        }
        const more = event.target.closest('[data-row-more]');
        if (more) {
            event.preventDefault();
            const row = more.closest('[data-thread]');
            openRowMenu(row, more.getBoundingClientRect().left - 150, more.getBoundingClientRect().bottom + 4);
            return;
        }
        // Baris yang sedang terbuka: ketukan menutupnya (bukan membuka obrolan); ketukan sesudah geser diabaikan.
        const surface = event.target.closest('[data-row-surface]');
        if (surface) {
            const row = surface.closest('[data-thread]');
            if (row.dataset.open === '1' || row.dataset.swiped === '1') {
                event.preventDefault();
                closeRow(row);
            }
        }
    }, true);

    function openRowMenu(row, x, y) {
        menuRow = row;
        const unread = row.dataset.unread === '1';
        const pinned = row.dataset.pinned === '1';
        rowMenu.querySelector('[data-row-action="unread"]').textContent = unread ? t('Tandai sudah dibaca') : t('Tandai belum dibaca');
        rowMenu.querySelector('[data-row-action="pin"]').textContent = pinned ? t('Lepas sematan') : t('Sematkan obrolan');
        rowMenu.hidden = false;
        rowMenu.style.left = `${Math.max(8, Math.min(x, window.innerWidth - rowMenu.offsetWidth - 8))}px`;
        rowMenu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - rowMenu.offsetHeight - 8))}px`;
    }
    rowMenu.addEventListener('click', (event) => {
        const action = event.target.closest('[data-row-action]');
        if (action && menuRow) runRowAction(menuRow, action.dataset.rowAction);
    });
    list.addEventListener('contextmenu', (event) => {
        const row = event.target.closest('[data-thread][data-pin-url]');
        if (!row || reordering) return;
        event.preventDefault();
        openRowMenu(row, event.clientX, event.clientY);
    });
    document.addEventListener('click', (event) => {
        if (!rowMenu.hidden && !rowMenu.contains(event.target) && !event.target.closest('[data-row-more]')) closeMenu();
        if (openRow && !openRow.contains(event.target)) closeRow();
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { closeMenu(); closeRow(); } });
    list.addEventListener('scroll', () => { closeMenu(); }, { passive: true });

    // Daftar dimuat ulang (pesan terakhir, angka belum dibaca) selama panel kiri terlihat.
    const refreshList = async (force = false) => {
        if (force !== true && (paused() || reordering || sidebar.offsetParent === null)) return;
        if (swipe || openRow) return;
        try {
            const response = await fetch(sidebar.dataset.listUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await response.json();
            const scroll = list.scrollTop;
            list.innerHTML = data.html;
            list.scrollTop = scroll;
            applySearch();
        } catch { /* dicoba lagi pada putaran berikutnya */ }
    };
    setInterval(refreshList, 8000);
    sidebar.addEventListener('chat:refresh', () => { openRow = null; swipe = null; refreshList(true); });
}

// ---- Percakapan -----------------------------------------------------------------------------------------------
const chat = document.querySelector('[data-chat]');
if (chat) {
    const log = chat.querySelector('[data-log]');
    const form = chat.querySelector('[data-form]');
    const errorBox = chat.querySelector('[data-error]');
    const subtitleText = chat.querySelector('[data-subtitle-text]');
    const scrollDown = chat.querySelector('[data-scroll-down]');
    const scrollCount = chat.querySelector('[data-scroll-count]');
    const panel = document.querySelector('[data-msg-panel]');
    let last = Number(chat.dataset.last) || 0;
    let since = chat.dataset.since;
    let readUpto = Number(chat.dataset.readUpto) || 0;
    let sending = false;
    let unseen = 0;

    const nearBottom = () => log.scrollHeight - log.scrollTop - log.clientHeight < 150;
    const toBottom = (smooth = false) => log.scrollTo({ top: log.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });

    const dayLabel = (day) => {
        const date = new Date(`${day}T12:00:00`);
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
        const same = (a, b) => a.toDateString() === b.toDateString();
        if (same(date, today)) return t('Hari ini');
        if (same(date, yesterday)) return t('Kemarin');
        return date.toLocaleDateString(document.documentElement.lang || 'id', { day: 'numeric', month: 'long', year: 'numeric' });
    };

    const lastDay = () => {
        const messages = log.querySelectorAll('[data-message]');
        return messages.length ? messages[messages.length - 1].dataset.day : null;
    };

    const updateTicks = () => {
        log.querySelectorAll('[data-tick]').forEach((tick) => {
            const id = Number(tick.closest('[data-message]').dataset.message);
            const read = id <= readUpto;
            tick.dataset.read = read ? '1' : '0';
            tick.title = read ? t('Dibaca') : t('Terkirim');
        });
    };

    const append = (messages, { stick, fromMe = false }) => {
        let added = 0;
        messages.forEach((m) => {
            if (log.querySelector(`[data-message="${m.id}"]`)) return;
            if (m.day !== lastDay()) {
                log.insertAdjacentHTML('beforeend', `<div class="sticky top-0 z-10 flex justify-center py-1" data-day-sep="${m.day}"><span class="rounded-lg bg-white/95 px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">${dayLabel(m.day)}</span></div>`);
            }
            log.insertAdjacentHTML('beforeend', m.html);
            added += 1;
            last = Math.max(last, m.id);
        });
        if (!added) return;
        log.querySelector('[data-empty-state]')?.remove(); // pesan pertama sudah ada
        if (stick || fromMe) {
            toBottom(true);
        } else {
            unseen += added;
            scrollCount.textContent = unseen;
            scrollCount.hidden = false;
            scrollDown.hidden = false;
        }
    };

    const replace = (messages) => messages.forEach((m) => {
        const old = log.querySelector(`[data-message="${m.id}"]`);
        if (old) old.outerHTML = m.html;
    });

    const setTyping = (names) => {
        const typing = names.length > 0;
        subtitleText.textContent = !typing ? chat.dataset.subtitle
            : (chat.dataset.direct === '1' ? t('sedang mengetik…') : `${names.join(', ')} ${t('sedang mengetik…')}`);
        subtitleText.classList.toggle('text-white/70', !typing);
        subtitleText.classList.toggle('md:text-slate-500', !typing);
        subtitleText.classList.toggle('text-emerald-400', typing);
        subtitleText.classList.toggle('md:text-emerald-600', typing);
        subtitleText.classList.toggle('font-medium', typing);
    };

    const poll = async () => {
        if (paused() || sending) return;
        try {
            const url = `${chat.dataset.pollUrl}?after=${last}&since=${encodeURIComponent(since)}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.status === 401 || response.status === 419) { location.reload(); return; }
            if (response.status === 403 || response.status === 404) { location.href = chat.dataset.indexUrl; return; }
            if (!response.ok) return;
            const data = await response.json();
            since = data.now;
            const stick = nearBottom();
            append(data.messages, { stick });
            replace(data.changed);
            readUpto = data.read_upto;
            updateTicks();
            setTyping(data.typing || []);
        } catch { /* jaringan putus sebentar */ }
    };
    setInterval(poll, 3000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

    // Loncat ke satu pesan dan sorot sebentar; kalau belum dimuat, buka halaman di sekitar pesan itu.
    const jumpTo = (id) => {
        const target = log.querySelector(`[data-message="${id}"]`);
        if (!target) {
            location.href = `${chat.dataset.showUrl}?pesan=${id}`;
            return;
        }
        target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        const bubble = target.firstElementChild;
        bubble.classList.add('ring-2', 'ring-[#b08a3e]');
        setTimeout(() => bubble.classList.remove('ring-2', 'ring-[#b08a3e]'), 1600);
    };

    // Dibuka di pesan tujuan (hasil cari), di penanda "belum dibaca", atau di pesan terbaru.
    const divider = log.querySelector('[data-unread-divider]');
    if (chat.dataset.target && log.querySelector(`[data-message="${chat.dataset.target}"]`)) {
        log.querySelector(`[data-message="${chat.dataset.target}"]`).scrollIntoView({ block: 'center', inline: 'nearest' });
        jumpTo(chat.dataset.target);
    } else if (divider) divider.scrollIntoView({ block: 'center', inline: 'nearest' });
    else toBottom();

    log.addEventListener('scroll', () => {
        if (nearBottom()) {
            unseen = 0;
            scrollCount.hidden = true;
            scrollDown.hidden = true;
        } else {
            scrollDown.hidden = false;
        }
    });
    scrollDown.addEventListener('click', () => toBottom(true));

    // Loncat ke pesan yang dibalas.
    log.addEventListener('click', (event) => {
        const jump = event.target.closest('[data-jump]');
        if (jump) jumpTo(jump.dataset.jump);
    });

    // ---- Cari pesan ----
    const searchBar = chat.querySelector('[data-search-bar]');
    const searchInput = searchBar.querySelector('[data-search-input]');
    const searchResults = searchBar.querySelector('[data-search-results]');
    const escapeText = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let searchTimer = null;
    let searchToken = 0;
    const closeSearch = () => { searchBar.hidden = true; searchResults.hidden = true; searchInput.value = ''; };
    chat.querySelector('[data-search-open]').addEventListener('click', () => {
        searchBar.hidden = !searchBar.hidden;
        if (!searchBar.hidden) searchInput.focus();
        else closeSearch();
    });
    searchBar.querySelector('[data-search-close]').addEventListener('click', closeSearch);
    searchInput.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeSearch(); });
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const term = searchInput.value.trim();
        if (term.length < 2) { searchResults.hidden = true; return; }
        searchTimer = setTimeout(async () => {
            const token = ++searchToken;
            try {
                const response = await fetch(`${chat.dataset.searchUrl}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok || token !== searchToken) return;
                const { results } = await response.json();
                // html dari server sudah di-escape (hanya <mark> yang ditambahkan); nama & waktu di-escape di sini.
                searchResults.innerHTML = results.length
                    ? results.map((r) => `<button type="button" data-search-hit="${r.id}" class="block w-full border-b border-slate-100 px-4 py-2.5 text-left hover:bg-[#faf6ec]">
                        <span class="flex justify-between gap-2 text-xs text-slate-500"><span class="truncate font-medium text-slate-700">${escapeText(r.author)}</span><span class="shrink-0">${escapeText(r.time)}</span></span>
                        <span class="mt-0.5 block text-sm text-slate-800">${r.html}</span></button>`).join('')
                    : `<p class="px-4 py-6 text-center text-sm text-slate-500">${escapeText(t('Tidak ada pesan yang cocok.'))}</p>`;
                searchResults.hidden = false;
            } catch { /* coba lagi saat mengetik */ }
        }, 300);
    });
    searchResults.addEventListener('click', (event) => {
        const hit = event.target.closest('[data-search-hit]');
        if (!hit) return;
        searchResults.hidden = true;
        jumpTo(hit.dataset.searchHit);
    });

    // ---- Menu pesan: tombol panah (komputer), tekan lama / klik kanan (HP) ----
    let current = null;
    const closeMenu = () => { panel.hidden = true; current = null; };
    const openMenu = (message, x, y) => {
        if (message.dataset.deleted === '1') return;
        current = message;
        panel.querySelector('[data-act="delete"]').hidden = message.dataset.mine !== '1';
        panel.querySelector('[data-act="copy"]').hidden = !message.dataset.text;
        panel.hidden = false;
        const width = panel.offsetWidth;
        const height = panel.offsetHeight;
        panel.style.left = `${Math.max(8, Math.min(x, window.innerWidth - width - 8))}px`;
        panel.style.top = `${Math.max(8, Math.min(y, window.innerHeight - height - 8))}px`;
    };
    log.addEventListener('click', (event) => {
        const button = event.target.closest('[data-msg-menu]');
        if (!button) return;
        event.stopPropagation();
        const rect = button.getBoundingClientRect();
        openMenu(button.closest('[data-message]'), rect.left - 140, rect.bottom + 4);
    });
    log.addEventListener('contextmenu', (event) => {
        const message = event.target.closest('[data-message]:not([data-system])');
        if (!message || event.target.closest('a')) return;
        event.preventDefault();
        openMenu(message, event.clientX, event.clientY);
    });
    let pressTimer = null;
    log.addEventListener('touchstart', (event) => {
        const message = event.target.closest('[data-message]:not([data-system])');
        if (!message) return;
        const touch = event.touches[0];
        pressTimer = setTimeout(() => { navigator.vibrate?.(15); openMenu(message, touch.clientX, touch.clientY); }, 450);
    }, { passive: true });
    ['touchend', 'touchmove', 'touchcancel'].forEach((name) => log.addEventListener(name, () => clearTimeout(pressTimer), { passive: true }));
    document.addEventListener('click', (event) => { if (!panel.hidden && !panel.contains(event.target)) closeMenu(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });
    log.addEventListener('scroll', closeMenu, { passive: true });

    panel.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-act]')?.dataset.act;
        const message = current;
        closeMenu();
        if (!action || !message) return;
        if (action === 'reply') startReply(message);
        if (action === 'copy') {
            try { await navigator.clipboard.writeText(message.dataset.text); } catch { /* izin papan klip ditolak */ }
        }
        if (action === 'delete') {
            const ok = window.askConfirm ? await window.askConfirm(t('Hapus pesan ini untuk semua orang?')) : true;
            if (!ok) return;
            const url = chat.dataset.deleteUrl.replace(/\/0$/, `/${message.dataset.message}`);
            const response = await fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            if (response.ok) replace((await response.json()).changed);
        }
    });

    // Info obrolan
    const info = document.querySelector('[data-info-dialog]');
    const media = info.querySelector('[data-media]');
    const loadMedia = async () => {
        try {
            const response = await fetch(media.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.ok) media.innerHTML = (await response.json()).html;
        } catch { /* tetap tampil pesan memuat */ }
    };
    document.querySelectorAll('[data-info-open]').forEach((button) => button.addEventListener('click', () => { info.showModal(); loadMedia(); }));
    media.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-media-tab]');
        if (tab) {
            media.querySelectorAll('[data-media-tab]').forEach((b) => b.setAttribute('aria-selected', String(b === tab)));
            media.querySelectorAll('[data-media-panel]').forEach((panelEl) => { panelEl.hidden = panelEl.dataset.mediaPanel !== tab.dataset.mediaTab; });
            return;
        }
        const go = event.target.closest('[data-goto]');
        if (go) { info.close(); jumpTo(go.dataset.goto); }
    });
    info.querySelector('[data-info-close]').addEventListener('click', () => info.close());
    info.addEventListener('click', (event) => { if (event.target === info) info.close(); });
    const inviteSearch = info.querySelector('[data-invite-search]');
    inviteSearch?.addEventListener('input', () => filterRows(info.querySelectorAll('[data-invite-person]'), inviteSearch.value.trim().toLowerCase()));

    // ---- Kotak tulis ----
    const replyBar = chat.querySelector('[data-reply-bar]');
    const photoBar = chat.querySelector('[data-photo-bar]');
    const body = form?.querySelector('[data-body]');
    let replyTo = null;
    let photo = null;
    let documentFile = null;

    function startReply(message) {
        if (!form) return;
        replyTo = message.dataset.message;
        replyBar.querySelector('[data-reply-author]').textContent = message.dataset.author;
        replyBar.querySelector('[data-reply-text]').textContent = message.dataset.snippet;
        replyBar.hidden = false;
        body.focus();
    }

    if (form) {
        const button = form.querySelector('[data-send]');
        const photoInputs = form.querySelectorAll('[data-photo-input]');
        const photoPreview = photoBar.querySelector('[data-photo-preview]');
        const fileInput = form.querySelector('[data-file-input]');
        const fileBar = chat.querySelector('[data-file-bar]');
        const attachButton = form.querySelector('[data-attach-open]');
        const attachMenu = form.querySelector('[data-attach-menu]');
        const closeAttach = () => { attachMenu.hidden = true; attachButton.setAttribute('aria-expanded', 'false'); };
        attachButton.addEventListener('click', (event) => {
            event.stopPropagation();
            attachMenu.hidden = !attachMenu.hidden;
            attachButton.setAttribute('aria-expanded', String(!attachMenu.hidden));
        });
        document.addEventListener('click', (event) => { if (!attachMenu.contains(event.target)) closeAttach(); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeAttach(); });

        const clearReply = () => { replyTo = null; replyBar.hidden = true; };
        const clearPhoto = () => {
            if (photoPreview.src) URL.revokeObjectURL(photoPreview.src);
            photo = null;
            photoInputs.forEach((input) => { input.value = ''; });
            photoPreview.removeAttribute('src');
            photoBar.hidden = true;
        };
        const clearFile = () => {
            documentFile = null;
            fileInput.value = '';
            fileBar.hidden = true;
        };
        fileBar.querySelector('[data-file-cancel]').addEventListener('click', clearFile);
        const sizeLabel = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
        fileInput.addEventListener('change', () => {
            closeAttach();
            const file = fileInput.files[0];
            if (!file) return;
            errorBox.hidden = true;
            if (file.size > 8 * 1024 * 1024) {
                clearFile();
                errorBox.textContent = t('Dokumen terlalu besar (maksimal 8 MB).');
                errorBox.hidden = false;
                return;
            }
            clearPhoto();
            documentFile = file;
            fileBar.querySelector('[data-file-ext]').textContent = (file.name.split('.').pop() || 'FILE').toUpperCase().slice(0, 4);
            fileBar.querySelector('[data-file-name]').textContent = file.name;
            fileBar.querySelector('[data-file-size]').textContent = sizeLabel(file.size);
            fileBar.hidden = false;
            body.focus();
        });
        replyBar.querySelector('[data-reply-cancel]').addEventListener('click', clearReply);
        photoBar.querySelector('[data-photo-cancel]').addEventListener('click', clearPhoto);

        const showError = (text) => {
            errorBox.textContent = text;
            errorBox.hidden = false;
        };

        // Foto dikecilkan di perangkat (sisi terpanjang 1280 px, JPEG) sebelum dikirim; server mengolah ulang.
        const compress = async (file) => {
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
            return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
        };

        photoInputs.forEach((photoInput) => photoInput.addEventListener('change', async () => {
            closeAttach();
            const file = photoInput.files[0];
            if (!file) return;
            errorBox.hidden = true;
            clearFile();
            try {
                photo = await compress(file);
                if (!photo) throw new Error();
                photoPreview.src = URL.createObjectURL(photo);
                photoBar.hidden = false;
                body.focus();
            } catch {
                clearPhoto();
                showError(t('Foto tidak bisa diproses. Coba foto lain.'));
            }
        }));

        const submit = async () => {
            const text = body.value.trim();
            if ((!text && !photo && !documentFile) || sending) return;
            sending = true;
            button.disabled = true;
            errorBox.hidden = true;
            const data = new FormData();
            data.append('body', text);
            if (replyTo) data.append('reply_to', replyTo);
            if (photo) data.append('photo', photo, 'foto.jpg');
            else if (documentFile) data.append('file', documentFile, documentFile.name);
            try {
                const response = await fetch(chat.dataset.sendUrl, {
                    method: 'POST', credentials: 'same-origin', body: data,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                });
                if (response.status === 401 || response.status === 419) { location.reload(); return; }
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const errors = json.errors ? Object.values(json.errors).flat() : [];
                    throw new Error(errors[0] || json.message || t('Pesan belum terkirim. Coba lagi.'));
                }
                body.value = '';
                body.style.height = '';
                clearReply();
                clearPhoto();
                clearFile();
                append(json.messages, { stick: true, fromMe: true });
                readUpto = json.read_upto;
                updateTicks();
            } catch (error) {
                showError(error.message || t('Pesan belum terkirim. Coba lagi.'));
            } finally {
                sending = false;
                button.disabled = false;
                body.focus();
            }
        };

        form.addEventListener('submit', (event) => { event.preventDefault(); submit(); });
        // Di komputer Enter mengirim (Shift+Enter = baris baru); di layar sentuh Enter tetap baris baru.
        body.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && window.matchMedia('(pointer: fine)').matches) {
                event.preventDefault();
                submit();
            }
            if (event.key === 'Escape') clearReply();
        });

        // Tinggi kotak mengikuti isi; "sedang mengetik" dikirim paling sering tiap 3 detik.
        let lastTyping = 0;
        body.addEventListener('input', () => {
            body.style.height = 'auto';
            body.style.height = `${Math.min(body.scrollHeight, 144)}px`;
            const now = Date.now();
            if (chat.dataset.typingUrl && body.value.trim() && now - lastTyping > 3000) {
                lastTyping = now;
                fetch(chat.dataset.typingUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } }).catch(() => {});
            }
        });

        // ---- @mention: ketik "@" lalu pilih anggota; yang dipilih ditulis "@Nama Lengkap " ----
        const mentionList = chat.querySelector('[data-mention-list]');
        let people = [];
        try { people = JSON.parse(chat.dataset.mentionables || '[]'); } catch { people = []; }
        let mention = null; // { start, matches, active }

        const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const initialsOf = (name) => name.split(/\s+/).filter((w) => /^\p{L}/u.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '#';

        const closeMention = () => { mention = null; mentionList.hidden = true; body.removeAttribute('aria-activedescendant'); };
        const renderMention = () => {
            mentionList.innerHTML = mention.matches.map((p, i) => `
                <button type="button" role="option" id="mention-${p.id}" data-mention-pick="${i}" aria-selected="${i === mention.active}"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-[#faf6ec] ${i === mention.active ? 'bg-[#f6efdf]' : ''}">
                    ${p.photo
                        ? `<img src="${escapeHtml(p.photo)}" alt="" class="h-8 w-8 shrink-0 rounded-full bg-slate-100 object-cover">`
                        : `<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#f3e6c4] text-xs font-semibold text-[#8a6a22]">${escapeHtml(initialsOf(p.name))}</span>`}
                    <span class="min-w-0"><span class="block truncate text-sm font-medium text-slate-900">${escapeHtml(p.name)}</span>
                    <span class="block truncate text-xs text-slate-500">${escapeHtml(p.info || '')}</span></span>
                </button>`).join('');
            mentionList.hidden = false;
            body.setAttribute('aria-activedescendant', `mention-${mention.matches[mention.active].id}`);
            mentionList.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        };

        // Kata yang sedang diketik: "@" di awal atau sesudah spasi, sampai kursor (boleh berisi spasi untuk nama lengkap, maks 30 huruf).
        const detectMention = () => {
            if (!people.length) return closeMention();
            const before = body.value.slice(0, body.selectionStart);
            const found = before.match(/(?:^|\s)@([^@\n]{0,30})$/u);
            if (!found) return closeMention();
            const query = found[1].toLowerCase();
            // Nama yang sudah lengkap diketik / dipilih (diikuti spasi) tidak membuka daftar lagi.
            if (people.some((p) => query.startsWith(`${p.name.toLowerCase()} `))) return closeMention();
            const matches = people.filter((p) => p.name.toLowerCase().includes(query.trim()) || p.name.toLowerCase().startsWith(query)).slice(0, 8);
            if (!matches.length) return closeMention();
            mention = { start: before.length - found[1].length - 1, matches, active: 0 };
            renderMention();
        };

        const pickMention = (index) => {
            const person = mention.matches[index];
            const caret = body.selectionStart;
            const text = `@${person.name} `;
            body.value = body.value.slice(0, mention.start) + text + body.value.slice(caret);
            const position = mention.start + text.length;
            body.setSelectionRange(position, position);
            closeMention();
            body.focus();
            body.dispatchEvent(new Event('input'));
        };

        body.addEventListener('input', detectMention);
        body.addEventListener('click', detectMention);
        body.addEventListener('blur', () => setTimeout(closeMention, 150));
        // Fase capture: jalan sebelum "Enter = kirim", supaya Enter / Tab memilih nama saat daftar terbuka.
        body.addEventListener('keydown', (event) => {
            if (!mention) return;
            const keys = { ArrowDown: 1, ArrowUp: -1 };
            if (event.key in keys) {
                mention.active = (mention.active + keys[event.key] + mention.matches.length) % mention.matches.length;
                renderMention();
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                pickMention(mention.active);
            } else if (event.key === 'Escape') {
                closeMention();
            } else {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        mentionList.addEventListener('mousedown', (event) => event.preventDefault()); // fokus tetap di kotak tulis
        mentionList.addEventListener('click', (event) => {
            const button = event.target.closest('[data-mention-pick]');
            if (button && mention) pickMention(Number(button.dataset.mentionPick));
        });

        // Geser ke kanan pada gelembung di HP = balas, seperti WhatsApp.
        let swipe = null;
        log.addEventListener('touchstart', (event) => {
            const message = event.target.closest('[data-message]:not([data-system])');
            if (!message || message.dataset.deleted === '1') return;
            swipe = { message, x: event.touches[0].clientX, y: event.touches[0].clientY, dx: 0 };
        }, { passive: true });
        log.addEventListener('touchmove', (event) => {
            if (!swipe) return;
            const dx = event.touches[0].clientX - swipe.x;
            const dy = Math.abs(event.touches[0].clientY - swipe.y);
            if (dy > 30) { swipe.message.firstElementChild.style.transform = ''; swipe = null; return; }
            swipe.dx = Math.max(0, Math.min(dx, 80));
            swipe.message.firstElementChild.style.transform = `translateX(${swipe.dx}px)`;
        }, { passive: true });
        log.addEventListener('touchend', () => {
            if (!swipe) return;
            const bubble = swipe.message.firstElementChild;
            bubble.style.transition = 'transform .15s';
            bubble.style.transform = '';
            setTimeout(() => { bubble.style.transition = ''; }, 160);
            if (swipe.dx > 60) startReply(swipe.message);
            swipe = null;
        });
    }
}
