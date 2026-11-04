<x-app-layout title="{{ __('Peta kamar') }}">
    @include('laporan.partials.head', ['picker' => true, 'heading' => __('Peta kamar'), 'action' => route('laporan.rooms'), 'keep' => ['department_id' => $departmentId, 'category_id' => $categoryId]])
    @php $dateParams = ['from' => $from->toDateString(), 'to' => $to->toDateString()]; @endphp

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Kendala dicatat') }}</p><p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $report['total'] }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Belum selesai') }}</p><p class="mt-1 font-display text-3xl tabular-nums {{ $report['open'] ? 'text-rose-700' : 'text-slate-900' }}">{{ $report['open'] }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Kamar langganan masalah') }}</p><p class="mt-1 font-display text-3xl tabular-nums {{ count($report['repeat']) ? 'text-amber-800' : 'text-slate-900' }}">{{ count($report['repeat']) }}</p><p class="mt-1 text-xs text-slate-500">{{ $threshold }}× {{ __('atau lebih') }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Kendala berulang') }}</p><p class="mt-1 font-display text-3xl tabular-nums {{ $report['recurrences'] ? 'text-amber-800' : 'text-slate-900' }}">{{ $report['recurrences'] }}</p><p class="mt-1 text-xs text-slate-500">{{ __('Kategori sama di kamar sama dalam :n hari', ['n' => \App\Models\RoomIssue::RECURRENCE_DAYS]) }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">{{ __('Rata-rata penanganan') }}</p><p class="mt-1 font-display text-3xl tabular-nums text-slate-900">{{ $report['avgHours'] !== null ? $report['avgHours'].' '.__('jam') : '—' }}</p><p class="mt-1 text-xs text-slate-500">{{ __('Dari dicatat sampai selesai') }}</p></div>
    </div>

    <form method="GET" action="{{ route('laporan.rooms') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-2 print:hidden">
        <input type="hidden" name="from" value="{{ $from->toDateString() }}"><input type="hidden" name="to" value="{{ $to->toDateString() }}">
        <div>
            <label class="label" for="rooms-department">{{ __('Departemen penanggung jawab') }}</label>
            <select id="rooms-department" name="department_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label" for="rooms-category">{{ __('Kategori') }}</label>
            <select id="rooms-category" name="category_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach
            </select>
        </div>
    </form>

    {{-- Ganti tampilan: Denah (gambar denah hotel) atau Daftar (kotak per lantai). --}}
    @if ($plans->isNotEmpty())
        @php $modeQuery = $dateParams + ['department_id' => $departmentId, 'category_id' => $categoryId]; @endphp
        <nav class="mb-4 inline-flex overflow-hidden rounded-full border border-slate-300 bg-white print:hidden" aria-label="{{ __('Tampilan peta') }}">
            <a href="{{ route('laporan.rooms', $modeQuery + ['tampilan' => 'denah', 'denah' => $plan?->id]) }}" @class(['px-5 py-2 text-sm font-semibold', 'bg-ink text-white' => $mode === 'denah', 'text-slate-600 hover:bg-slate-50' => $mode !== 'denah']) @if ($mode === 'denah') aria-current="page" @endif>{{ __('Denah') }}</a>
            <a href="{{ route('laporan.rooms', $modeQuery + ['tampilan' => 'daftar']) }}" @class(['px-5 py-2 text-sm font-semibold', 'bg-ink text-white' => $mode === 'daftar', 'text-slate-600 hover:bg-slate-50' => $mode !== 'daftar']) @if ($mode === 'daftar') aria-current="page" @endif>{{ __('Daftar') }}</a>
        </nav>
    @endif

    @if ($mode === 'denah' && $plan)
        @vite(['resources/js/floor-plan-map.js'])
        <section class="card mb-6 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-display text-xl text-slate-900">{{ $plan->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Makin merah, makin sering bermasalah. Ketuk kamar untuk melihat riwayatnya.') }}</p>
                </div>
                @if ($plans->count() > 1)
                    <div class="flex flex-wrap gap-2 print:hidden">
                        @foreach ($plans as $option)
                            <a href="{{ route('laporan.rooms', $dateParams + ['department_id' => $departmentId, 'category_id' => $categoryId, 'tampilan' => 'denah', 'denah' => $option->id]) }}" @class(['rounded-full border px-4 py-1.5 text-sm', 'border-amber-700 bg-amber-50 font-semibold text-amber-900' => $option->id === $plan->id, 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' => $option->id !== $plan->id])>{{ $option->name }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($plan->areas->isEmpty())
                <div class="mx-5 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <span>{{ __('Belum ada kamar atau gedung yang ditandai di denah ini, jadi belum ada warna kendala.') }}</span>
                    @can('manage-tasks')<a class="font-semibold underline" href="{{ route('admin.plans.edit', $plan) }}">{{ __('Tandai sekarang') }} &rarr;</a>@endcan
                </div>
            @endif
            <div data-plan-map>
                <div class="flex flex-wrap items-center gap-3 px-5 pt-4 print:hidden">
                    <div class="inline-flex overflow-hidden rounded-full border border-slate-300 bg-white" role="group" aria-label="{{ __('Zoom') }}">
                        <button type="button" class="px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" data-zoom="out" aria-label="{{ __('Perkecil') }}">−</button>
                        <button type="button" class="border-x border-slate-200 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" data-zoom="fit">{{ __('Pas') }}</button>
                        <button type="button" class="px-3 py-1.5 text-sm font-semibold hover:bg-slate-50" data-zoom="in" aria-label="{{ __('Perbesar') }}">+</button>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-slate-500" aria-hidden="true">
                        <span>0</span><span class="h-2.5 w-24 rounded-full" style="background: linear-gradient(to right, rgba(148,163,184,0.25), rgba(190,18,60,0.9))"></span><span>{{ max(1, $report['max']) }}+ {{ __('kendala') }}</span>
                    </div>
                </div>
                <div class="m-5 overflow-auto rounded-2xl border border-slate-200 bg-slate-100" style="max-height: 80vh">
                    <div class="relative" style="width: 100%" data-stage>
                        <img src="{{ route('denah.image', $plan) }}" alt="{{ __('Denah :name', ['name' => $plan->name]) }}" width="{{ $plan->width }}" height="{{ $plan->height }}" class="block h-auto w-full max-w-none select-none">
                        @foreach ($plan->areas as $area)
                            @php
                                $isBuilding = $area->room_id === null;
                                $cell = $isBuilding ? ($report['buildings'][mb_strtolower($area->building)] ?? null) : ($report['rooms'][$area->room_id] ?? null);
                                $count = $cell['count'] ?? 0;
                                $top = $isBuilding ? $report['buildingMax'] : $report['max'];
                                $alpha = $count === 0 ? 0 : min(0.9, 0.2 + $count / max(1, $top) * 0.7);
                                $name = $isBuilding ? $area->building : ($area->room?->number ?? '');
                                $hint = ($isBuilding ? '' : __('Kamar').' ').$name.': '.$count.' '.__('kendala').(($cell['open'] ?? 0) ? ', '.$cell['open'].' '.__('belum selesai') : '').(($cell['top'] ?? null) ? ' · '.$cell['top'] : '').($isBuilding && ($cell['rooms'] ?? 0) ? ' · '.$cell['rooms'].' '.__('kamar') : '');
                                $href = $isBuilding ? route('laporan.building', ['nama' => $area->building] + $dateParams) : route('laporan.room', ['room' => $area->room_id] + $dateParams);
                            @endphp
                            <a href="{{ $href }}" title="{{ $hint }}" aria-label="{{ $hint }}"
                                class="absolute flex flex-col items-center justify-center overflow-hidden border text-center leading-none transition hover:z-10 hover:ring-2 hover:ring-amber-500 {{ $count === 0 ? 'border-slate-400/60 text-slate-700' : 'border-rose-900/40' }}"
                                style="left: {{ $area->x }}%; top: {{ $area->y }}%; width: {{ $area->w }}%; height: {{ $area->h }}%; background: {{ $count === 0 ? 'rgba(148,163,184,0.2)' : 'rgba(190,18,60,'.$alpha.')' }}; color: {{ $alpha >= 0.5 ? '#fff' : '#334155' }}; font-size: clamp(7px, 1.1vw, 13px)">
                                <span class="max-w-full truncate px-0.5 font-bold">{{ $name }}</span>
                                @if ($count)<span class="opacity-90">{{ $count }}×</span>@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            @php
                $onPlan = $plans->flatMap->areas->pluck('room_id')->filter()->all();
                $buildingsOnPlan = $plans->flatMap->areas->pluck('building')->filter()->map(fn ($b) => mb_strtolower($b))->all();
                $others = collect($report['rooms'])->filter(fn ($r) => ! in_array($r['id'], $onPlan, true) && ! (filled($r['building']) && in_array(mb_strtolower($r['building']), $buildingsOnPlan, true)) && $r['count'] > 0);
            @endphp
            @if ($others->isNotEmpty())
                <div class="border-t border-slate-100 p-5">
                    <p class="mb-2 text-sm font-semibold text-slate-700">{{ __('Belum ada di denah') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($others as $room)
                            <a href="{{ route('laporan.room', ['room' => $room['id']] + $dateParams) }}" class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ $room['number'] }} · {{ $room['count'] }}×</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @else
    <section class="card mb-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-display text-xl text-slate-900">{{ __('Peta per lantai') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Satu kotak satu kamar. Makin merah, makin sering bermasalah. Ketuk kamar untuk melihat riwayatnya.') }}</p>
        </div>
        @forelse ($report['floors'] as $floor)
            <div class="border-b border-slate-100 p-5 last:border-0">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $floor['floor'] === '—' ? __('Tanpa lantai') : __('Lantai').' '.$floor['floor'] }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($floor['rooms'] as $room)
                        @php
                            $alpha = $room['count'] === 0 ? 0 : min(0.9, 0.18 + $room['count'] / max(1, $report['max']) * 0.72);
                            $hint = __('Kamar').' '.$room['number'].': '.$room['count'].' '.__('kendala').($room['open'] ? ', '.$room['open'].' '.__('belum selesai') : '').($room['top'] ? ' · '.$room['top'] : '');
                        @endphp
                        <a href="{{ route('laporan.room', ['room' => $room['id']] + $dateParams) }}"
                            class="flex h-12 w-14 flex-col items-center justify-center rounded-lg border text-xs tabular-nums transition hover:ring-2 hover:ring-amber-400 {{ $room['count'] === 0 ? 'border-slate-200 bg-slate-50 text-slate-400' : 'border-transparent' }}"
                            @if ($room['count']) style="background: rgba(190, 18, 60, {{ $alpha }}); color: {{ $alpha >= 0.5 ? '#fff' : '#4a1020' }}" @endif
                            title="{{ $hint }}">
                            <span class="font-semibold">{{ $room['number'] }}</span>
                            @if ($room['count'])<span class="text-[10px] opacity-90">{{ $room['count'] }}×</span>@endif
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="p-10 text-center">
                <p class="font-medium text-slate-800">{{ __('Belum ada data kamar.') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Tambahkan daftar kamar di menu Data kamar, lalu catat kendala di menu Kendala kamar.') }}</p>
            </div>
        @endforelse
    </section>

    @endif

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <section class="card overflow-hidden">
            <div class="border-b border-slate-100 p-5">
                <h2 class="font-display text-xl text-slate-900">{{ __('Kamar langganan masalah') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Kamar dengan :n kendala atau lebih pada rentang ini.', ['n' => $threshold]) }}</p>
            </div>
            @forelse ($report['repeat'] as $room)
                <a href="{{ route('laporan.room', ['room' => $room['id']] + $dateParams) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 text-sm transition last:border-0 hover:bg-amber-50/50">
                    <span><span class="font-display text-lg text-slate-900">{{ $room['number'] }}</span> <span class="text-slate-500">{{ $room['floor'] !== '—' ? __('Lantai').' '.$room['floor'] : '' }}</span>@if ($room['top'])<span class="block text-xs text-slate-500">{{ __('Paling sering') }}: {{ $room['top'] }}</span>@endif</span>
                    <span class="rounded-full bg-rose-50 px-3 py-1 font-semibold text-rose-700">{{ $room['count'] }}×</span>
                </a>
            @empty
                <p class="p-8 text-center text-sm text-slate-500">{{ __('Tidak ada kamar yang berulang kali bermasalah.') }}</p>
            @endforelse
        </section>

        <div class="space-y-6">
            @foreach ([['categories', __('Per kategori')], ['departments', __('Per departemen penanggung jawab')]] as [$key, $title])
                <section class="card overflow-hidden">
                    <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ $title }}</h2>
                    @php $maxCount = max(1, collect($report[$key])->max('count')); @endphp
                    @forelse ($report[$key] as $row)
                        <div class="border-b border-slate-100 px-5 py-3 text-sm last:border-0">
                            <div class="flex justify-between"><span class="text-slate-800">{{ $row['name'] }}</span><span class="tabular-nums text-slate-600">{{ $row['count'] }}@if ($row['open']) <span class="text-rose-700">({{ $row['open'] }} {{ __('belum selesai') }})</span>@endif</span></div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-rose-500" style="width: {{ $row['count'] / $maxCount * 100 }}%"></div></div>
                        </div>
                    @empty
                        <p class="p-6 text-center text-sm text-slate-500">{{ __('Belum ada data.') }}</p>
                    @endforelse
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
