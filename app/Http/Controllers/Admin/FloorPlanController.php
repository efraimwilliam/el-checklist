<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FloorPlan;
use App\Models\FloorPlanArea;
use App\Models\Room;
use App\Rooms\FloorPlanImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Denah per lantai / gedung untuk Peta kamar. Administrator / HR mengunggah gambar (PDF sudah diubah menjadi gambar di browser),
 * lalu menandai kotak area tiap kamar di atasnya. Gambar disimpan privat dan dilayani lewat `image()` setelah pemeriksaan akses.
 */
class FloorPlanController extends Controller
{
    /** Unggah denah baru: nama, lantai (opsional), dan gambar. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'floor' => ['nullable', 'string', 'max:30'],
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:12288'],
        ], [
            'name.required' => __('Beri nama denah, mis. Lantai 3.'),
            'image.required' => __('Pilih berkas denah dulu.'),
            'image.mimes' => __('Gunakan gambar PNG, JPG, atau WebP. PDF diubah menjadi gambar otomatis di halaman ini.'),
            'image.max' => __('Gambar denah terlalu besar (maksimal 12 MB).'),
            'image.uploaded' => __('Berkas tidak bisa diunggah. Coba lagi.'),
        ]);

        $stored = FloorPlanImage::store($request->file('image'));
        $plan = FloorPlan::create([
            'name' => trim($data['name']),
            'floor' => filled($data['floor'] ?? null) ? trim($data['floor']) : null,
            'image_path' => $stored['path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'sort' => (int) FloorPlan::max('sort') + 1,
        ]);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => route('admin.plans.edit', $plan)], 201)
            : redirect()->route('admin.plans.edit', $plan)->with('status', __('Denah diunggah. Tandai kamarnya di bawah ini.'));
    }

    public function edit(FloorPlan $plan): View
    {
        Gate::authorize('manage-tasks');
        $plan->load('areas');
        $placedElsewhere = FloorPlanArea::where('floor_plan_id', '!=', $plan->id)->whereNotNull('room_id')->pluck('room_id')->all();

        return view('admin.rooms.plan-editor', [
            'plan' => $plan,
            'rooms' => Room::where('is_active', true)->orderByRaw('cast(floor as unsigned)')->orderBy('floor')->orderBy('sort')->orderBy('number')->get(['id', 'number', 'floor', 'type'])
                ->reject(fn (Room $room) => in_array($room->id, $placedElsewhere, true))->values(),
            // Gedung / area = nilai kolom "Gedung" pada kamar (beserta jumlah kamarnya); nama baru juga boleh ditambahkan di editor.
            'buildings' => Room::where('is_active', true)->whereNotNull('building')->where('building', '!=', '')
                ->selectRaw('building as name, count(*) as rooms')->groupBy('building')->orderBy('building')->get()->map(fn ($b) => ['name' => $b->name, 'rooms' => (int) $b->rooms])->values(),
            'areas' => $plan->areas->map(fn (FloorPlanArea $a) => ['room_id' => $a->room_id, 'building' => $a->building, 'x' => $a->x, 'y' => $a->y, 'w' => $a->w, 'h' => $a->h])->values(),
        ]);
    }

    /** Ubah nama, lantai, urutan, dan status aktif denah. */
    public function update(Request $request, FloorPlan $plan): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'floor' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['name.required' => __('Nama denah wajib diisi.')]);

        $plan->update([
            'name' => trim($data['name']),
            'floor' => filled($data['floor'] ?? null) ? trim($data['floor']) : null,
            'sort' => $data['sort'] ?? $plan->sort,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', __('Denah disimpan.'));
    }

    public function destroy(FloorPlan $plan): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $path = $plan->image_path;
        $plan->delete(); // kotak area ikut terhapus (cascade); kamar dan kendalanya tidak berubah
        FloorPlanImage::delete($path);

        return redirect()->route('admin.rooms.index', ['tab' => 'denah'])->with('status', __('Denah dihapus.'));
    }

    /**
     * Simpan seluruh kotak area satu denah sekaligus (JSON). Yang tidak ada di daftar dilepas dari denah ini.
     * Kotak mewakili satu kamar (`room_id`, satu kamar hanya boleh punya satu kotak di satu denah) atau satu gedung / area (`building`,
     * sama dengan kolom Gedung pada kamar; satu nama hanya sekali per denah).
     */
    public function saveAreas(Request $request, FloorPlan $plan): JsonResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'areas' => ['present', 'array', 'max:600'],
            'areas.*.room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'areas.*.building' => ['nullable', 'string', 'max:60'],
            'areas.*.x' => ['required', 'numeric', 'min:0', 'max:100'],
            'areas.*.y' => ['required', 'numeric', 'min:0', 'max:100'],
            'areas.*.w' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'areas.*.h' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ], ['areas.*.room_id.exists' => __('Kamar tidak ditemukan.')]);

        $areas = collect($data['areas'])->map(function (array $a) {
            $x = min((float) $a['x'], 99.5);
            $y = min((float) $a['y'], 99.5);

            return [
                'room_id' => filled($a['room_id'] ?? null) ? (int) $a['room_id'] : null,
                'building' => filled($a['building'] ?? null) ? trim($a['building']) : null,
                'x' => round($x, 3), 'y' => round($y, 3),
                'w' => round(min((float) $a['w'], 100 - $x), 3), 'h' => round(min((float) $a['h'], 100 - $y), 3),
            ];
        });

        if ($areas->contains(fn (array $a) => ($a['room_id'] === null) === ($a['building'] === null))) {
            return response()->json(['message' => __('Setiap kotak harus mewakili satu kamar atau satu gedung / area.')], 422);
        }
        $roomAreas = $areas->whereNotNull('room_id');
        $buildingAreas = $areas->whereNotNull('building');
        if ($roomAreas->pluck('room_id')->duplicates()->isNotEmpty() || $buildingAreas->pluck('building')->map(fn ($b) => mb_strtolower($b))->duplicates()->isNotEmpty()) {
            return response()->json(['message' => __('Satu kamar atau gedung hanya boleh punya satu kotak di denah ini.')], 422);
        }

        $takenElsewhere = FloorPlanArea::where('floor_plan_id', '!=', $plan->id)->whereIn('room_id', $roomAreas->pluck('room_id'))->pluck('room_id');
        if ($takenElsewhere->isNotEmpty()) {
            return response()->json(['message' => __('Kamar :rooms sudah ditandai di denah lain.', ['rooms' => Room::whereIn('id', $takenElsewhere)->pluck('number')->implode(', ')])], 422);
        }
        $inactive = Room::whereIn('id', $roomAreas->pluck('room_id'))->where('is_active', false)->pluck('number');
        if ($inactive->isNotEmpty()) {
            return response()->json(['message' => __('Kamar :rooms nonaktif.', ['rooms' => $inactive->implode(', ')])], 422);
        }

        DB::transaction(function () use ($plan, $roomAreas, $buildingAreas) {
            $plan->areas()->where(function ($q) use ($roomAreas, $buildingAreas) {
                $q->where(fn ($r) => $r->whereNotNull('room_id')->whereNotIn('room_id', $roomAreas->pluck('room_id')))
                    ->orWhere(fn ($b) => $b->whereNotNull('building')->whereNotIn('building', $buildingAreas->pluck('building')));
            })->delete();
            foreach ($roomAreas as $area) {
                FloorPlanArea::updateOrCreate(['room_id' => $area['room_id']], $area + ['floor_plan_id' => $plan->id]);
            }
            foreach ($buildingAreas as $area) {
                FloorPlanArea::updateOrCreate(['floor_plan_id' => $plan->id, 'building' => $area['building']], $area);
            }
        });

        return response()->json(['ok' => true, 'count' => $areas->count()]);
    }

    /** Gambar denah. Terbuka untuk semua pengguna kendala kamar (denah dipakai Peta kamar dan pemilihan kamar). */
    public function image(FloorPlan $plan): Response
    {
        Gate::authorize('room-issues');
        $bytes = FloorPlanImage::contents($plan->image_path);
        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
