<?php

namespace App\Http\Controllers\Api;

use App\Checklists\ChecklistService;
use App\Checklists\ChecklistSettings;
use App\Checklists\ParameterRules;
use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Checklist milik pengguna untuk aplikasi Android. Menyimpan isian, catatan, dan foto memakai controller web yang sama
 * (`ChecklistItemController`, `ChecklistController::meta`, `ChecklistPhotoController`) lewat rute /api, jadi aturannya satu.
 */
class ChecklistController extends Controller
{
    /** Hari ke belakang yang tampil di aplikasi (sama dengan panel "Lanjutkan checklist" di web). */
    public const DAYS_BACK = 7;

    public function __construct(private ChecklistService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = CarbonImmutable::today();
        // progress() memuat tugas dan fotonya sendiri.
        $checklists = Checklist::query()
            ->where('employee_id', $user->employee_id)
            ->whereDate('date', '>=', $today->subDays(self::DAYS_BACK))
            ->whereDate('date', '<=', $today)
            ->orderByDesc('date')->orderBy('id')->limit(60)->get();

        return response()->json(['checklists' => $checklists->map(fn (Checklist $c) => $this->summary($c, $user))->values()]);
    }

    public function show(Request $request, Checklist $checklist): JsonResponse
    {
        Gate::authorize('view', $checklist);
        $summary = $this->summary($checklist, $request->user()); // progress() memuat ulang tugas, jadi sebelum load di bawah
        $checklist->load(['items.photos', 'items.parameters', 'extraTasks']);
        $settings = app(ChecklistSettings::class);

        return response()->json(['checklist' => $summary + [
            'note' => $checklist->note,
            'photo_requirement' => $settings->photoRequirement(),
            'photo_max' => $settings->photosPerTask(),
            'pending_note_required' => (bool) config('checklist.pending_note_required'),
            'items' => $checklist->items->sortBy('sort')->map(fn (ChecklistItem $item) => [
                'id' => $item->id,
                'section' => $item->section,
                'title' => $item->title,
                'standard' => $item->standard,
                'status' => $item->status?->value,
                'note' => $item->note,
                'parameters' => $item->parameters->sortBy('sort')->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'unit' => $p->unit,
                    'min' => $p->min_value,
                    'max' => $p->max_value,
                    'options' => ParameterRules::options($p->options),
                    'normal_option' => $p->normal_option,
                    'value' => $p->value,
                ])->values(),
                'photos' => $item->photos->map(fn ($photo) => ['id' => $photo->id])->values(),
            ])->values(),
            'extras' => $checklist->extraTasks->map(fn ($extra) => [
                'title' => $extra->title,
                'status' => $extra->status?->value,
                'note' => $extra->note,
            ])->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function summary(Checklist $checklist, $user): array
    {
        return [
            'id' => $checklist->id,
            'date' => $checklist->date->toDateString(),
            'position' => $checklist->position_name,
            'status' => $checklist->status->value,
            'can_edit' => $user->can('update', $checklist),
            'updated_at' => $checklist->updated_at?->toIso8601String(),
            'progress' => $this->service->progress($checklist),
        ];
    }
}
