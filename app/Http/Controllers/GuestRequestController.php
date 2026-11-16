<?php

namespace App\Http\Controllers;

use App\Chat\ChatPoster;
use App\Models\Department;
use App\Models\GuestRequest;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Permintaan tamu (air mineral, handuk, laundry, MUR, dst.): dicatat Front Office, diambil lalu diselesaikan departemen tujuan (bawaan Housekeeping). */
class GuestRequestController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): View
    {
        Gate::authorize('guest-requests');
        $user = $request->user();

        $status = (string) $request->query('status', 'open');
        $status = in_array($status, ['open', 'all', GuestRequest::STATUS_NEW, GuestRequest::STATUS_CLAIMED, GuestRequest::STATUS_DONE], true) ? $status : 'open';
        $search = trim((string) $request->query('q', '')) ?: null;

        $requests = GuestRequest::visibleTo($user)->with(['room', 'department', 'requester.employee', 'claimer.employee'])
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', GuestRequest::STATUS_DONE))
            ->when(in_array($status, [GuestRequest::STATUS_NEW, GuestRequest::STATUS_CLAIMED, GuestRequest::STATUS_DONE], true), fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q, $term) => $q->whereHas('room', fn ($r) => $r->where('number', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByRaw("case when status = 'selesai' then 1 else 0 end")->orderByRaw("case when priority = 'mendesak' then 0 else 1 end")
            ->orderBy('created_at', $status === GuestRequest::STATUS_DONE ? 'desc' : 'asc')
            ->paginate(self::PER_PAGE)->withQueryString();

        // Ringkasan hari ini (WITA): jumlah dan rata-rata menit sampai diambil / selesai.
        $today = GuestRequest::visibleTo($user)->where('created_at', '>=', now()->startOfDay())->get();
        $summary = [
            'waiting' => GuestRequest::visibleTo($user)->where('status', GuestRequest::STATUS_NEW)->count(),
            'claimed' => GuestRequest::visibleTo($user)->where('status', GuestRequest::STATUS_CLAIMED)->count(),
            'done_today' => $today->where('status', GuestRequest::STATUS_DONE)->count(),
            'avg_claim' => $today->map->minutesToClaim()->filter(fn ($m) => $m !== null)->avg(),
            'avg_done' => $today->map->minutesToDone()->filter(fn ($m) => $m !== null)->avg(),
        ];

        return view('guest-requests.index', [
            'requests' => $requests,
            'status' => $status,
            'search' => $search,
            'summary' => $summary,
            'rooms' => Room::where('is_active', true)->orderBy('floor')->orderBy('sort')->orderBy('number')->get(),
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'defaultDepartmentId' => GuestRequest::defaultDepartmentId(),
            // Jenis aktif: kunci => [nama, pakai jumlah] (format lama view).
            'kinds' => GuestRequest::kinds()->active()->map(fn ($kind) => [$kind->label, $kind->has_quantity])->all(),
        ]);
    }

    public function store(Request $request, ChatPoster $poster): RedirectResponse|JsonResponse
    {
        Gate::authorize('guest-requests');
        $data = $request->validate([
            'room' => ['required', 'string', 'max:30'],
            'kinds' => ['required', 'array', 'min:1'],
            'kinds.*' => ['string', Rule::in(GuestRequest::kinds()->active()->keys()->all())],
            'qty' => ['nullable', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'priority' => ['required', Rule::in(GuestRequest::PRIORITIES)],
            'assigned_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
        ], [
            'room.required' => __('Tulis nomor kamarnya.'),
            'kinds.required' => __('Pilih minimal satu permintaan.'),
            'kinds.min' => __('Pilih minimal satu permintaan.'),
            'qty.*.max' => __('Jumlah maksimal 20.'),
        ]);

        $room = Room::where('is_active', true)->where('number', trim($data['room']))->first()
            ?? throw ValidationException::withMessages(['room' => __('Kamar :number tidak ada di data kamar. Periksa lagi nomornya.', ['number' => trim($data['room'])])]);
        // "Sesuai jenis" (kosong) = tiap jenis ke departemennya sendiri; departemen yang dipilih berlaku untuk semua jenis.
        $chosenDepartmentId = ($data['assigned_department_id'] ?? null) ?: null;
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        $created = collect();
        foreach (array_unique($data['kinds']) as $kind) {
            $created[] = GuestRequest::create([
                'room_id' => $room->id, 'kind' => $kind,
                'quantity' => GuestRequest::kinds()->hasQuantity($kind) ? (int) ($data['qty'][$kind] ?? 1) : 1,
                'note' => $note, 'priority' => $data['priority'], 'requested_by' => $request->user()->id,
                'assigned_department_id' => $chosenDepartmentId ?? GuestRequest::kinds()->departmentFor($kind), 'status' => GuestRequest::STATUS_NEW,
            ]);
        }

        // Dicatat dari obrolan: tiap permintaan jadi satu kartu di percakapan itu.
        if ($thread = $poster->target($request)) {
            $created->each(fn (GuestRequest $item) => $poster->card($thread, $request->user(), ['guest_request_id' => $item->id]));

            return $poster->done($thread);
        }

        $message = __(':n permintaan untuk kamar :room dicatat.', ['n' => $created->count(), 'room' => $room->number]);

        return $this->api($request) ? response()->json(['message' => $message]) : redirect()->route('permintaan.index')->with('status', $message);
    }

    /** "Saya ambil" (pengganti "Copy" di chat); atomik supaya dua orang tidak sama-sama berhasil. */
    public function claim(Request $request, GuestRequest $guestRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('claim', $guestRequest);
        $won = GuestRequest::whereKey($guestRequest->id)->where('status', GuestRequest::STATUS_NEW)->whereNull('claimed_by')
            ->update(['status' => GuestRequest::STATUS_CLAIMED, 'claimed_by' => $request->user()->id, 'claimed_at' => now(), 'updated_at' => now()]);

        $message = $won ? __('Permintaan diambil.') : __('Permintaan ini sudah diambil orang lain.');
        if ($this->api($request)) {
            return response()->json(['message' => $message], $won ? 200 : 409);
        }

        return back()->with($won ? 'status' : 'error', $message);
    }

    /** "Selesai" (pengganti "Done"); boleh langsung dari Baru, pengambilnya otomatis orang yang menekan. */
    public function complete(Request $request, GuestRequest $guestRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('complete', $guestRequest);
        $now = now();
        $guestRequest->update([
            'status' => GuestRequest::STATUS_DONE, 'completed_by' => $request->user()->id, 'completed_at' => $now,
            'claimed_by' => $guestRequest->claimed_by ?? $request->user()->id, 'claimed_at' => $guestRequest->claimed_at ?? $now,
        ]);

        return $this->api($request) ? response()->json(['message' => __('Permintaan selesai.')]) : back()->with('status', __('Permintaan selesai.'));
    }

    public function release(Request $request, GuestRequest $guestRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('release', $guestRequest);
        $guestRequest->update(['status' => GuestRequest::STATUS_NEW, 'claimed_by' => null, 'claimed_at' => null]);

        return $this->api($request) ? response()->json(['message' => __('Pengambilan dilepas.')]) : back()->with('status', __('Pengambilan dilepas.'));
    }

    public function destroy(Request $request, GuestRequest $guestRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $guestRequest);
        $guestRequest->delete();

        return $this->api($request) ? response()->json(['message' => __('Permintaan dihapus.')]) : back()->with('status', __('Permintaan dihapus.'));
    }

    /** Dipanggil aplikasi Android (`/api/v1/app`): jawab JSON, tanpa sesi / redirect. */
    private function api(Request $request): bool
    {
        return $request->is('api/*');
    }
}
