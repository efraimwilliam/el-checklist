<?php

namespace App\Http\Controllers\Admin;

use App\Chat\QuickEntry;
use App\Http\Controllers\Controller;
use App\Models\GuestRequest;
use App\Models\GuestRequestKind;
use App\Models\IssueCategory;
use App\Models\QuickTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kamus ketik bebas (Administrator, Pengaturan > Fitur > Kamus ketik bebas): kata yang membuat pesan obrolan terbaca sebagai
 * permintaan ("req"), sebutan barang per jenis ("mw" = Air mineral), tanda kendala per kategori ("ac" = AC), tanda mendesak,
 * dan frasa yang bukan kendala ("shower room"). Ada kotak "Coba kalimat" untuk melihat hasil bacaan sebelum dipakai petugas.
 */
class QuickTermController extends Controller
{
    public function index(Request $request, QuickEntry $quick): View
    {
        Gate::authorize('manage-access');
        $test = trim((string) $request->query('coba', ''));

        return view('admin.quick-terms', [
            'terms' => QuickTerm::orderBy('term')->get()->groupBy(fn (QuickTerm $t) => $t->group.'|'.$t->target),
            'kinds' => GuestRequest::kinds()->all(),
            'departments' => \App\Models\Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'defaultDepartmentId' => GuestRequest::defaultDepartmentId(),
            'categories' => IssueCategory::with('department')->orderBy('sort')->orderBy('name')->get(),
            'test' => $test,
            'result' => $test !== '' ? $quick->read(mb_substr($test, 0, 500)) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $request->validate([
            'group' => ['required', Rule::in(QuickTerm::GROUPS)],
            'target' => ['nullable', 'alpha_dash', 'max:40'],
            'term' => ['required', 'string', 'max:60'],
        ], ['term.required' => __('Tulis katanya dulu.'), 'term.max' => __('Kata maksimal 60 huruf.')]);

        $target = match ($data['group']) {
            'item' => GuestRequest::kinds()->all()->has((string) $data['target']) ? $data['target'] : null,
            'issue' => IssueCategory::whereKey((int) $data['target'])->exists() ? (string) (int) $data['target'] : null,
            default => null,
        };
        // Pesan salah tampil di bagian kamus tempat kata itu diketik (quick_slot).
        $fail = fn (string $message) => back()->withInput()->withErrors(['term' => $message], 'quick')->with('quick_slot', $data['group'].'|'.$target);
        if (in_array($data['group'], ['item', 'issue'], true) && $target === null) {
            return $fail(__('Jenis / kategorinya tidak ditemukan.'));
        }
        $term = QuickTerm::normalize($data['term']);
        if (mb_strlen(rtrim($term, '*')) < 2 || ! preg_match('~\p{L}~u', $term)) {
            return $fail(__('Kata minimal 2 huruf.'));
        }
        if (QuickTerm::where(['group' => $data['group'], 'target' => $target, 'term' => $term])->exists()) {
            return $fail(__('":term" sudah ada di sini.', ['term' => $term]));
        }

        QuickTerm::create(['group' => $data['group'], 'target' => $target, 'term' => $term]);

        return back()->with('status', __('":term" ditambahkan.', ['term' => $term]));
    }

    /**
     * Jenis permintaan baru (mis. "Kabel LAN"): kunci dibuat dari namanya dan tidak pernah berubah; kata awal (dipisah koma)
     * langsung masuk kamus. Muncul di dialog Catat permintaan, ketik bebas, kartu obrolan, dan halaman Permintaan.
     */
    public function storeKind(Request $request): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $this->validateKind($request, null);
        $base = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($data['label'], '_'), 32, '') ?: 'jenis';
        $key = $base;
        for ($n = 2; GuestRequestKind::where('key', $key)->exists(); $n++) {
            $key = $base.'_'.$n;
        }
        GuestRequestKind::create([
            'key' => $key, 'label' => $data['label'], 'has_quantity' => $request->boolean('has_quantity'),
            'department_id' => $data['department_id'] ?? null, 'is_active' => true,
            'sort' => (int) GuestRequestKind::where('key', '!=', 'other')->max('sort') + 1,
        ]);
        // "Lainnya" selalu paling bawah.
        GuestRequestKind::where('key', 'other')->update(['sort' => (int) GuestRequestKind::max('sort') + 10]);
        $words = collect(explode(',', (string) ($data['terms'] ?? '')))->map(fn ($word) => QuickTerm::normalize($word))
            ->filter(fn ($word) => mb_strlen(rtrim($word, '*')) >= 2)->unique();
        foreach ($words as $word) {
            QuickTerm::firstOrCreate(['group' => 'item', 'target' => $key, 'term' => $word]);
        }
        GuestRequest::kinds()->refresh();

        return redirect()->to(route('admin.quick-terms.index').'#kamus-item-'.$key)->with('status', __('Jenis ":label" ditambahkan.', ['label' => $data['label']]));
    }

    /** Ubah nama, pemakaian jumlah, tujuan, atau nonaktifkan jenis (Lainnya selalu aktif). Riwayat permintaan tidak berubah. */
    public function updateKind(Request $request, GuestRequestKind $kind): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $this->validateKind($request, $kind);
        $kind->update([
            'label' => $data['label'], 'has_quantity' => $request->boolean('has_quantity'), 'department_id' => $data['department_id'] ?? null,
            'is_active' => $kind->key === 'other' || $request->boolean('is_active'),
        ]);
        GuestRequest::kinds()->refresh();

        return redirect()->to(route('admin.quick-terms.index').'#kamus-item-'.$kind->key)->with('status', __('Jenis ":label" disimpan.', ['label' => $kind->label]));
    }

    /**
     * Kategori kendala baru dari kamus (sama dengan Data kamar > Kategori kendala): nama, departemen penanggung jawab, dan kata
     * awal yang menandainya di obrolan. Muncul di dialog Catat kendala, ketik bebas, Peta kamar, dan laporan.
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $this->validateCategory($request, null);
        $category = IssueCategory::create(['name' => $data['name'], 'department_id' => $data['department_id'] ?? null, 'sort' => (int) IssueCategory::max('sort') + 1, 'is_active' => true]);
        $words = collect(explode(',', (string) ($data['terms'] ?? '')))->map(fn ($word) => QuickTerm::normalize($word))
            ->filter(fn ($word) => mb_strlen(rtrim($word, '*')) >= 2)->unique();
        foreach ($words as $word) {
            QuickTerm::firstOrCreate(['group' => 'issue', 'target' => (string) $category->id, 'term' => $word]);
        }

        return redirect()->to(route('admin.quick-terms.index').'#kamus-issue-'.$category->id)->with('status', __('Kategori ":name" ditambahkan.', ['name' => $category->name]));
    }

    /** Ubah nama, departemen, atau nonaktifkan kategori (kendala lama tidak berubah; kategori nonaktif tidak dibaca dari obrolan). */
    public function updateCategory(Request $request, IssueCategory $category): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $this->validateCategory($request, $category);
        $category->update(['name' => $data['name'], 'department_id' => $data['department_id'] ?? null, 'is_active' => $request->boolean('is_active')]);

        return redirect()->to(route('admin.quick-terms.index').'#kamus-issue-'.$category->id)->with('status', __('Kategori ":name" disimpan.', ['name' => $category->name]));
    }

    /** @return array{name: string, department_id: ?int, terms?: ?string} */
    private function validateCategory(Request $request, ?IssueCategory $category): array
    {
        $data = $request->validateWithBag('category', [
            'name' => ['required', 'string', 'max:100', Rule::unique('issue_categories', 'name')->ignore($category?->id)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'terms' => ['nullable', 'string', 'max:500'],
        ], ['name.required' => __('Nama kategori wajib diisi.'), 'name.unique' => __('Kategori itu sudah ada.')]);
        $data['name'] = trim(preg_replace('~\s+~u', ' ', $data['name']));

        return $data;
    }

    /** @return array{label: string, department_id: ?int, terms?: ?string} */
    private function validateKind(Request $request, ?GuestRequestKind $kind): array
    {
        $data = $request->validateWithBag('kind', [
            'label' => ['required', 'string', 'max:80', Rule::unique('guest_request_kinds', 'label')->ignore($kind?->id)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'terms' => ['nullable', 'string', 'max:500'],
        ], [
            'label.required' => __('Tulis nama jenisnya.'),
            'label.unique' => __('Nama jenis ini sudah ada.'),
        ]);
        $data['label'] = trim(preg_replace('~\s+~u', ' ', $data['label']));

        return $data;
    }

    public function destroy(QuickTerm $term): RedirectResponse
    {
        Gate::authorize('manage-access');
        $term->delete();

        return back()->with('status', __('":term" dihapus.', ['term' => $term->term]));
    }
}
