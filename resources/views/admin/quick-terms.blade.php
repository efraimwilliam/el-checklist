<x-app-layout title="{{ __('Kamus ketik bebas') }}">
    @php
        $box = fn (string $group, ?string $target = null) => $terms->get($group.'|'.$target, collect());
        $tones = ['request' => 'border-sky-600 bg-sky-50/60', 'issue' => 'border-amber-500 bg-amber-50/60'];
        $activeKinds = $kinds->where('is_active', true);
        $inactiveKinds = $kinds->where('is_active', false);
        $activeCategories = $categories->where('is_active', true);
        $inactiveCategories = $categories->where('is_active', false);
        $wordTotal = $terms->flatten()->count();
        // Tab yang dibuka: sesuai form terakhir (gagal simpan), selain itu Permintaan; JavaScript juga membaca #anchor.
        $slotGroup = explode('|', (string) session('quick_slot'))[0];
        $tab = match (true) {
            old('category_form') !== null || $slotGroup === 'issue' => 'kendala',
            in_array($slotGroup, ['request_word', 'urgent', 'ignore'], true) => 'bantu',
            default => 'permintaan',
        };
        $tabs = [
            'permintaan' => ['🛎', __('Permintaan tamu'), __('Permintaan'), $activeKinds->count().' '.__('jenis')],
            'kendala' => ['🛠', __('Kendala'), __('Kendala'), $activeCategories->count().' '.__('kategori')],
            'bantu' => ['💬', __('Kata bantu'), __('Kata bantu'), __('minta · mendesak · abaikan')],
        ];
        $row = fn (array $extra) => $extra + ['kind' => null, 'category' => null, 'help' => null]; // isi yang diberikan menang
    @endphp

    <a href="{{ route('admin.settings.edit') }}#ketik" class="link-back"><span aria-hidden="true">‹</span> {{ __('Pengaturan') }}</a>
    <div class="mb-5 mt-1 flex flex-wrap items-end justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-page">{{ __('Kamus ketik bebas') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Kata yang dibaca sistem saat petugas mengetik di obrolan, mis. "1206 req 2 mw" atau "305 ac tidak dingin". Perubahan langsung berlaku.') }}</p>
        </div>
        <p class="flex flex-wrap gap-2 text-xs text-slate-600">
            <span class="rounded-full bg-white px-3 py-1 ring-1 ring-slate-200"><b class="tabular-nums">{{ $activeKinds->count() }}</b> {{ __('jenis permintaan') }}</span>
            <span class="rounded-full bg-white px-3 py-1 ring-1 ring-slate-200"><b class="tabular-nums">{{ $activeCategories->count() }}</b> {{ __('kategori kendala') }}</span>
            <span class="rounded-full bg-white px-3 py-1 ring-1 ring-slate-200"><b class="tabular-nums">{{ $wordTotal }}</b> {{ __('kata') }}</span>
        </p>
    </div>

    {{-- Coba kalimat: hasil bacaan memakai kamus di halaman ini. --}}
    <section class="mb-6 rounded-2xl bg-ink p-5 text-white shadow-sm">
        <form method="GET" action="{{ route('admin.quick-terms.index') }}" class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label for="coba" class="shrink-0 text-sm font-semibold text-gold-400 sm:w-32">{{ __('Coba kalimat') }}</label>
            <input id="coba" name="coba" value="{{ $test }}" maxlength="500" class="input flex-1 !border-white/10 !bg-white/10 !text-white placeholder:!text-white/40" placeholder="{{ __('mis. 1206 req 2 mw + 1 bt, tamu di kamar') }}">
            <button class="btn-primary shrink-0">{{ __('Baca') }}</button>
        </form>
        @if ($result)
            <div @class(['mt-3 rounded-xl border-l-4 px-4 py-3 text-sm text-slate-800', $tones[$result['type']] ?? 'border-slate-300 bg-white/90']) role="status">
                @if ($result['type'] === 'request')
                    <p class="font-semibold">🛎 {{ __('Permintaan') }} · {{ __('Kamar') }} {{ $result['room_number'] }}</p>
                    <p>{{ collect($result['items'])->map(fn ($i) => (new \App\Models\GuestRequest(['kind' => $i['kind'], 'item_name' => $i['name'], 'quantity' => $i['quantity']]))->label())->implode(', ') }}</p>
                @elseif ($result['type'] === 'issue')
                    <p class="font-semibold">🛠 {{ __('Kendala') }} · {{ __('Kamar') }} {{ $result['room_number'] }}</p>
                    <p>{{ $result['category']?->name ?? __('Kategori belum terbaca') }}</p>
                @else
                    <p class="font-semibold">{{ __('Tidak terbaca sebagai permintaan atau kendala.') }}</p>
                    <p class="text-slate-600">{{ __('Pesan seperti ini terkirim sebagai teks biasa. Pastikan ada nomor kamar (3-4 angka) dan kata minta / sebutan barang / tanda kendala.') }}</p>
                @endif
                @if ($result['type'] && $result['priority'] === 'mendesak')<p class="mt-1"><span class="rounded-full bg-rose-600 px-2 py-0.5 text-[11px] font-semibold text-white">{{ __('Mendesak') }}</span></p>@endif
                @foreach ($result['warnings'] as $warning)<p class="mt-1 text-rose-700">{{ $warning }}</p>@endforeach
            </div>
        @endif
    </section>

    {{-- Tab + cari kata. --}}
    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between" data-kamus-tabs data-active="{{ $tab }}">
        <div class="grid grid-cols-3 gap-1 rounded-2xl bg-gold-100 p-1" role="tablist">
            @foreach ($tabs as $key => [$icon, $label, $short, $meta])
                <button type="button" role="tab" data-tab="{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        class="rounded-xl px-2 py-2 text-center text-sm transition aria-selected:bg-white aria-selected:shadow-sm sm:px-4 sm:text-left">
                    <span class="block whitespace-nowrap font-semibold text-slate-900"><span aria-hidden="true">{{ $icon }}</span> <span class="sm:hidden">{{ $short }}</span><span class="hidden sm:inline">{{ $label }}</span></span>
                    <span class="hidden text-xs text-slate-500 sm:block">{{ $meta }}</span>
                </button>
            @endforeach
        </div>
        <div class="relative lg:w-80">
            <label for="kamus-cari" class="sr-only">{{ __('Cari kata') }}</label>
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
            <input id="kamus-cari" type="search" data-kamus-search autocomplete="off" class="input w-full !pl-9" placeholder="{{ __('Cari kata, mis. mw') }}">
        </div>
    </div>
    <p hidden data-kamus-empty class="mb-4 rounded-xl bg-white px-4 py-6 text-center text-sm text-slate-500 ring-1 ring-slate-200">{{ __('Kata itu belum ada di kamus.') }}</p>

    {{-- 🛎 Permintaan tamu --}}
    <section data-panel="permintaan" @if ($tab !== 'permintaan') hidden @endif class="space-y-2">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-500">{{ __('Sebutan barang per jenis. Barang yang tidak ada di daftar tetap tercatat sebagai "Lainnya" bila ada kata minta.') }}</p>
            <button type="button" class="btn-primary !px-4 !py-2 text-sm" data-dialog-open="kind-dialog">+ {{ __('Jenis permintaan baru') }}</button>
        </div>
        @foreach ($activeKinds as $kind)
            @include('admin.partials.quick-term-row', $row(['group' => 'item', 'target' => $kind->key, 'kind' => $kind, 'title' => __($kind->label), 'terms' => $box('item', $kind->key),
                'help' => $kind->key === 'other' ? __('Barang lain yang sering diminta; namanya ikut tersimpan (mis. "Lainnya: duvet").') : null]))
        @endforeach
        @if ($inactiveKinds->isNotEmpty())
            <p class="pt-4 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Jenis nonaktif') }} ({{ $inactiveKinds->count() }})</p>
            @foreach ($inactiveKinds as $kind)
                @include('admin.partials.quick-term-row', $row(['group' => 'item', 'target' => $kind->key, 'kind' => $kind, 'title' => __($kind->label), 'terms' => $box('item', $kind->key)]))
            @endforeach
        @endif
    </section>

    {{-- 🛠 Kendala --}}
    <section data-panel="kendala" @if ($tab !== 'kendala') hidden @endif class="space-y-2">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-500">{{ __('Tanda kendala per kategori. Bila beberapa cocok, yang muncul paling awal di pesan yang dipakai ("minibar tidak dingin" = Minibar).') }}</p>
            <button type="button" class="btn-primary !px-4 !py-2 text-sm" data-dialog-open="category-dialog">+ {{ __('Kategori kendala baru') }}</button>
        </div>
        @foreach ($activeCategories as $category)
            @include('admin.partials.quick-term-row', $row(['group' => 'issue', 'target' => (string) $category->id, 'category' => $category, 'title' => $category->name, 'terms' => $box('issue', (string) $category->id)]))
        @endforeach
        @if ($inactiveCategories->isNotEmpty())
            <p class="pt-4 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ __('Kategori nonaktif') }} ({{ $inactiveCategories->count() }})</p>
            @foreach ($inactiveCategories as $category)
                @include('admin.partials.quick-term-row', $row(['group' => 'issue', 'target' => (string) $category->id, 'category' => $category, 'title' => $category->name, 'terms' => $box('issue', (string) $category->id)]))
            @endforeach
        @endif
    </section>

    {{-- 💬 Kata bantu --}}
    <section data-panel="bantu" @if ($tab !== 'bantu') hidden @endif class="space-y-2">
        <p class="mb-3 text-sm text-slate-500">{{ __('Kata yang berlaku untuk semua jenis dan kategori.') }}</p>
        @include('admin.partials.quick-term-row', $row(['group' => 'request_word', 'target' => null, 'title' => __('Kata minta'), 'help' => __('Menandai pesan sebagai permintaan, mis. req, minta, need.'), 'terms' => $box('request_word')]))
        @include('admin.partials.quick-term-row', $row(['group' => 'urgent', 'target' => null, 'title' => __('Tanda mendesak'), 'help' => __('Membuat permintaan / kendala berprioritas Mendesak, mis. "tamu di kamar", "segera".'), 'terms' => $box('urgent')]))
        @include('admin.partials.quick-term-row', $row(['group' => 'ignore', 'target' => null, 'title' => __('Bukan kendala'), 'help' => __('Frasa yang diabaikan saat mencari kendala, mis. "shower room" (ruang mandi tamu), "hair dryer".'), 'terms' => $box('ignore')]))
        @include('admin.partials.quick-term-row', $row(['group' => 'lost', 'target' => null, 'title' => __('Barang tertinggal'), 'help' => __('Pesan / foto berketerangan dengan nomor kamar dan salah satu kata ini dicatat sebagai barang tertinggal, mis. "1203 complete tertinggal".'), 'terms' => $box('lost')]))
        <div class="mt-4 rounded-xl bg-white p-4 text-sm text-slate-600 ring-1 ring-slate-200">
            <p class="font-semibold text-slate-900">{{ __('Cara menulis kata') }}</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                <li>{{ __('Huruf kecil; spasi di tengah boleh ada atau tidak ("tamu di kamar" = "tamu dikamar").') }}</li>
                <li>{{ __('Akhiran -nya ikut terbaca ("showernya"); "*" di akhir = boleh diikuti huruf apa pun ("towel*" = towels).') }}</li>
                <li>{{ __('Kata harus berdiri sendiri: "ac" tidak cocok di dalam "acara".') }}</li>
                <li>{{ __('Tetap diatur sistem: nomor kamar, jumlah, inisial petugas (*eka), "no need X", "laundry" saja = kabar status, pesan "Q" bukan kendala.') }}</li>
            </ul>
        </div>
    </section>

    {{-- Dialog: jenis permintaan baru. --}}
    @php($newKindOpen = old('kind_form') === 'new')
    <dialog id="kind-dialog" @if ($newKindOpen) data-autoopen @endif aria-labelledby="kind-dialog-title" class="dialog-panel w-[min(32rem,calc(100vw-2rem))]">
        <form method="POST" action="{{ route('admin.quick-terms.kinds.store') }}" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="kind_form" value="new">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="kind-dialog-title" class="text-section">🛎 {{ __('Jenis permintaan baru') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Langsung muncul di dialog Catat permintaan, ketik bebas di obrolan, kartu obrolan, dan halaman Permintaan.') }}</p>
                </div>
                <button type="button" class="dialog-close" aria-label="{{ __('Tutup') }}" data-dialog-close>×</button>
            </div>
            <div>
                <label class="label" for="new-kind-label">{{ __('Nama jenis') }}</label>
                <input id="new-kind-label" name="label" maxlength="80" required class="input w-full" value="{{ $newKindOpen ? old('label') : '' }}" placeholder="{{ __('mis. Kabel LAN') }}">
            </div>
            <div>
                <label class="label" for="new-kind-dept">{{ __('Diteruskan ke') }}</label>
                <select id="new-kind-dept" name="department_id" class="input w-full">
                    <option value="">{{ __('Housekeeping (bawaan)') }}</option>
                    @foreach ($departments as $department)
                        @continue($department->id === $defaultDepartmentId)
                        <option value="{{ $department->id }}" @selected($newKindOpen && (int) old('department_id') === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="new-kind-terms">{{ __('Kata yang dibaca (pisahkan dengan koma)') }}</label>
                <input id="new-kind-terms" name="terms" maxlength="500" class="input w-full" value="{{ $newKindOpen ? old('terms') : '' }}" placeholder="{{ __('mis. lan cable, kabel lan, lan') }}">
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="has_quantity" value="1" class="h-5 w-5" @checked($newKindOpen ? old('has_quantity') : true)> {{ __('Pakai jumlah (mis. "2 lan cable")') }}</label>
            @if ($newKindOpen && $errors->kind->any())<p class="text-sm text-rose-700">{{ $errors->kind->first() }}</p>@endif
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" data-dialog-close>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Tambah jenis') }}</button>
            </div>
        </form>
    </dialog>

    {{-- Dialog: kategori kendala baru (tabel yang sama dengan Data kamar > Kategori kendala). --}}
    @php($newCategoryOpen = old('category_form') === 'new')
    <dialog id="category-dialog" @if ($newCategoryOpen) data-autoopen @endif aria-labelledby="category-dialog-title" class="dialog-panel w-[min(32rem,calc(100vw-2rem))]">
        <form method="POST" action="{{ route('admin.quick-terms.categories.store') }}" class="space-y-4 p-6">
            @csrf
            <input type="hidden" name="category_form" value="new">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="category-dialog-title" class="text-section">🛠 {{ __('Kategori kendala baru') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Langsung muncul di dialog Catat kendala, ketik bebas di obrolan, Peta kamar, dan Data kamar.') }}</p>
                </div>
                <button type="button" class="dialog-close" aria-label="{{ __('Tutup') }}" data-dialog-close>×</button>
            </div>
            <div>
                <label class="label" for="new-category-name">{{ __('Nama kategori') }}</label>
                <input id="new-category-name" name="name" maxlength="100" required class="input w-full" value="{{ $newCategoryOpen ? old('name') : '' }}" placeholder="{{ __('mis. IT & jaringan') }}">
            </div>
            <div>
                <label class="label" for="new-category-dept">{{ __('Departemen penanggung jawab') }}</label>
                <select id="new-category-dept" name="department_id" class="input w-full">
                    <option value="">{{ __('Belum ditentukan (dipilih saat mencatat)') }}</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected($newCategoryOpen && (int) old('department_id') === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="new-category-terms">{{ __('Kata yang dibaca (pisahkan dengan koma)') }}</label>
                <input id="new-category-terms" name="terms" maxlength="500" class="input w-full" value="{{ $newCategoryOpen ? old('terms') : '' }}" placeholder="{{ __('mis. wifi, internet, lan, router') }}">
            </div>
            @if ($newCategoryOpen && $errors->category->any())<p class="text-sm text-rose-700">{{ $errors->category->first() }}</p>@endif
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" data-dialog-close>{{ __('Batal') }}</button>
                <button class="btn-primary">{{ __('Tambah kategori') }}</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const tabs = document.querySelector('[data-kamus-tabs]');
            const buttons = [...tabs.querySelectorAll('[data-tab]')];
            const panels = [...document.querySelectorAll('[data-panel]')];
            const search = document.querySelector('[data-kamus-search]');
            const empty = document.querySelector('[data-kamus-empty]');
            const rows = [...document.querySelectorAll('[data-term-row]')];

            const show = (key) => {
                buttons.forEach((button) => button.setAttribute('aria-selected', String(button.dataset.tab === key)));
                panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== key; });
                try { sessionStorage.setItem('kamus-tab', key); } catch { /* penyimpanan tidak tersedia */ }
            };
            buttons.forEach((button) => button.addEventListener('click', () => { search.value = ''; filter(); show(button.dataset.tab); }));

            // Cari kata di semua tab: baris yang memuat kata itu dibuka dan katanya disorot; tab yang punya hasil ditandai.
            const filter = () => {
                const query = search.value.trim().toLowerCase();
                let hits = 0;
                rows.forEach((row) => {
                    const match = query === '' || row.dataset.words.toLowerCase().includes(query);
                    row.hidden = !match;
                    if (match && query !== '') { row.open = true; hits++; }
                    row.querySelectorAll('[data-term-chip]').forEach((chip) => {
                        const on = query !== '' && chip.dataset.termChip.includes(query);
                        chip.classList.toggle('!bg-amber-100', on);
                        chip.classList.toggle('!ring-amber-400', on);
                    });
                });
                if (query !== '') {
                    panels.forEach((panel) => { panel.hidden = !panel.querySelector('[data-term-row]:not([hidden])'); });
                    panels.forEach((panel) => panel.querySelectorAll(':scope > p.pt-4').forEach((label) => { label.hidden = true; }));
                    buttons.forEach((button) => button.setAttribute('aria-selected', 'false'));
                } else {
                    panels.forEach((panel) => panel.querySelectorAll(':scope > p.pt-4').forEach((label) => { label.hidden = false; }));
                    const current = buttons.find((button) => button.getAttribute('aria-selected') === 'true')?.dataset.tab;
                    show(current || tabs.dataset.active);
                }
                empty.hidden = query === '' || hits > 0;
            };
            search.addEventListener('input', filter);

            // Tab awal: dari #anchor (setelah simpan), dari server (form gagal), atau yang terakhir dibuka.
            const anchor = location.hash ? document.querySelector(location.hash) : null;
            const fromAnchor = anchor?.closest('[data-panel]')?.dataset.panel;
            let remembered = null;
            try { remembered = sessionStorage.getItem('kamus-tab'); } catch { /* penyimpanan tidak tersedia */ }
            show(fromAnchor || (tabs.dataset.active !== 'permintaan' ? tabs.dataset.active : remembered) || 'permintaan');
            if (anchor && anchor.matches('[data-term-row]')) { anchor.open = true; anchor.scrollIntoView({ block: 'center' }); }

            document.querySelectorAll('[data-dialog-open]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen).showModal()));
            document.querySelectorAll('dialog').forEach((dialog) => {
                dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
                if (dialog.hasAttribute('data-autoopen')) dialog.showModal();
            });
        })();
    </script>
</x-app-layout>
