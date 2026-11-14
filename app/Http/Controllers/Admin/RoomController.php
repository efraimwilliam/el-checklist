<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\FloorPlan;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Reports\ExcelReport;
use App\Rooms\InvalidRoomFile;
use App\Rooms\RoomImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Administrator / HR mengelola data kamar (impor Excel) dan kategori kendala. */
class RoomController extends Controller
{
    private const SESSION = 'room_import';

    public function index(Request $request): View
    {
        Gate::authorize('manage-tasks');
        $tab = in_array($request->query('tab'), ['kategori', 'denah'], true) ? $request->query('tab') : 'kamar';
        $search = trim((string) $request->query('q', '')) ?: null;
        $floor = trim((string) $request->query('floor', '')) ?: null;
        $status = in_array($request->query('status'), ['aktif', 'nonaktif'], true) ? $request->query('status') : null;

        $floors = Room::whereNotNull('floor')->distinct()->pluck('floor')->sort(SORT_NATURAL)->values();

        return view('admin.rooms.index', [
            'tab' => $tab,
            'rooms' => $this->filtered(Room::withCount('issues'), $search, $floor, $status)
                ->orderByRaw('cast(floor as unsigned)')->orderBy('floor')->orderBy('sort')->orderBy('number')->paginate(30)->withQueryString(),
            'stats' => ['total' => Room::count(), 'active' => Room::where('is_active', true)->count(), 'floors' => $floors->count()],
            'floors' => $floors,
            'search' => $search,
            'floor' => $floor,
            'status' => $status,
            'plans' => FloorPlan::withCount('areas')->orderBy('sort')->orderBy('name')->get()
                ->each(fn (FloorPlan $plan) => $plan->setAttribute('rooms_total', $plan->floor !== null ? Room::where('is_active', true)->where('floor', $plan->floor)->count() : Room::where('is_active', true)->count())),
            'categories' => IssueCategory::with('department')->withCount('issues')->orderBy('sort')->orderBy('name')->get(),
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    /** Penyaring daftar kamar (cari nomor, lantai, status), dipakai daftar dan "Atur gedung". */
    private function filtered($query, ?string $search, ?string $floor, ?string $status)
    {
        return $query
            ->when($search, fn ($q, $term) => $q->where('number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($floor, fn ($q, $value) => $q->where('floor', $value))
            ->when($status, fn ($q, $value) => $q->where('is_active', $value === 'aktif'));
    }

    /**
     * Mengisi kolom Gedung untuk SEMUA kamar yang cocok dengan penyaring saat ini (bukan hanya halaman yang tampil).
     * Kolom kosong = mengosongkan gedung. Dipakai supaya kotak gedung di peta kawasan menghitung kamar yang benar.
     */
    public function setBuilding(Request $request): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate(['building' => ['nullable', 'string', 'max:60']]);
        $search = trim((string) $request->input('q', '')) ?: null;
        $floor = trim((string) $request->input('floor', '')) ?: null;
        $status = in_array($request->input('status'), ['aktif', 'nonaktif'], true) ? $request->input('status') : null;
        if (! $search && ! $floor && ! $status) {
            return back()->withErrors(['building' => __('Saring dulu kamar yang mau diatur (cari nomor, lantai, atau status).')]);
        }

        $building = filled($data['building'] ?? null) ? trim($data['building']) : null;
        $updated = $this->filtered(Room::query(), $search, $floor, $status)->update(['building' => $building]);

        return back()->with('status', $building ? __(':count kamar diatur ke gedung ":building".', ['count' => $updated, 'building' => $building]) : __('Gedung dikosongkan untuk :count kamar.', ['count' => $updated]));
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30', Rule::unique('rooms', 'number')],
            'floor' => ['nullable', 'string', 'max:30'],
            'building' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:60'],
        ], ['number.required' => __('Nomor kamar wajib diisi.'), 'number.unique' => __('Nomor kamar itu sudah ada.')]);

        Room::create($this->roomFields($data) + ['sort' => (int) Room::max('sort') + 1]);

        return back()->with('status', __('Kamar ditambahkan.'));
    }

    public function updateRoom(Request $request, Room $room): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30', Rule::unique('rooms', 'number')->ignore($room)],
            'floor' => ['nullable', 'string', 'max:30'],
            'building' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:60'],
        ], ['number.required' => __('Nomor kamar wajib diisi.'), 'number.unique' => __('Nomor kamar itu sudah ada.')]);

        $room->update($this->roomFields($data) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', __('Kamar disimpan.'));
    }

    /**
     * Hapus kamar. Kamar yang pernah punya kendala TIDAK dihapus (riwayat kendala dan peta kamar bergantung padanya):
     * nonaktifkan saja. Kotak kamar di denah ikut terhapus; kamar lain dan denahnya tidak berubah.
     */
    public function destroyRoom(Request $request, Room $room): RedirectResponse
    {
        Gate::authorize('manage-tasks');

        $count = $room->issues()->count();
        if ($count > 0) {
            return back()->withErrors(['room' => __('Kamar :room punya :n kendala dalam riwayat, jadi tidak bisa dihapus. Nonaktifkan saja supaya tidak muncul lagi di pilihan.', ['room' => $room->number, 'n' => $count])]);
        }

        $number = $room->number;
        $room->delete();

        return back()->with('status', __('Kamar :room dihapus.', ['room' => $number]));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('issue_categories', 'name')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], ['name.required' => __('Nama kategori wajib diisi.'), 'name.unique' => __('Kategori itu sudah ada.')]);

        IssueCategory::create($data + ['sort' => (int) IssueCategory::max('sort') + 1]);

        return back()->with('status', __('Kategori ditambahkan.'));
    }

    public function updateCategory(Request $request, IssueCategory $category): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('issue_categories', 'name')->ignore($category)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], ['name.required' => __('Nama kategori wajib diisi.'), 'name.unique' => __('Kategori itu sudah ada.')]);

        $category->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', __('Kategori disimpan.'));
    }

    /** Contoh berkas Excel untuk impor kamar. */
    public function template(): StreamedResponse
    {
        Gate::authorize('manage-tasks');

        return ExcelReport::download('contoh-daftar-kamar.xlsx', [[
            'title' => 'Kamar',
            'headers' => RoomImport::HEADERS,
            'rows' => [['101', '1', 'Gedung A', 'Deluxe'], ['102', '1', 'Gedung A', 'Deluxe'], ['201', '2', 'Gedung A', 'Suite']],
        ]]);
    }

    public function importForm(): View
    {
        Gate::authorize('manage-tasks');

        return view('admin.rooms.import', ['plan' => null]);
    }

    /** Langkah 1: simpan berkas privat, periksa, tampilkan rencana perubahan. */
    public function importPreview(Request $request): View|RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $request->validate(
            ['berkas' => ['required', 'file', 'extensions:xlsx', 'max:5120']],
            ['berkas.required' => __('Pilih berkas Excel (.xlsx) dulu.'), 'berkas.file' => __('Berkas tidak bisa diunggah. Coba lagi.'), 'berkas.extensions' => __('Berkas harus berformat Excel (.xlsx).'), 'berkas.max' => __('Ukuran berkas maksimal 5 MB.')],
        );

        $this->discard($request);
        $path = $request->file('berkas')->storeAs('room-imports', Str::uuid().'.xlsx', 'local');
        $request->session()->put(self::SESSION, $path);

        try {
            $plan = (new RoomImport)->plan(Storage::disk('local')->path($path));
        } catch (InvalidRoomFile $e) {
            $this->discard($request);

            return back()->withErrors(['berkas' => $e->getMessage()]);
        }

        return view('admin.rooms.import', ['plan' => $plan]);
    }

    /** Langkah 2: simpan bila tidak ada kesalahan. */
    public function importApply(Request $request): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $path = $request->session()->get(self::SESSION);
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return redirect()->route('admin.rooms.import')->withErrors(['berkas' => __('Berkas tidak ditemukan. Unggah ulang.')]);
        }

        try {
            $result = (new RoomImport)->apply(Storage::disk('local')->path($path));
        } catch (InvalidRoomFile $e) {
            return redirect()->route('admin.rooms.import')->withErrors(['berkas' => $e->getMessage()]);
        }
        $this->discard($request);

        return redirect()->route('admin.rooms.index')->with('status', __(':created kamar ditambahkan, :updated diperbarui.', $result));
    }

    public function importCancel(Request $request): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $this->discard($request);

        return redirect()->route('admin.rooms.import');
    }

    private function discard(Request $request): void
    {
        if ($path = $request->session()->pull(self::SESSION)) {
            Storage::disk('local')->delete($path);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function roomFields(array $data): array
    {
        return array_map(fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null, collect($data)->only(['number', 'floor', 'building', 'type'])->all());
    }
}
