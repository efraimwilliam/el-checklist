const t = (text) => (window.__i18n && window.__i18n[text]) || text;
// Kalender tanggal bergaya emas untuk semua kolom tanggal di aplikasi: satu tanggal (data-date-field) atau rentang (data-range-field).
// Tombol menampilkan tanggal terpilih; ketuk untuk membuka kalender. Ketuk nama bulan untuk memilih bulan dan tahun.
// Hanya sampai hari ini yang bisa dipilih. Form yang bertanda data-submit dikirim otomatis begitu pilihan lengkap.
const MONTHS = [t('Januari'), t('Februari'), t('Maret'), t('April'), t('Mei'), t('Juni'), t('Juli'), t('Agustus'), t('September'), t('Oktober'), t('November'), t('Desember')];
const WEEKDAYS = [t('Sen'), t('Sel'), t('Rab'), t('Kam'), t('Jum'), t('Sab'), t('Min')];
const WEEKDAYS_LONG = [t('Minggu'), t('Senin'), t('Selasa'), t('Rabu'), t('Kamis'), t('Jumat'), t('Sabtu')];

const toIso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const fromIso = (value) => {
    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
};
const shortDate = (date) => `${date.getDate()} ${MONTHS[date.getMonth()].slice(0, 3)} ${date.getFullYear()}`;
const longDate = (date) => `${WEEKDAYS_LONG[date.getDay()]}, ${shortDate(date)}`;

// Kerangka kalender: navigasi bulan, pilih bulan & tahun, dan sel hari. Pemanggil menentukan sorotan, petunjuk, dan aksi pilih.
const mountCalendar = (panel, { max, initialView, hint, bounds, onDay, onHover }) => {
    let view = initialView;
    let mode = 'day';

    const renderDays = () => {
        const year = view.getFullYear();
        const month = view.getMonth();
        const lead = (new Date(year, month, 1).getDay() + 6) % 7; // minggu dimulai Senin
        const days = new Date(year, month + 1, 0).getDate();
        const canGoNext = new Date(year, month + 1, 1) <= max;
        const [lo, hi] = bounds();
        const cells = [];
        for (let i = 0; i < lead; i++) cells.push('<span></span>');
        for (let day = 1; day <= days; day++) {
            const date = new Date(year, month, day);
            const iso = toIso(date);
            const attrs = [
                `data-day="${iso}"`,
                date >= lo && date <= hi ? 'data-in' : '',
                iso === toIso(lo) || iso === toIso(hi) ? 'data-edge' : '',
                iso === toIso(max) ? 'data-today' : '',
                date > max ? 'disabled' : '',
            ].join(' ');
            cells.push(`<button type="button" ${attrs}>${day}</button>`);
        }
        panel.innerHTML = `
            <div class="cal-head">
                <button type="button" data-nav="-1" aria-label="Bulan sebelumnya">‹</button>
                <p><button type="button" class="cal-title" data-pick="month" aria-label="Pilih bulan dan tahun">${MONTHS[month]} <span>${year}</span></button></p>
                <button type="button" data-nav="1" aria-label="Bulan berikutnya" ${canGoNext ? '' : 'disabled'}>›</button>
            </div>
            <div class="cal-grid">
                ${WEEKDAYS.map((name) => `<span class="cal-dow">${name}</span>`).join('')}
                ${cells.join('')}
            </div>
            <p class="cal-hint">${hint()}</p>`;
    };

    const renderMonths = () => {
        const year = view.getFullYear();
        const cells = MONTHS.map((name, index) => {
            const future = year > max.getFullYear() || (year === max.getFullYear() && index > max.getMonth());
            return `<button type="button" data-month="${index}" aria-pressed="${index === view.getMonth()}" ${future ? 'disabled' : ''}>${name.slice(0, 3)}</button>`;
        }).join('');
        panel.innerHTML = `
            <div class="cal-head">
                <button type="button" data-year="-1" aria-label="Tahun sebelumnya">‹</button>
                <p><span class="cal-year">${year}</span></p>
                <button type="button" data-year="1" aria-label="Tahun berikutnya" ${year >= max.getFullYear() ? 'disabled' : ''}>›</button>
            </div>
            <div class="cal-months">${cells}</div>`;
    };

    const render = () => (mode === 'month' ? renderMonths() : renderDays());

    panel.addEventListener('click', (event) => {
        if (event.target.closest('[data-pick]')) {
            mode = 'month';
            render();
            return;
        }
        const yearNav = event.target.closest('[data-year]');
        if (yearNav) {
            view = new Date(view.getFullYear() + Number(yearNav.dataset.year), view.getMonth(), 1);
            render();
            return;
        }
        const month = event.target.closest('[data-month]');
        if (month) {
            view = new Date(view.getFullYear(), Number(month.dataset.month), 1);
            mode = 'day';
            render();
            return;
        }
        const nav = event.target.closest('[data-nav]');
        if (nav) {
            view = new Date(view.getFullYear(), view.getMonth() + Number(nav.dataset.nav), 1);
            render();
            return;
        }
        const day = event.target.closest('[data-day]');
        if (day) onDay(fromIso(day.dataset.day));
    });

    panel.addEventListener('mouseover', (event) => {
        const day = event.target.closest('[data-day]');
        if (day && onHover) onHover(fromIso(day.dataset.day));
    });

    return {
        render,
        reset(date) {
            view = date;
            mode = 'day';
            render();
        },
    };
};

// Satu tanggal: tombol menampilkan tanggal; pilih hari = tutup kalender (dan kirim form bila data-submit).
document.querySelectorAll('[data-date-field]').forEach((field) => {
    const form = field.closest('form');
    const valueInput = field.querySelector('[data-date-value]');
    const trigger = field.querySelector('[data-date-trigger]');
    const label = field.querySelector('[data-date-label]');
    const panel = field.querySelector('[data-date-panel]');
    const max = fromIso(field.dataset.max);
    const autoSubmit = field.hasAttribute('data-submit');

    const calendar = mountCalendar(panel, {
        max,
        initialView: fromIso(valueInput.value),
        hint: () => t('Pilih tanggal'),
        bounds: () => {
            const selected = fromIso(valueInput.value);
            return [selected, selected];
        },
        onDay: (date) => {
            valueInput.value = toIso(date);
            label.textContent = longDate(date);
            close();
            if (autoSubmit) form.requestSubmit();
        },
    });

    const open = () => {
        calendar.reset(fromIso(valueInput.value));
        panel.classList.remove('hidden');
        trigger.setAttribute('aria-expanded', 'true');
    };
    const close = () => {
        panel.classList.add('hidden');
        trigger.setAttribute('aria-expanded', 'false');
    };

    trigger.addEventListener('click', () => (panel.classList.contains('hidden') ? open() : close()));
    document.addEventListener('keydown', (event) => event.key === 'Escape' && close());
    document.addEventListener('click', (event) => {
        if (!event.composedPath().includes(field)) close();
    });
});

// Rentang: klik tanggal mulai lalu tanggal selesai, atau tekan dan seret dari tanggal mulai ke tanggal selesai.
// Sorotan mengikuti kursor. Setelah rentang lengkap, form dikirim.
document.querySelectorAll('[data-range-field]').forEach((field) => {
    const form = field.closest('form');
    const fromInput = field.querySelector('[data-range-from]');
    const toInput = field.querySelector('[data-range-to]');
    const trigger = field.querySelector('[data-range-trigger]');
    const label = field.querySelector('[data-range-label]');
    const panel = field.querySelector('[data-range-panel]');
    const max = fromIso(field.dataset.max);

    let start = fromIso(fromInput.value);
    let end = fromIso(toInput.value);
    let picking = false; // tanggal mulai sudah dipilih, menunggu tanggal selesai
    let hover = null;
    let dragStart = null; // tombol kursor ditekan di tanggal ini
    let dragged = false; // kursor sudah pindah ke tanggal lain sambil ditekan

    const bounds = () => {
        const other = picking ? (hover ?? start) : end;
        return other < start ? [other, start] : [start, other];
    };

    const calendar = mountCalendar(panel, {
        max,
        initialView: start,
        hint: () => (picking ? t('Pilih tanggal selesai') : t('Klik atau seret: tanggal mulai lalu tanggal selesai')),
        bounds,
        onDay: (date) => {
            if (!picking) {
                start = date;
                end = date;
                picking = true;
                hover = null;
                calendar.render();
                return;
            }
            finish(date);
        },
        onHover: (date) => {
            if (dragStart) {
                if (!dragged && toIso(date) === toIso(dragStart)) return;
                dragged = true;
                picking = true;
                start = dragStart;
                hover = date;
                calendar.render();
                return;
            }
            if (!picking || (hover && toIso(hover) === toIso(date))) return;
            hover = date;
            calendar.render();
        },
    });

    // Tekan di tanggal (belum memilih apa pun): mulai seret. Lepas di tanggal yang sama = klik biasa.
    panel.addEventListener('pointerdown', (event) => {
        const day = event.target.closest('[data-day]');
        if (!day || picking || day.disabled) return;
        dragStart = fromIso(day.dataset.day);
        dragged = false;
    });

    document.addEventListener('pointerup', () => {
        if (!dragStart) return;
        const moved = dragged;
        dragStart = null;
        if (moved && hover) finish(hover);
    });

    // Pilihan selesai: rentang dari tanggal awal ke tanggal ini, lalu form dikirim.
    const finish = (date) => {
        const [lo, hi] = date < start ? [date, start] : [start, date];
        start = lo;
        end = hi;
        picking = false;
        hover = null;
        dragged = false;
        fromInput.value = toIso(start);
        toInput.value = toIso(end);
        label.textContent = start.getTime() === end.getTime() ? shortDate(start) : `${shortDate(start)} – ${shortDate(end)}`;
        close();
        form.requestSubmit();
    };

    const open = () => {
        start = fromIso(fromInput.value);
        end = fromIso(toInput.value);
        picking = false;
        hover = null;
        dragStart = null;
        calendar.reset(start);
        panel.classList.remove('hidden');
        trigger.setAttribute('aria-expanded', 'true');
    };
    const close = () => {
        panel.classList.add('hidden');
        trigger.setAttribute('aria-expanded', 'false');
        picking = false;
        hover = null;
        dragStart = null;
    };

    trigger.addEventListener('click', () => (panel.classList.contains('hidden') ? open() : close()));
    document.addEventListener('keydown', (event) => event.key === 'Escape' && close());
    document.addEventListener('click', (event) => {
        if (!event.composedPath().includes(field)) close();
    });
});
