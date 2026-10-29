<x-app-layout title="Akses pengguna">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-3xl text-slate-900">Akses pengguna</h1>
            <p class="text-sm text-slate-500">
                Data pegawai dari Sistem Absensi.
                @if ($lastSync)
                    Sinkron terakhir {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->format('d M Y H:i') }}.
                @endif
            </p>
        </div>
        <form method="POST" action="{{ route('admin.sync') }}">
            @csrf
            <button class="btn-secondary">Sinkron dari absensi</button>
        </form>
    </div>
    @error('sync') <p class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-end gap-4 border-b border-slate-100 p-4">
            <div class="min-w-64 flex-1">
                <label for="access-search" class="label">Cari pegawai</label>
                <input id="access-search" type="search" placeholder="Nama, username, departemen, peran, atau jabatan" class="input" data-access-search>
            </div>
            <div class="min-w-44">
                <label for="access-department" class="label">Departemen</label>
                <select id="access-department" class="input" data-filter="department">
                    <option value="">Semua departemen</option>
                    @foreach ($employees->map(fn ($e) => $e->department?->name)->filter()->unique()->sort() as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-44">
                <label for="access-role" class="label">Peran</label>
                <select id="access-role" class="input" data-filter="role">
                    <option value="">Semua peran</option>
                    @foreach (\App\Enums\Role::cases() as $role)
                        <option value="{{ $role->label() }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-44">
                <label for="access-status" class="label">Akses</label>
                <select id="access-status" class="input" data-filter="access">
                    <option value="">Semua</option>
                    <option value="1">Punya akses</option>
                    <option value="0">Tanpa akses</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-[#fbf6ea] text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                    <tr>
                        @foreach (['name' => 'Pegawai', 'department' => 'Departemen', 'role' => 'Peran', 'position' => 'Jabatan checklist'] as $key => $label)
                            <th class="px-4 py-3" data-sort-th="{{ $key }}" aria-sort="none">
                                <button type="button" class="inline-flex items-center gap-1.5 hover:text-slate-900" data-sort="{{ $key }}">
                                    {{ $label }}
                                    <span class="text-[10px] text-slate-300" aria-hidden="true" data-sort-icon>↕</span>
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" data-access-body>
                    @foreach ($employees as $employee)
                        @php
                            $hasAccess = (bool) $employee->user?->is_active;
                            $role = $hasAccess ? $employee->user->role->label() : '';
                            $position = $employee->user?->position?->name ?? '';
                            $department = $employee->department?->name ?? '';
                        @endphp
                        <tr class="{{ $employee->is_active ? '' : 'text-slate-400' }}"
                            data-access-row
                            data-name="{{ $employee->name }}"
                            data-department="{{ $department }}"
                            data-role="{{ $role }}"
                            data-position="{{ $position }}"
                            data-has-access="{{ $hasAccess ? '1' : '0' }}"
                            data-search="{{ mb_strtolower(implode(' ', array_filter([$employee->name, $employee->username, $department, $role, $position]))) }}">
                            <td class="px-4 py-2.5">
                                <a href="{{ route('admin.access.edit', $employee) }}" class="font-medium text-sky-700 hover:underline">{{ $employee->name }}</a>
                                <span class="block text-xs text-slate-500">{{ $employee->username }}{{ $employee->is_active ? '' : ' · nonaktif di absensi' }}</span>
                            </td>
                            <td class="px-4 py-2.5">{{ $department ?: '—' }}</td>
                            <td class="px-4 py-2.5">
                                @if ($hasAccess)
                                    {{ $role }}
                                    @if ($employee->user->departments->isNotEmpty())
                                        <span class="block text-xs text-slate-500">{{ $employee->user->departments->pluck('code')->implode(', ') }}</span>
                                    @endif
                                @else
                                    <span class="text-slate-400">Tanpa akses</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">{{ $position ?: '—' }}</td>
                        </tr>
                    @endforeach
                    <tr class="hidden" data-access-empty><td colspan="4" class="px-4 py-10 text-center text-slate-500">Tidak ada pegawai yang cocok.</td></tr>
                </tbody>
            </table>
            @if ($employees->isEmpty())
                <p class="px-4 py-10 text-center text-slate-500">Belum ada data pegawai. Tekan "Sinkron dari absensi".</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label class="flex items-center gap-2" for="access-per-page">
                    Tampilkan
                    <select id="access-per-page" class="input !w-20 !py-1.5" data-per-page>
                        @foreach ([10, 25, 50, 100] as $option)
                            <option value="{{ $option }}" @selected($option === 25)>{{ $option }}</option>
                        @endforeach
                    </select>
                    baris
                </label>
                <p data-access-info></p>
            </div>
            <nav class="flex items-center gap-1.5" aria-label="Halaman" data-access-pager></nav>
        </div>
    </div>

    <script>
        (() => {
            const body = document.querySelector('[data-access-body]');
            const rows = [...body.querySelectorAll('[data-access-row]')];
            const search = document.querySelector('[data-access-search]');
            const filters = [...document.querySelectorAll('[data-filter]')];
            const perPage = document.querySelector('[data-per-page]');
            const info = document.querySelector('[data-access-info]');
            const pager = document.querySelector('[data-access-pager]');
            const empty = document.querySelector('[data-access-empty]');
            const headers = [...document.querySelectorAll('[data-sort-th]')];
            let sortKey = null;
            let direction = 1;
            let page = 1;

            const compare = (a, b) => a.dataset[sortKey].localeCompare(b.dataset[sortKey], 'id', { numeric: true, sensitivity: 'base' }) * direction;

            const pageButton = (label, target, { current = false, disabled = false } = {}) => {
                if (current) {
                    const span = document.createElement('span');
                    span.textContent = label;
                    span.setAttribute('aria-current', 'page');
                    span.className = 'flex h-9 min-w-9 items-center justify-center rounded-full bg-ink px-3 font-semibold text-white';
                    return span;
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                button.disabled = disabled;
                button.className = 'rounded-full border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 hover:border-gold-500 disabled:border-slate-200 disabled:text-slate-300';
                button.addEventListener('click', () => {
                    page = target;
                    render();
                });
                return button;
            };

            const render = () => {
                const query = search.value.trim().toLowerCase();
                const matched = rows.filter((row) => (query === '' || row.dataset.search.includes(query))
                    && filters.every((select) => select.value === '' || (select.dataset.filter === 'access'
                        ? row.dataset.hasAccess === select.value
                        : row.dataset[select.dataset.filter] === select.value)));
                const size = Number(perPage.value);
                const pages = Math.max(1, Math.ceil(matched.length / size));
                page = Math.min(page, pages);
                const from = (page - 1) * size;
                const shown = new Set(matched.slice(from, from + size));

                body.append(...rows); // urutan tampilan mengikuti sortir
                rows.forEach((row) => { row.hidden = !shown.has(row); });
                empty.classList.toggle('hidden', matched.length > 0 || rows.length === 0);

                const first = matched.length ? from + 1 : 0;
                const last = Math.min(from + size, matched.length);
                info.innerHTML = `${first}–${last} dari <strong class="text-slate-900">${matched.length}</strong> pegawai · Halaman <strong class="text-slate-900">${page}</strong> dari <strong class="text-slate-900">${pages}</strong>`;

                pager.replaceChildren(pageButton('← Sebelumnya', page - 1, { disabled: page === 1 }));
                for (let n = Math.max(1, page - 2); n <= Math.min(pages, page + 2); n++) {
                    pager.append(pageButton(String(n), n, { current: n === page }));
                }
                pager.append(pageButton('Berikutnya →', page + 1, { disabled: page === pages }));

                headers.forEach((th) => {
                    const icon = th.querySelector('[data-sort-icon]');
                    const active = th.dataset.sortTh === sortKey;
                    th.setAttribute('aria-sort', active ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                    icon.textContent = active ? (direction === 1 ? '▲' : '▼') : '↕';
                    icon.className = `text-[10px] ${active ? 'text-gold-700' : 'text-slate-300'}`;
                });
            };

            search.addEventListener('input', () => { page = 1; render(); });
            filters.forEach((select) => select.addEventListener('change', () => { page = 1; render(); }));
            perPage.addEventListener('change', () => { page = 1; render(); });
            headers.forEach((th) => th.querySelector('[data-sort]').addEventListener('click', () => {
                const key = th.dataset.sortTh;
                direction = sortKey === key ? -direction : 1;
                sortKey = key;
                rows.sort(compare);
                page = 1;
                render();
            }));

            render();
        })();
    </script>
</x-app-layout>
