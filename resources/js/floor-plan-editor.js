// Editor kotak area di atas denah. Satu kotak mewakili satu kamar (denah lantai) atau satu gedung / area (peta kawasan).
// Posisi dan ukuran disimpan dalam persen (0-100) dari gambar, jadi tetap pas di ukuran layar apa pun.
// Tanpa library: pointer events untuk gambar / geser / ubah ukuran, tombol keyboard untuk hapus dan geser halus.

const root = document.querySelector('[data-plan-editor]');

if (root) {
    const MIN = 0.8; // ukuran terkecil kotak (persen)
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const layer = root.querySelector('[data-layer]');
    const stage = root.querySelector('[data-stage]');
    const list = root.querySelector('[data-room-list]');
    const buildingList = root.querySelector('[data-building-list]');
    const buildingForm = root.querySelector('[data-building-form]');
    const buildingNew = root.querySelector('[data-building-new]');
    const filter = root.querySelector('[data-room-filter]');
    const count = root.querySelector('[data-room-count]');
    const hint = root.querySelector('[data-hint]');
    const status = root.querySelector('[data-save-status]');
    const saveButton = root.querySelector('[data-save]');
    const deleteButton = root.querySelector('[data-delete-area]');
    const rooms = JSON.parse(root.dataset.rooms || '[]');
    const planFloor = root.dataset.floor || '';

    const keyOf = (a) => (a.room_id ? `r${a.room_id}` : `b:${String(a.building).toLowerCase()}`);
    let areas = JSON.parse(root.dataset.areas || '[]').map((a) => ({ room_id: a.room_id, building: a.building, x: +a.x, y: +a.y, w: +a.w, h: +a.h }));
    // Daftar gedung / area: dari data kamar, ditambah nama yang sudah dipakai di denah ini atau diketik di sini.
    const buildings = new Map(JSON.parse(root.dataset.buildings || '[]').map((b) => [b.name.toLowerCase(), { name: b.name, rooms: b.rooms }]));
    areas.filter((a) => a.building).forEach((a) => { if (!buildings.has(a.building.toLowerCase())) buildings.set(a.building.toLowerCase(), { name: a.building, rooms: 0 }); });

    let selected = null; // kunci kotak terpilih
    let armed = null; // { kind, id|name } yang akan digambar
    let kind = 'room';
    let dirty = false;
    let zoom = 1;
    let drag = null;

    const roomOf = (id) => rooms.find((room) => room.id === id);
    const areaOf = (key) => areas.find((area) => keyOf(area) === key);
    const armedKey = () => (armed ? (armed.kind === 'room' ? `r${armed.id}` : `b:${armed.name.toLowerCase()}`) : null);
    const labelOf = (area) => (area.room_id ? (roomOf(area.room_id)?.number ?? area.room_id) : area.building);
    const clamp = (value, low, high) => Math.min(high, Math.max(low, value));

    const setDirty = () => { dirty = true; status.textContent = 'Belum disimpan'; saveButton.disabled = false; };

    const updateHint = () => {
        const selectedArea = selected ? areaOf(selected) : null;
        if (armed) hint.textContent = `Seret di atas gambar untuk menggambar kotak ${armed.kind === 'room' ? `kamar ${roomOf(armed.id)?.number ?? ''}` : armed.name}. (Esc untuk batal)`;
        else if (selectedArea) hint.textContent = `${selectedArea.room_id ? 'Kamar' : 'Gedung / area'} ${labelOf(selectedArea)} terpilih: geser kotaknya, tarik pegangan untuk mengubah ukuran, atau tekan Delete untuk menghapus.`;
        else hint.textContent = 'Pilih kamar atau gedung / area di daftar untuk mulai menggambar, atau klik kotak yang sudah ada.';
        deleteButton.disabled = selected === null;
        layer.style.touchAction = armed ? 'none' : '';
        layer.style.cursor = armed ? 'crosshair' : '';
    };

    const itemButton = (active, placed, content) => {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm transition ${active ? 'border-amber-600 bg-amber-50' : 'border-slate-200 bg-white hover:border-amber-400'}`;
        const badge = document.createElement('span');
        badge.className = `shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${placed ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'}`;
        badge.textContent = placed ? '✓' : '—';
        button.append(content, badge);
        item.append(button);

        return { item, button };
    };

    const choose = (target, key, placed) => {
        if (placed) {
            armed = null;
            selected = key;
            renderAreas();
            document.querySelector(`[data-area="${key}"]`)?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
        } else {
            armed = armedKey() === key ? null : target;
            selected = null;
            renderAreas();
        }
    };

    // ---- Daftar kamar dan daftar gedung / area ----
    const renderLists = () => {
        const query = filter.value.trim().toLowerCase();
        const sorted = [...rooms].sort((a, b) => {
            const aMatch = planFloor && a.floor === planFloor ? 0 : 1;
            const bMatch = planFloor && b.floor === planFloor ? 0 : 1;
            return aMatch - bMatch || String(a.number).localeCompare(String(b.number), 'id', { numeric: true });
        });
        list.replaceChildren(...sorted.filter((room) => !query || String(room.number).toLowerCase().includes(query)).map((room) => {
            const key = `r${room.id}`;
            const placed = !!areaOf(key);
            const label = document.createElement('span');
            label.innerHTML = '<span class="font-display text-lg text-slate-900"></span> <span class="text-xs text-slate-500"></span>';
            label.children[0].textContent = room.number;
            label.children[1].textContent = [room.floor ? `Lantai ${room.floor}` : '', room.type || ''].filter(Boolean).join(' · ');
            const { item, button } = itemButton(armedKey() === key || selected === key, placed, label);
            button.addEventListener('click', () => choose({ kind: 'room', id: room.id }, key, placed));

            return item;
        }));
        count.textContent = `${areas.filter((a) => a.room_id).length} / ${rooms.length + areas.filter((a) => a.room_id && !roomOf(a.room_id)).length} kamar ditandai`;

        buildingList.replaceChildren(...[...buildings.values()].sort((a, b) => a.name.localeCompare(b.name, 'id', { numeric: true })).map((building) => {
            const key = `b:${building.name.toLowerCase()}`;
            const placed = !!areaOf(key);
            const label = document.createElement('span');
            label.innerHTML = '<span class="font-semibold text-slate-900"></span> <span class="block text-xs text-slate-500"></span>';
            label.children[0].textContent = building.name;
            label.children[1].textContent = building.rooms ? `${building.rooms} kamar` : 'belum ada kamar dengan nama gedung ini';
            const { item, button } = itemButton(armedKey() === key || selected === key, placed, label);
            button.addEventListener('click', () => choose({ kind: 'building', name: building.name }, key, placed));

            return item;
        }));
    };

    // ---- Kotak di atas gambar ----
    const HANDLES = { nw: 'left-0 top-0 -translate-x-1/2 -translate-y-1/2 cursor-nwse-resize', n: 'left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 cursor-ns-resize', ne: 'right-0 top-0 translate-x-1/2 -translate-y-1/2 cursor-nesw-resize', e: 'right-0 top-1/2 translate-x-1/2 -translate-y-1/2 cursor-ew-resize', se: 'right-0 bottom-0 translate-x-1/2 translate-y-1/2 cursor-nwse-resize', s: 'bottom-0 left-1/2 -translate-x-1/2 translate-y-1/2 cursor-ns-resize', sw: 'bottom-0 left-0 -translate-x-1/2 translate-y-1/2 cursor-nesw-resize', w: 'left-0 top-1/2 -translate-x-1/2 -translate-y-1/2 cursor-ew-resize' };

    const place = (el, area) => {
        el.style.left = `${area.x}%`;
        el.style.top = `${area.y}%`;
        el.style.width = `${area.w}%`;
        el.style.height = `${area.h}%`;
    };

    const renderAreas = () => {
        layer.replaceChildren(...areas.map((area) => {
            const key = keyOf(area);
            const el = document.createElement('div');
            el.dataset.area = key;
            const isSelected = selected === key;
            const tone = area.room_id ? 'border-amber-700 bg-amber-400/25 text-amber-900' : 'border-teal-700 bg-teal-400/25 text-teal-900';
            el.className = `absolute flex items-center justify-center overflow-visible border-2 text-xs font-bold leading-none ${isSelected ? 'z-10 border-sky-600 bg-sky-500/25 text-sky-900' : tone}`;
            el.style.touchAction = 'none';
            el.style.cursor = armed ? 'crosshair' : 'move';
            place(el, area);
            const label = document.createElement('span');
            label.className = 'pointer-events-none max-w-full truncate rounded bg-white/80 px-1 py-0.5';
            label.textContent = labelOf(area);
            el.append(label);
            if (isSelected) {
                Object.entries(HANDLES).forEach(([name, classes]) => {
                    const handle = document.createElement('span');
                    handle.dataset.handle = name;
                    handle.className = `absolute h-3 w-3 rounded-sm border border-sky-700 bg-white ${classes}`;
                    handle.style.touchAction = 'none';
                    el.append(handle);
                });
            }
            return el;
        }));
        renderLists();
        updateHint();
    };

    const pointOf = (event) => {
        const rect = layer.getBoundingClientRect();
        return { x: clamp(((event.clientX - rect.left) / rect.width) * 100, 0, 100), y: clamp(((event.clientY - rect.top) / rect.height) * 100, 0, 100) };
    };

    layer.addEventListener('pointerdown', (event) => {
        if (event.button !== undefined && event.button !== 0) return;
        const handle = event.target.closest('[data-handle]');
        const areaEl = event.target.closest('[data-area]');
        const point = pointOf(event);

        if (handle && areaEl) {
            const area = areaOf(areaEl.dataset.area);
            drag = { kind: 'resize', key: areaEl.dataset.area, dir: handle.dataset.handle, start: point, origin: { ...area } };
        } else if (areaEl && !armed) {
            const area = areaOf(areaEl.dataset.area);
            selected = areaEl.dataset.area;
            drag = { kind: 'move', key: selected, start: point, origin: { ...area }, moved: false };
            renderAreas();
        } else if (armed) {
            drag = { kind: 'draw', target: armed, start: point };
            const ghost = document.createElement('div');
            ghost.className = 'absolute border-2 border-dashed border-sky-600 bg-sky-500/20';
            layer.append(ghost);
            drag.ghost = ghost;
        } else {
            selected = null;
            renderAreas();
            return;
        }
        layer.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    layer.addEventListener('pointermove', (event) => {
        if (!drag) return;
        const point = pointOf(event);

        if (drag.kind === 'draw') {
            const box = { x: Math.min(drag.start.x, point.x), y: Math.min(drag.start.y, point.y), w: Math.abs(point.x - drag.start.x), h: Math.abs(point.y - drag.start.y) };
            place(drag.ghost, box);
            drag.box = box;
        } else if (drag.kind === 'move') {
            const area = areaOf(drag.key);
            const dx = point.x - drag.start.x;
            const dy = point.y - drag.start.y;
            if (Math.abs(dx) + Math.abs(dy) > 0.2) drag.moved = true;
            area.x = clamp(drag.origin.x + dx, 0, 100 - area.w);
            area.y = clamp(drag.origin.y + dy, 0, 100 - area.h);
            place(layer.querySelector(`[data-area="${drag.key}"]`), area);
        } else if (drag.kind === 'resize') {
            const area = areaOf(drag.key);
            const o = drag.origin;
            let left = o.x;
            let top = o.y;
            let right = o.x + o.w;
            let bottom = o.y + o.h;
            if (drag.dir.includes('w')) left = clamp(point.x, 0, right - MIN);
            if (drag.dir.includes('e')) right = clamp(point.x, left + MIN, 100);
            if (drag.dir.includes('n')) top = clamp(point.y, 0, bottom - MIN);
            if (drag.dir.includes('s')) bottom = clamp(point.y, top + MIN, 100);
            Object.assign(area, { x: left, y: top, w: right - left, h: bottom - top });
            place(layer.querySelector(`[data-area="${drag.key}"]`), area);
        }
    });

    const finish = () => {
        if (!drag) return;
        const current = drag;
        drag = null;
        if (current.kind === 'draw') {
            current.ghost.remove();
            const box = current.box;
            if (box && box.w >= MIN && box.h >= MIN) {
                const target = current.target;
                const area = target.kind === 'room' ? { room_id: target.id, building: null } : { room_id: null, building: target.name };
                areas.push({ ...area, x: box.x, y: box.y, w: box.w, h: box.h });
                armed = null;
                selected = keyOf(area);
                setDirty();
            }
        } else if (current.kind === 'resize' || current.moved) {
            setDirty();
        }
        renderAreas();
    };
    layer.addEventListener('pointerup', finish);
    layer.addEventListener('pointercancel', finish);

    // ---- Hapus, geser halus, batal ----
    const removeSelected = () => {
        if (selected === null) return;
        areas = areas.filter((area) => keyOf(area) !== selected);
        selected = null;
        setDirty();
        renderAreas();
    };
    deleteButton.addEventListener('click', removeSelected);

    document.addEventListener('keydown', (event) => {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        if (event.key === 'Escape') { armed = null; selected = null; renderAreas(); return; }
        if (selected === null) return;
        if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); removeSelected(); return; }
        const step = event.shiftKey ? 1 : 0.1;
        const area = areaOf(selected);
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[event.key]) {
            event.preventDefault();
            area.x = clamp(area.x + moves[event.key][0], 0, 100 - area.w);
            area.y = clamp(area.y + moves[event.key][1], 0, 100 - area.h);
            setDirty();
            place(layer.querySelector(`[data-area="${selected}"]`), area);
        }
    });

    // ---- Tab Kamar | Gedung / area, dan nama gedung baru ----
    root.querySelectorAll('[data-kind-tab]').forEach((tab) => tab.addEventListener('click', () => {
        kind = tab.dataset.kindTab;
        root.querySelectorAll('[data-kind-tab]').forEach((other) => other.setAttribute('aria-selected', String(other === tab)));
        root.querySelectorAll('[data-pane]').forEach((pane) => {
            const on = pane.dataset.pane === kind;
            pane.classList.toggle('hidden', !on);
            pane.classList.toggle('flex', on);
        });
        armed = null;
        updateHint();
        renderLists();
    }));

    buildingForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const name = buildingNew.value.trim();
        if (!name) return;
        if (!buildings.has(name.toLowerCase())) buildings.set(name.toLowerCase(), { name, rooms: 0 });
        buildingNew.value = '';
        armed = { kind: 'building', name: buildings.get(name.toLowerCase()).name };
        selected = null;
        renderAreas();
    });

    // ---- Zoom ----
    const STEPS = [1, 1.5, 2, 3, 4];
    root.querySelectorAll('[data-zoom]').forEach((button) => button.addEventListener('click', () => {
        const index = STEPS.indexOf(zoom);
        if (button.dataset.zoom === 'fit') zoom = 1;
        if (button.dataset.zoom === 'in') zoom = STEPS[Math.min(STEPS.length - 1, index + 1)];
        if (button.dataset.zoom === 'out') zoom = STEPS[Math.max(0, index - 1)];
        stage.style.width = `${zoom * 100}%`;
    }));

    // ---- Simpan ----
    saveButton.addEventListener('click', async () => {
        saveButton.disabled = true;
        status.textContent = 'Menyimpan…';
        try {
            const response = await fetch(root.dataset.saveUrl, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ areas: areas.map((a) => ({ room_id: a.room_id, building: a.building, x: +a.x.toFixed(3), y: +a.y.toFixed(3), w: +a.w.toFixed(3), h: +a.h.toFixed(3) })) }),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.message || (json.errors ? Object.values(json.errors)[0][0] : 'Gagal menyimpan.'));
            dirty = false;
            status.textContent = `Tersimpan (${json.count} kotak)`;
        } catch (error) {
            status.textContent = error.message || 'Gagal menyimpan. Coba lagi.';
        }
        saveButton.disabled = false;
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });

    filter.addEventListener('input', renderLists);
    renderAreas();
    status.textContent = '';
}
