<?php

namespace App\Observers;

use App\Audit\AuditLogger;
use App\Models\Checklist;
use App\Models\ChecklistExtraTask;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemPhoto;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ChronologyReport;
use App\Models\ChronologyReportEntry;
use App\Models\ChronologyReportGuest;
use App\Models\FloorPlan;
use App\Models\FloorPlanArea;
use App\Models\IssueFollowup;
use App\Models\RoomIssuePhoto;
use App\Models\RoomIssueUpdate;
use App\Models\RoomIssue;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\Position;
use App\Models\ReportNote;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Mencatat setiap pembuatan, perubahan (lama → baru), dan penghapusan data utama secara otomatis. */
class AuditObserver
{
    /** Kolom yang tidak perlu dicatat sebagai perubahan. */
    private const IGNORED = ['updated_at', 'created_at', 'last_login_at', 'last_synced_at', 'remember_token', 'password'];

    public const MODELS = [
        Checklist::class, ChecklistItem::class, ChecklistItemPhoto::class, ChecklistExtraTask::class,
        Department::class, Employee::class, Position::class, Task::class, User::class, Setting::class, ReportNote::class, IssueFollowup::class, Room::class, IssueCategory::class, RoomIssue::class, RoomIssueUpdate::class, RoomIssuePhoto::class, FloorPlan::class, FloorPlanArea::class, ChronologyReport::class, ChronologyReportGuest::class, ChronologyReportEntry::class,
    ];

    public function created(Model $model): void
    {
        $changes = collect($model->getAttributes())->reject(fn ($value, $key) => in_array($key, self::IGNORED, true) || $value === null)
            ->mapWithKeys(fn ($value, $key) => [$key => [null, $value]])->all();
        $this->record($model, 'created', $changes);
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }
            $changes[$key] = [$model->getOriginal($key), $new];
        }
        if ($changes) {
            $this->record($model, 'updated', $changes);
        }
    }

    public function deleted(Model $model): void
    {
        // Kendala yang dihapus: isi pokoknya ikut dicatat (nilai lama -> kosong) supaya log tetap menjelaskan apa yang hilang.
        $changes = $model instanceof RoomIssue
            ? collect($model->getAttributes())->reject(fn ($value, $key) => in_array($key, self::IGNORED, true) || $value === null)->mapWithKeys(fn ($value, $key) => [$key => [$value, null]])->all()
            : null;
        $this->record($model, 'deleted', $changes);
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>|null  $changes */
    private function record(Model $model, string $event, ?array $changes = null): void
    {
        $name = Str::snake(class_basename($model));
        $key = $model->getKey();
        AuditLogger::record("{$name}.{$event}", class_basename($model), is_numeric($key) ? (int) $key : null, $this->label($model), $changes);
    }

    private function label(Model $model): string
    {
        return match (true) {
            $model instanceof Checklist => $model->position_name.' · '.($model->date?->format('d M Y') ?? ''),
            $model instanceof User => $model->name(),
            $model instanceof Setting => $model->key,
            $model instanceof RoomIssue => '#'.$model->getKey().' · '.$model->placeTitle().' · '.($model->category?->name ?? '—').' · '.$model->status,
            $model instanceof ReportNote => 'Catatan '.($model->period_from?->format('d M Y') ?? ''),
            default => (string) ($model->name ?? $model->title ?? '#'.$model->getKey()),
        };
    }
}
