<?php

namespace App\Http\Controllers;

use App\Chat\ChatPoster;
use App\Models\LostItem;
use App\Models\Room;
use App\Support\PhotoReencoder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Barang tertinggal (lost & found) di dalam obrolan: halaman ini dibuka di jendela form obrolan (menu lampiran / "/t") dan
 * tidak ada di menu. Catat dari obrolan = kartu terkirim ke percakapan; perbarui status dari kartu = kartu baru sebagai jejak.
 */
class LostItemController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): View
    {
        Gate::authorize('lost-found');
        $status = (string) $request->query('status', LostItem::STATUS_STORED);
        $status = in_array($status, ['all', ...LostItem::STATUSES], true) ? $status : LostItem::STATUS_STORED;
        $search = trim((string) $request->query('q', '')) ?: null;

        $items = LostItem::with(['room', 'finder.employee', 'resolver.employee'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('location', 'like', $like)->orWhereHas('room', fn ($r) => $r->where('number', 'like', $like)));
            })
            ->orderByDesc('found_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('lost-items.index', [
            'items' => $items,
            'status' => $status,
            'search' => $search,
            'storedCount' => LostItem::where('status', LostItem::STATUS_STORED)->count(),
            'storedPlaces' => LostItem::whereNotNull('stored_at')->distinct()->orderBy('stored_at')->limit(20)->pluck('stored_at'),
        ]);
    }

    public function store(Request $request, ChatPoster $poster): RedirectResponse
    {
        Gate::authorize('lost-found');
        $data = $request->validate([
            'room' => ['nullable', 'string', 'max:30'],
            'location' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:200'],
            'stored_at' => ['nullable', 'string', 'max:120'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
        ], [
            'description.required' => __('Tulis barangnya, mis. dompet hitam, charger iPhone.'),
            'photo.image' => __('File bukan foto yang valid.'),
            'photo.max' => __('Foto terlalu besar (maksimal 8 MB).'),
        ]);

        $roomId = null;
        $roomNumber = trim((string) ($data['room'] ?? ''));
        if ($roomNumber !== '') {
            $roomId = Room::where('is_active', true)->where('number', $roomNumber)->value('id')
                ?? throw ValidationException::withMessages(['room' => __('Kamar :number tidak ada di data kamar. Periksa lagi nomornya.', ['number' => $roomNumber])]);
        } elseif (blank($data['location'] ?? null)) {
            throw ValidationException::withMessages(['room' => __('Isi nomor kamar, atau lokasi ditemukan bila bukan di kamar.')]);
        }

        $item = LostItem::create([
            'room_id' => $roomId, 'location' => $roomId ? null : trim((string) $data['location']),
            'description' => trim($data['description']), 'stored_at' => trim((string) ($data['stored_at'] ?? '')) ?: null,
            'photo_path' => $request->hasFile('photo') ? $this->storePhoto(PhotoReencoder::jpeg($request->file('photo'))) : null,
            'found_by' => $request->user()->id, 'found_at' => now(), 'status' => LostItem::STATUS_STORED,
        ]);

        if ($thread = $poster->target($request)) {
            $poster->card($thread, $request->user(), ['lost_item_id' => $item->id]);

            return $poster->done($thread);
        }

        return redirect()->route('lost.index')->with('status', __('Barang tertinggal dicatat.'));
    }

    /** Perbarui status (Diambil tamu / Dikirim / Tidak diambil / kembali Disimpan) + catatan (nama penerima, resi, alasan). */
    public function updateStatus(Request $request, LostItem $item, ChatPoster $poster): RedirectResponse
    {
        Gate::authorize('lost-found');
        $data = $request->validate([
            'status' => ['required', Rule::in(LostItem::STATUSES)],
            'resolution_note' => [Rule::requiredIf(fn () => in_array($request->input('status'), ['diambil', 'dikirim'], true)), 'nullable', 'string', 'max:300'],
            'stored_at' => ['nullable', 'string', 'max:120'],
        ], ['resolution_note.required' => __('Tulis nama penerima / nomor resi.')]);

        $resolved = $data['status'] !== LostItem::STATUS_STORED;
        $item->update([
            'status' => $data['status'],
            'resolution_note' => trim((string) ($data['resolution_note'] ?? '')) ?: null,
            'stored_at' => array_key_exists('stored_at', $data) ? (trim((string) $data['stored_at']) ?: $item->stored_at) : $item->stored_at,
            'resolved_by' => $resolved ? $request->user()->id : null,
            'resolved_at' => $resolved ? now() : null,
        ]);

        if ($thread = $poster->target($request)) {
            $message = $poster->post($thread, $request->user(), __('Status barang tertinggal: :status', ['status' => LostItem::statusLabels()[$item->status]]).($item->resolution_note ? ' · '.$item->resolution_note : ''));
            $message->update(['lost_item_id' => $item->id]);

            return $poster->done($thread);
        }

        return back()->with('status', __('Status barang diperbarui.'));
    }

    public function photo(LostItem $item): StreamedResponse
    {
        Gate::authorize('lost-found');
        $disk = Storage::disk('local');
        abort_unless($item->photo_path && $disk->exists($item->photo_path), 404);

        return $disk->response($item->photo_path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, LostItem $item): RedirectResponse
    {
        abort_unless(Gate::allows('lost-found') && $request->user()->isAdmin(), 403);
        if ($item->photo_path) {
            Storage::disk('local')->delete($item->photo_path);
        }
        $item->delete();

        return back()->with('status', __('Catatan barang dihapus.'));
    }

    /** Simpan JPEG (sudah diolah ulang) di folder privat; dipakai juga saat foto obrolan dicatat sebagai barang tertinggal. */
    public static function storePhoto(string $jpeg): string
    {
        $path = 'lost-items/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        return $path;
    }
}
