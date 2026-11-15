// Perilaku antarmuka bersama untuk semua halaman (dimuat layouts/app dan layouts/guest).

const t = (text) => (window.t ? window.t(text) : text);

// 1. Kirim sekali: formulir POST yang sedang dikirim tidak bisa dikirim lagi (ketuk dua kali, sinyal lemah), dan tombol pengirimnya
//    diberi putaran. Formulir GET (filter, cari) dan formulir yang ditangani JavaScript (preventDefault, mis. fetch) tidak disentuh.
//    Kecualikan satu formulir dengan data-no-submit-lock.
const UNLOCK_AFTER = 15000; // jaga-jaga bila halaman tidak berganti (unduhan, jaringan putus): tombol aktif lagi

const unlock = (form) => {
    delete form.dataset.submitting;
    form.querySelectorAll('[data-submit-spinner]').forEach((spinner) => spinner.remove());
    form.querySelectorAll('[aria-busy="true"]').forEach((button) => button.removeAttribute('aria-busy'));
};

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.submitting) {
        event.preventDefault();
        return;
    }
    if (form.method !== 'post' || form.target || 'noSubmitLock' in form.dataset) return;
    // Tanpa submitter (dikirim ulang oleh dialog konfirmasi lewat requestSubmit): tombol kirim pertama formulir.
    const button = event.submitter ?? form.querySelector('button[type=submit], button:not([type])');
    // Diperiksa sesudah semua pendengar submit lain berjalan: yang membatalkan pengiriman (dialog konfirmasi, fetch) tidak dikunci.
    setTimeout(() => {
        if (event.defaultPrevented) return;
        form.dataset.submitting = '1';
        if (button instanceof HTMLButtonElement) {
            button.setAttribute('aria-busy', 'true');
            button.insertAdjacentHTML('afterbegin', '<span class="spinner" data-submit-spinner aria-hidden="true"></span>');
        }
        setTimeout(() => unlock(form), UNLOCK_AFTER);
    });
});

// Kembali ke halaman ini lewat tombol Back (cache browser): formulir bisa dipakai lagi.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) document.querySelectorAll('form[data-submitting]').forEach(unlock);
});

// 2. Toast: window.toast('Tersalin') atau window.toast('Gagal menyimpan', { tone: 'danger' }).
//    Hilang sendiri (lebih lama untuk pesan panjang), berhenti saat diarahkan kursor, bisa ditutup.
let stack;
window.toast = (message, { tone = 'success', duration } = {}) => {
    stack ||= document.querySelector('[data-toasts]');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toasts print:hidden';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.append(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.dataset.tone = tone;
    toast.innerHTML = `<span class="toast-dot" aria-hidden="true">${tone === 'danger' ? '!' : '✓'}</span><span class="min-w-0 flex-1"></span><button type="button" aria-label="${t('Tutup')}">×</button>`;
    toast.children[1].textContent = message;
    stack.append(toast);

    const remove = () => {
        toast.dataset.leaving = '';
        setTimeout(() => toast.remove(), 200);
    };
    const wait = duration ?? (message.length > 90 ? 9000 : 5000);
    let timer = setTimeout(remove, wait);
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', () => { timer = setTimeout(remove, 2000); });
    toast.querySelector('button').addEventListener('click', remove);
};

// Pesan dari server (session('status'), ditulis layout sebagai data-flash).
document.querySelectorAll('[data-flash]').forEach((element) => {
    window.toast(element.dataset.flash, { tone: element.dataset.tone || 'success' });
    element.remove();
});

// 3. Kolom dengan pesan error / keterangan (x-ui.field): pesan ditautkan ke isian untuk pembaca layar.
document.querySelectorAll('[data-field]').forEach((field, index) => {
    const control = field.querySelector('input:not([type=hidden]), select, textarea');
    const note = field.querySelector('[data-field-error], [data-field-hint]');
    if (!control || !note) return;
    note.id ||= `field-note-${index}`;
    control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), note.id].filter(Boolean).join(' '));
    if (note.matches('[data-field-error]')) control.setAttribute('aria-invalid', 'true');
});

// 4. Penyaring (form GET bertanda data-filters): di HP dilipat menjadi tombol "Filter · N" + chip penyaring aktif (× = lepas satu);
//    "Hapus filter" tampil di semua layar bila ada yang aktif. Isian dianggap aktif bila nilainya bukan data-default (bawaan: kosong).
//    Isian tersembunyi (rentang tanggal, tab) tidak dihitung dan tetap dibawa saat menghapus filter.
document.querySelectorAll('form[data-filters]').forEach((form, index) => {
    const controls = [...form.querySelectorAll('select[name], input[name]:not([type=hidden]):not([type=submit])')];
    const initial = (control) => control.dataset.default ?? '';
    const isActive = (control) => (control.type === 'checkbox' ? control.checked : control.value.trim() !== initial(control));
    const labelOf = (control) => (control.labels?.[0]?.textContent || control.getAttribute('aria-label') || '').trim();
    const active = controls.filter(isActive);

    const reset = new URL(form.action || location.href, location.href);
    reset.search = '';
    form.querySelectorAll('input[type=hidden][name]').forEach((input) => reset.searchParams.set(input.name, input.value));

    form.id ||= `filters-${index}`;
    const bar = document.createElement('div');
    bar.className = 'mb-3 flex flex-wrap items-center gap-2 print:hidden';
    bar.innerHTML = `<button type="button" class="btn-secondary btn-sm gap-1.5 sm:hidden" aria-expanded="false" aria-controls="${form.id}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
        <span></span></button>`;
    const toggle = bar.querySelector('button');
    toggle.lastElementChild.textContent = active.length ? `${t('Filter')} · ${active.length}` : t('Filter');

    active.forEach((control) => {
        const text = control.tagName === 'SELECT' ? control.selectedOptions[0]?.textContent.trim() : control.type === 'checkbox' ? labelOf(control) : `${labelOf(control)}: ${control.value.trim()}`;
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'inline-flex max-w-full items-center gap-1 rounded-full border border-gold-500 bg-gold-100 px-3 py-1 text-[13px] font-medium text-slate-800 sm:hidden';
        chip.innerHTML = '<span class="truncate"></span><span aria-hidden="true" class="text-slate-500">×</span>';
        chip.firstElementChild.textContent = text;
        chip.setAttribute('aria-label', `${t('Lepas filter')} ${text}`);
        chip.addEventListener('click', () => {
            if (control.type === 'checkbox') control.checked = false;
            else control.value = initial(control);
            form.requestSubmit();
        });
        bar.append(chip);
    });

    if (active.length) {
        const clear = document.createElement('a');
        clear.href = reset.toString();
        clear.className = 'btn-ghost btn-sm';
        clear.textContent = t('Hapus filter');
        bar.append(clear);
    }

    toggle.addEventListener('click', () => {
        const open = !('open' in form.dataset);
        form.toggleAttribute('data-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (open) form.querySelector('select, input:not([type=hidden])')?.focus({ preventScroll: true });
    });

    form.before(bar);
    form.dataset.filtersReady = '';
});

// 5. Dropdown menu atas (layouts/app, data-nav-menu): tetap terbuka saat kursor di atasnya / fokus di dalamnya (CSS), dan di sini
//    aria-expanded diperbarui untuk pembaca layar, Esc menutup dan mengembalikan fokus ke tombolnya, panah bawah masuk ke daftar.
// Submenu (mis. Tugas > Data kamar) terbuka ke KANAN; bila tidak muat di layar, dibalik ke kiri (data-flip).
document.querySelectorAll('[data-submenu]').forEach((item) => {
    const panel = item.querySelector(':scope > div');
    const place = () => {
        delete item.dataset.flip;
        const rect = panel.getBoundingClientRect();
        if (rect.right > window.innerWidth - 8) item.dataset.flip = '';
    };
    item.addEventListener('mouseenter', place);
    item.addEventListener('focusin', place);
});

document.querySelectorAll('[data-nav-menu]').forEach((menu) => {
    const button = menu.querySelector('button');
    const links = () => [...menu.querySelectorAll('a')];
    const sync = () => button.setAttribute('aria-expanded', String(!('suppress' in menu.dataset) && (menu.matches(':hover') || menu.contains(document.activeElement))));
    const open = () => { delete menu.dataset.suppress; sync(); };

    menu.addEventListener('mouseenter', open);
    menu.addEventListener('mouseleave', () => setTimeout(sync));
    menu.addEventListener('focusin', () => setTimeout(sync));
    menu.addEventListener('focusout', (event) => {
        if (menu.contains(event.relatedTarget)) return;
        delete menu.dataset.suppress;
        setTimeout(sync);
    });
    button.addEventListener('click', open);
    // Ketuk / klik di luar menu (layar sentuh tidak punya mouseleave): tandai tertutup.
    document.addEventListener('pointerdown', (event) => { if (!menu.contains(event.target)) button.setAttribute('aria-expanded', 'false'); });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            menu.dataset.suppress = '';
            sync();
            button.focus();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            open();
            const list = links();
            const at = list.indexOf(document.activeElement);
            const next = event.key === 'ArrowDown' ? (at + 1) % list.length : (at <= 0 ? list.length - 1 : at - 1);
            list[next]?.focus();
        }
    });
});
