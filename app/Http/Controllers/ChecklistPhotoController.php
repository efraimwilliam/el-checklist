<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistPhotoService;
use App\Checklists\ChecklistService;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Foto bukti per tugas: unggah dan hapus selama checklist boleh diubah, lihat oleh siapa pun yang boleh melihat checklist. */
class ChecklistPhotoController extends Controller
{
    public function __construct(private ChecklistPhotoService $photos, private ChecklistService $checklists) {}

    public function store(Request $request, Checklist $checklist, ChecklistItem $item): JsonResponse
    {
        Gate::authorize('update', $checklist);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192']], [
            'photo.max' => __('Foto terlalu besar (maksimal 8 MB).'),
            'photo.mimes' => __('Gunakan foto JPG, PNG, atau WebP.'),
        ]);
        $photo = $this->photos->store($item, $request->file('photo'), $request->user());
        $checklist->touch();

        return response()->json([
            'ok' => true,
            'photo' => $this->payload($photo->setRelation('item', $item)),
            'progress' => $this->checklists->progress($checklist),
        ], 201);
    }

    public function show(Checklist $checklist, ChecklistItemPhoto $photo): StreamedResponse
    {
        abort_unless($photo->item->checklist_id === $checklist->id, 404);
        Gate::authorize('view', $checklist);
        $disk = Storage::disk(config('checklist.photos.disk'));
        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Checklist $checklist, ChecklistItemPhoto $photo): JsonResponse
    {
        abort_unless($photo->item->checklist_id === $checklist->id, 404);
        Gate::authorize('update', $checklist);
        $this->photos->delete($photo);
        $checklist->touch();

        return response()->json(['ok' => true, 'progress' => $this->checklists->progress($checklist)]);
    }

    /** @return array{id: int, url: string, delete_url: string} */
    private function payload(ChecklistItemPhoto $photo): array
    {
        return ['id' => $photo->id, 'url' => $photo->url(), 'delete_url' => route('checklists.photos.destroy', [$photo->item->checklist_id, $photo->id])];
    }
}
