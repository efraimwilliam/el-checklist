<x-app-layout title="Checklist saya">
    <div class="card p-6">
        <h1 class="text-2xl text-slate-900">Checklist hari ini</h1>
        <p class="mt-2 text-sm text-slate-600">Jabatan checklist Anda belum diatur, jadi daftar tugas belum bisa dibuat. Hubungi Administrator.</p>
    </div>
    @include('checklists.partials.history')
</x-app-layout>
