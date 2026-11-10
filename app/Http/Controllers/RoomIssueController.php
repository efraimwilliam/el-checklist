<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\IssueCategory;
use App\Models\Position;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\RoomIssuePhoto;
use App\Models\RoomIssueUpdate;
use Illuminate\Http\Response;
use App\Models\User;
use App\Rooms\RoomIssuePhotoService;
use App\Support\PhotoReencoder;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Kendala di kamar atau lokasi lain: dicatat (biasanya Front Office), diteruskan ke departemen / jabatan / pegawai, lalu yang menangani mencatat pekerjaannya sampai selesai. */
class RoomIssueController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private RoomIssuePhotoService $photos) {}

    public function index(Request $request): View
    {
        Gate::authorize('room-issues');
        $user = $request->user();

        $status = (string) $request->query('status', 'open');
        $status = in_array($status, ['open', 'all', ...RoomIssue::STATUSES], true) ? $status : 'open';
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : null;
        $search = trim((string) $request->query('q', '')) ?: null;

        $issues = RoomIssue::visibleTo($user)->with(['room', 'category', 'department', 'assignedPosition', 'assignedEmployee', 'claimer.employee', 'reporter.employee'])
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', RoomIssue::STATUS_DONE))
            ->when(in_array($status, RoomIssue::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($departmentId, fn ($q, $id) => $q->where('assigned_department_id', $id))
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->when($search, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('room', fn ($r) => $r->where('number', 'like', $like))->orWhere('location', 'like', $like));
            })
            ->orderByRaw("case when status = 'selesai' then 1 else 0 end")->orderByRaw("case when priority = 'mendesak' then 0 else 1 end")->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('rooms.issues.index', [
            'issues' => $issues,
            'status' => $status,
            'departmentId' => $departmentId,
            'categoryId' => $categoryId,
            'search' => $search,
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'categories' => IssueCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'rooms' => Room::where('is_active', true)->orderBy('floor')->orderBy('sort')->orderBy('number')->get(),
            'locations' => $this->locationSuggestions(),
            'positions' => Position::where('is_active', true)->whereHas('department', fn ($q) => $q->where('is_active', true))->with('department')->orderBy('name')->get()->sortBy(fn (Position $p) => $p->department->name.'|'.$p->name)->values(),
            'employees' => Employee::where('is_active', true)->whereNotNull('department_id')->whereHas('user', fn ($q) => $q->where('is_active', true))->with('department')->orderBy('name')->get()->sortBy(fn (Employee $e) => $e->department->name.'|'.$e->name)->values(),
            'sources' => RoomIssue::sourceLabels(),
            'newForMe' => $user->newRoomIssueCount(),
        ]);
    }

    /**
     * JSON untuk dialog "Catat kendala": ada kendala serupa (kamar dan kategori sama) yang belum selesai?
     * Hanya ringkasan seperlunya, supaya pelapor (mis. Front Office) tahu sebelum membuat kendala ganda.
     */
    public function duplicate(Request $request): JsonResponse
    {
        Gate::authorize('room-issues');
        $data = $request->validate(['room_id' => ['nullable', 'integer'], 'location' => ['nullable', 'string', 'max:120'], 'category_id' => ['required', 'integer']]);
        if (empty($data['room_id']) && blank($data['location'] ?? null)) {
            return response()->json(['found' => false]);
        }
        $issue = RoomIssue::openDuplicate($data['room_id'] ?? null, $data['location'] ?? null, $data['category_id']);
        if (! $issue) {
            return response()->json(['found' => false]);
        }
        $issue->loadMissing(['category', 'claimer.employee', 'room']);

        return response()->json([
            'found' => true,
            'id' => $issue->id,
            'room' => $issue->placeName(),
            'category' => $issue->category?->name,
            'status' => RoomIssue::statusLabels()[$issue->status],
            'claimer' => $issue->claimer?->name(),
            'since' => $issue->created_at->diffForHumans(),
            'description' => \Illuminate\Support\Str::limit($issue->description, 140),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('room-issues');
        $data = $request->validate([
            'place_type' => ['nullable', Rule::in(['room', 'location'])],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->where('is_active', true)],
            'location' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('issue_categories', 'id')->where('is_active', true)],
            'source' => ['required', Rule::in(RoomIssue::SOURCES)],
            'priority' => ['required', Rule::in(RoomIssue::PRIORITIES)],
            'target_type' => ['nullable', Rule::in(['department', 'position', 'employee'])],
            'assigned_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'assigned_position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('is_active', true)],
            'assigned_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('is_active', true)],
            'description' => ['required', 'string', 'max:2000'],
            'merge_into' => ['nullable', 'integer', 'exists:room_issues,id'],
            'force_new' => ['nullable', 'boolean'],
        ], [
            'room_id.exists' => __('Kamar tidak ditemukan atau sudah nonaktif.'),
            'description.required' => __('Tulis kendalanya dulu.'),
            'source.required' => __('Pilih sumber laporan.'),
            'assigned_position_id.exists' => __('Jabatan tidak ditemukan atau sudah nonaktif.'),
            'assigned_employee_id.exists' => __('Pegawai tidak ditemukan atau sudah nonaktif.'),
        ]);

        // Lokasi: kamar (dipilih) atau tempat lain (diketik); tepat salah satu.
        $placeType = $data['place_type'] ?? (blank($data['room_id'] ?? null) && filled($data['location'] ?? null) ? 'location' : 'room');
        if ($placeType === 'room') {
            $roomId = (int) ($data['room_id'] ?? 0) ?: throw ValidationException::withMessages(['room_id' => __('Pilih kamarnya.')]);
            $location = null;
        } else {
            $roomId = null;
            $location = trim((string) ($data['location'] ?? '')) ?: throw ValidationException::withMessages(['location' => __('Tulis lokasinya.')]);
        }

        $categoryId = $data['category_id'] ?? null;
        $duplicate = RoomIssue::openDuplicate($roomId, $location, $categoryId);

        // Laporan tambahan untuk kendala yang sama: masuk ke riwayatnya, bukan kendala baru.
        if (! empty($data['merge_into'])) {
            $target = RoomIssue::find($data['merge_into']);
            if (! $target || $target->isDone() || ! $target->isAtPlace($roomId, $location) || $target->category_id !== $categoryId) {
                return back()->withInput()->withErrors(['merge_into' => __('Kendala yang dipilih sudah tidak cocok. Periksa lagi lokasi dan kategorinya.')]);
            }
            DB::transaction(function () use ($request, $target, $data) {
                $this->log($request, $target, __('Laporan lagi (:source): :text', ['source' => RoomIssue::sourceLabels()[$data['source']], 'text' => trim($data['description'])]), 'laporan');
                if ($data['priority'] === 'mendesak' && $target->priority !== 'mendesak') {
                    $target->update(['priority' => 'mendesak']);
                } else {
                    $target->touch();
                }
            });
            $message = __('Laporan ditambahkan ke kendala yang sudah ada.');

            return $request->user()->can('view', $target)
                ? redirect()->route('kamar.issues.show', $target)->with('status', $message)
                : redirect()->route('kamar.issues.index')->with('status', $message);
        }

        // Ada kendala serupa yang belum selesai dan pelapor belum memilih: minta memilih dulu (dialog menampilkan pilihannya).
        if ($duplicate && ! $request->boolean('force_new')) {
            return back()->withInput()->withErrors(['duplicate' => __(':place sudah punya kendala :category yang belum selesai. Tambahkan laporan ke kendala itu, atau pilih buat kendala baru.', ['place' => $duplicate->loadMissing('room')->placeTitle(), 'category' => $duplicate->category?->name])]);
        }

        [$departmentId, $positionId, $employeeId] = $this->resolveTarget($data, $categoryId);

        $issue = RoomIssue::create([
            'room_id' => $roomId,
            'location' => $location,
            'category_id' => $categoryId,
            'source' => $data['source'],
            'priority' => $data['priority'],
            'recurrence' => RoomIssue::nextRecurrence($roomId, $location, $categoryId),
            'description' => trim($data['description']),
            'reported_by' => $request->user()->id,
            'assigned_department_id' => $departmentId,
            'assigned_position_id' => $positionId,
            'assigned_employee_id' => $employeeId,
            'status' => RoomIssue::STATUS_NEW,
        ]);

        // Kendala lama yang serupa ikut mencatat bahwa masalahnya muncul lagi (dengan tautan ke kendala baru).
        if ($issue->recurrence > 1) {
            $previous = RoomIssue::atPlace($roomId, $location)->where('category_id', $categoryId)->whereKeyNot($issue->id)->orderByDesc('created_at')->first();
            $previous?->updates()->create([
                'body' => __('Muncul lagi sebagai kendala #:id (Berulang ke-:n).', ['id' => $issue->id, 'n' => $issue->recurrence]),
                'kind' => 'berulang',
                'related_issue_id' => $issue->id,
            ]);
        }

        return redirect()->route('kamar.issues.show', $issue)->with('status', __('Kendala dicatat.'));
    }

    /**
     * Tujuan kendala: departemen (pilihan, atau bawaan kategori), satu jabatan, atau satu pegawai. Departemennya selalu terisi.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int} departemen, jabatan, pegawai
     */
    private function resolveTarget(array $data, ?int $categoryId): array
    {
        $type = $data['target_type'] ?? 'department';

        if ($type === 'position') {
            $position = Position::find($data['assigned_position_id'] ?? null) ?? throw ValidationException::withMessages(['assigned_position_id' => __('Pilih jabatannya.')]);

            return [$position->department_id, $position->id, null];
        }

        if ($type === 'employee') {
            $employee = Employee::find($data['assigned_employee_id'] ?? null) ?? throw ValidationException::withMessages(['assigned_employee_id' => __('Pilih pegawainya.')]);
            if (! User::where('employee_id', $employee->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['assigned_employee_id' => __(':name belum punya akses ke aplikasi ini, jadi tidak bisa menerima kendala.', ['name' => $employee->name])]);
            }

            return [$employee->department_id, null, $employee->id];
        }

        return [$data['assigned_department_id'] ?? ($categoryId ? IssueCategory::find($categoryId)?->department_id : null), null, null];
    }

    /** Saran lokasi selain kamar: yang pernah dipakai, ditambah daftar bawaan. @return list<string> */
    private function locationSuggestions(): array
    {
        $used = RoomIssue::whereNotNull('location')->distinct()->orderBy('location')->pluck('location')->all();

        return collect([...RoomIssue::SUGGESTED_LOCATIONS, ...$used])->unique(fn ($name) => mb_strtolower($name))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public function show(RoomIssue $issue): View
    {
        Gate::authorize('view', $issue);
        $issue->load(['room', 'category', 'department', 'assignedPosition', 'assignedEmployee', 'claimer.employee', 'reporter.employee', 'updates.user.employee', 'updates.department', 'updates.photos']);

        $related = RoomIssue::whereIn('id', $issue->updates->pluck('related_issue_id')->filter())->get()->keyBy('id');

        return view('rooms.issues.show', [
            'issue' => $issue,
            'record' => $this->trackRecord($issue, request()->user()),
            'related' => $related,
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'sources' => RoomIssue::sourceLabels(),
        ]);
    }

    /**
     * Isi status pekerjaan setelah kendala diambil: Done (selesai), Ongoing (sedang dikerjakan), atau Pending (tertunda, wajib beralasan),
     * dengan catatan pekerjaan yang sudah dilakukan.
     */
    public function progress(Request $request, RoomIssue $issue): RedirectResponse
    {
        Gate::authorize('work', $issue);
        $data = $request->validate([
            'status' => ['required', Rule::in(['done', 'ongoing', 'pending'])],
            'body' => ['nullable', 'string', 'max:2000', 'required_if:status,pending'],
            'photos' => ['nullable', 'array', 'max:'.RoomIssuePhotoService::MAX_PER_UPDATE],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'status.required' => __('Pilih statusnya.'), 'status.in' => __('Pilih statusnya.'), 'body.required_if' => __('Tulis alasan Pending.'),
            'photos.max' => __('Maksimal :n foto.', ['n' => RoomIssuePhotoService::MAX_PER_UPDATE]),
            'photos.*.max' => __('Foto terlalu besar (maksimal 8 MB).'), 'photos.*.mimes' => __('Gunakan foto JPG, PNG, atau WebP.'), 'photos.*.file' => __('Foto tidak bisa diunggah. Coba lagi.'),
        ]);
        $body = trim((string) ($data['body'] ?? ''));
        $files = array_values($request->file('photos', []));

        // Foto diperiksa dulu: yang bukan gambar sungguhan menggagalkan simpan sebelum status berubah.
        foreach ($files as $file) {
            PhotoReencoder::jpeg($file, 'photos');
        }

        $update = DB::transaction(function () use ($request, $issue, $data, $body) {
            if ($data['status'] === 'done') {
                $update = $this->log($request, $issue, $body ?: __('Selesai.'), 'selesai');
                $issue->update(['status' => RoomIssue::STATUS_DONE, 'completed_at' => now(), 'completed_by' => $request->user()->id]);
            } elseif ($data['status'] === 'pending') {
                $update = $this->log($request, $issue, $body, 'tunda');
                $issue->update(['status' => RoomIssue::STATUS_PENDING]);
            } else {
                $update = $this->log($request, $issue, $body ?: __('Sedang dikerjakan.'), 'catatan');
                $issue->update(['status' => RoomIssue::STATUS_WORKING]);
            }

            return $update;
        });
        foreach ($files as $file) {
            $this->photos->store($update, $file, $request->user());
        }

        return redirect()->route('kamar.issues.show', $issue)->with('status', match ($data['status']) {
            'done' => __('Kendala ditandai Done.'),
            'pending' => __('Kendala ditandai Pending.'),
            default => __('Status Ongoing disimpan.'),
        });
    }

    /**
     * Laporan kronologi satu kendala, siap dicetak / disimpan PDF: ringkasan, lalu urutan waktu dari dicatat sampai selesai
     * (diambil, tiap pekerjaan beserta foto, laporan tambahan) dengan selang waktu antar kejadian dan waktu respons / penanganan.
     */
    public function chronology(Request $request, RoomIssue $issue): View
    {
        Gate::authorize('view', $issue);
        $issue->load(['room', 'category', 'department', 'assignedPosition', 'assignedEmployee', 'reporter.employee', 'claimer.employee', 'completer.employee', 'updates.user.employee', 'updates.department', 'updates.photos']);

        $events = collect([[
            'at' => $issue->created_at,
            'kind' => 'dicatat',
            'actor' => $issue->reporter?->name(),
            'department' => $issue->reporter?->employee?->department?->name,
            'body' => $issue->description,
            'photos' => collect(),
        ]])->concat($issue->updates->map(fn ($u) => [
            'at' => $u->created_at,
            'kind' => $u->kind,
            'actor' => $u->user?->name(),
            'department' => $u->department?->name,
            'body' => $u->body,
            'photos' => $u->photos,
        ]))->sortBy('at')->values();

        $firstClaim = $events->firstWhere('kind', 'ambil');
        $done = $issue->completed_at;

        return view('rooms.issues.chronology', [
            'issue' => $issue,
            'events' => $events,
            'sources' => RoomIssue::sourceLabels(),
            'respondedAfter' => $firstClaim ? $issue->created_at->diffInMinutes($firstClaim['at']) : null,
            'handledAfter' => $firstClaim && $done ? (int) $firstClaim['at']->diffInMinutes($done) : null,
            'totalMinutes' => (int) $issue->created_at->diffInMinutes($done ?? now()),
            'printedBy' => $request->user()->name(),
        ]);
    }

    /** Foto bukti pekerjaan. Terbuka untuk semua pengguna kendala kamar (track record kamar juga terbuka untuk mereka). */
    public function photo(RoomIssue $issue, RoomIssuePhoto $photo): Response
    {
        Gate::authorize('room-issues');
        abort_unless($photo->entry?->room_issue_id === $issue->id, 404);
        $bytes = $this->photos->contents($photo);
        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Track record kamar untuk halaman detail, dipisah supaya tidak tercampur: kendala sejenis (kategori sama, departemen mana pun),
     * pekerjaan departemen pengguna di kategori lain, dan kendala departemen lain.
     *
     * Setelah diambil, yang tampil hanya `department`: kendala di kamar itu yang ditangani departemen kendala ini (mis. Engineering).
     *
     * @return array{same: Collection, ownDepartment: Collection, others: Collection, department: Collection}
     */
    private function trackRecord(RoomIssue $issue, User $user): array
    {
        $past = RoomIssue::atPlace($issue->room_id, $issue->location)->whereKeyNot($issue->id)
            ->with(['category', 'department', 'claimer.employee', 'updates.user.employee', 'updates.department', 'updates.photos'])
            ->orderByDesc('created_at')->limit(40)->get();
        $mine = $user->issueDepartmentIds();

        $same = $past->filter(fn (RoomIssue $p) => $p->category_id !== null && $p->category_id === $issue->category_id)->take(10)->values();
        $rest = $past->reject(fn (RoomIssue $p) => $same->contains('id', $p->id));

        return [
            'same' => $same,
            'ownDepartment' => $rest->filter(fn (RoomIssue $p) => in_array($p->assigned_department_id, $mine, true))->values(),
            'others' => $rest->reject(fn (RoomIssue $p) => in_array($p->assigned_department_id, $mine, true))->values(),
            'department' => $past->filter(fn (RoomIssue $p) => $issue->assigned_department_id !== null && $p->assigned_department_id === $issue->assigned_department_id)->values(),
        ];
    }

    /** Track record satu kamar: semua kendala beserta pekerjaan yang dicatat, bisa disaring kategori dan departemen. Terbuka untuk semua pengguna kendala. */
    public function room(Request $request, Room $room): View
    {
        Gate::authorize('room-issues');

        return $this->recordView($request, $room, null);
    }

    /** Track record satu lokasi selain kamar (teks lokasi yang sama): halaman yang sama dengan track record kamar. */
    public function place(Request $request): View
    {
        Gate::authorize('room-issues');
        $name = trim((string) $request->query('nama', ''));
        abort_if($name === '', 404);

        return $this->recordView($request, null, $name);
    }

    private function recordView(Request $request, ?Room $room, ?string $location): View
    {
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : null;
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;

        $issues = RoomIssue::atPlace($room?->id, $location)
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->when($departmentId, fn ($q, $id) => $q->where('assigned_department_id', $id))
            ->with(['category', 'department', 'claimer.employee', 'updates.user.employee', 'updates.department', 'updates.photos'])
            ->orderByDesc('created_at')->limit(100)->get();

        return view('rooms.show', [
            'room' => $room,
            'placeName' => $room?->number ?? $location,
            'issues' => $issues,
            'categoryId' => $categoryId,
            'departmentId' => $departmentId,
            'categories' => IssueCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    /** "Saya ambil": satu orang per kendala. Diperiksa atomik di database supaya dua orang yang menekan bersamaan tidak sama-sama berhasil. */
    public function claim(Request $request, RoomIssue $issue): RedirectResponse
    {
        Gate::authorize('claim', $issue);
        $user = $request->user();

        $taken = DB::transaction(function () use ($request, $issue, $user) {
            $updated = RoomIssue::whereKey($issue->id)->whereNull('claimed_by')->where('status', '!=', RoomIssue::STATUS_DONE)
                ->update(['claimed_by' => $user->id, 'claimed_at' => now(), 'status' => RoomIssue::STATUS_WORKING]);
            if ($updated) {
                $this->log($request, $issue, __(':name mengambil kendala ini.', ['name' => $user->name()]), 'ambil');
            }

            return $updated === 1;
        });

        if (! $taken) {
            $by = $issue->refresh()->claimer?->name();

            return redirect()->route('kamar.issues.show', $issue)->withErrors(['claim' => $by ? __('Kendala ini sudah diambil oleh :name.', ['name' => $by]) : __('Kendala ini tidak bisa diambil lagi.')]);
        }

        return redirect()->route('kamar.issues.show', $issue)->with('status', __('Anda mengambil kendala ini.'));
    }

    /** Melepas pengambilan supaya rekan lain bisa mengambilnya; status kembali Baru bila belum ada pekerjaan yang dicatat. */
    public function release(Request $request, RoomIssue $issue): RedirectResponse
    {
        Gate::authorize('release', $issue);
        $name = $issue->claimer?->name() ?? '—';

        DB::transaction(function () use ($request, $issue, $name) {
            $issue->update(['claimed_by' => null, 'claimed_at' => null]);
            $this->log($request, $issue, __('Pengambilan oleh :name dilepas.', ['name' => $name]), 'lepas');
            if (! $issue->updates()->whereIn('kind', ['catatan', 'tunda'])->exists()) {
                $issue->update(['status' => RoomIssue::STATUS_NEW]);
            }
        });

        return redirect()->route('kamar.issues.show', $issue)->with('status', __('Pengambilan dilepas.'));
    }

    public function reopen(Request $request, RoomIssue $issue): RedirectResponse
    {
        Gate::authorize('reopen', $issue);

        DB::transaction(function () use ($request, $issue) {
            $this->log($request, $issue, __('Dibuka kembali.'), 'catatan');
            $issue->update(['status' => RoomIssue::STATUS_WORKING, 'completed_at' => null, 'completed_by' => null]);
        });

        return redirect()->route('kamar.issues.show', $issue)->with('status', __('Kendala dibuka kembali.'));
    }

    private function log(Request $request, RoomIssue $issue, string $body, string $kind): RoomIssueUpdate
    {
        return RoomIssueUpdate::create([
            'room_issue_id' => $issue->id,
            'user_id' => $request->user()->id,
            'department_id' => $request->user()->checklistDepartmentId(),
            'body' => $body,
            'kind' => $kind,
        ]);
    }
}
