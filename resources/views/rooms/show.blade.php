<x-app-layout title="{{ __('Track record') }} {{ $placeName }}">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('kamar.issues.index') }}" class="text-sm text-sky-700 hover:underline">{{ __('← Kembali') }}</a>
    <div class="mb-5 mt-1">
        <h1 class="text-3xl text-slate-900">{{ __('Track record') }} {{ $room ? __('kamar') : '' }} {{ $placeName }}</h1>
        <p class="text-sm text-slate-500">{{ $room?->floor ? __('Lantai').' '.$room->floor.' · ' : '' }}{{ $room?->type ? $room->type.' · ' : '' }}{{ $issues->count() }} {{ __('kendala tercatat, termasuk pekerjaan yang sudah dilakukan.') }}</p>
    </div>

    <form method="GET" action="{{ $room ? route('kamar.room', $room) : route('kamar.place') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-2">
        @unless ($room)<input type="hidden" name="nama" value="{{ $placeName }}">@endunless
        <div>
            <label class="label" for="record-category">{{ __('Kategori') }}</label>
            <select id="record-category" name="category_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label" for="record-department">{{ __('Departemen') }}</label>
            <select id="record-department" name="department_id" class="input w-full" onchange="this.form.submit()">
                <option value="">{{ __('Semua') }}</option>
                @foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach
            </select>
        </div>
    </form>

    @forelse ($issues->groupBy(fn ($i) => $i->category?->name ?? __('Tanpa kategori')) as $categoryName => $items)
        <section class="card mb-5 overflow-hidden">
            <h2 class="border-b border-slate-100 p-5 font-display text-xl text-slate-900">{{ $categoryName }} <span class="text-sm font-normal text-slate-500">({{ $items->count() }}×)</span></h2>
            <ul class="space-y-2 p-5">@foreach ($items as $past) @include('rooms.partials.record-item', ['past' => $past]) @endforeach</ul>
        </section>
    @empty
        <div class="card p-10 text-center">
            <p class="font-medium text-slate-800">{{ __('Belum ada kendala di lokasi ini.') }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ ($categoryId || $departmentId) ? __('Tidak ada yang cocok dengan filter ini.') : __('Track record akan terisi setelah ada kendala yang dicatat.') }}</p>
        </div>
    @endforelse
</x-app-layout>
