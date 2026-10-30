<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistService;
use App\Enums\ItemStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Penyimpanan otomatis per tugas (JSON). Status boleh dikosongkan; Pending tanpa alasan tetap disimpan, hanya membuat checklist "belum lengkap".
 */
class ChecklistItemController extends Controller
{
    public function __construct(private ChecklistService $service) {}

    public function update(Request $request, Checklist $checklist, ChecklistItem $item): JsonResponse
    {
        Gate::authorize('update', $checklist);
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(ItemStatus::class)],
            'parameters' => ['nullable', 'array', 'max:30'],
            'parameters.*' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if (isset($data['parameters'])) {
            $this->service->saveParameters($item, $data['parameters'], $data['note'] ?? null, $request->user());
        } else {
            $this->service->saveItem($item, $data['status'] ?? null, $data['note'] ?? null, $request->user());
        }

        return $this->response($checklist);
    }

    private function response(Checklist $checklist): JsonResponse
    {
        $checklist->load('items.parameters');
        $progress = $this->service->progress($checklist);

        return response()->json([
            'ok' => true,
            'saved_at' => now()->format('H:i:s'),
            'progress' => $progress,
            'items' => $checklist->items->mapWithKeys(fn (ChecklistItem $item) => [$item->id => [
                'status' => $item->status?->value,
                'note' => $item->note,
                'parameters' => $item->parameters->mapWithKeys(fn ($parameter) => [$parameter->id => $parameter->value]),
            ]]),
        ]);
    }
}
