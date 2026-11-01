<?php

namespace App\Http\Controllers;

use App\Reports\ExcelReport;
use App\Tasks\InvalidTaskFile;
use App\Tasks\TaskCatalogImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Unduh dan impor Daftar tugas lewat Excel. Berkas yang diunggah disimpan privat sampai disimpan atau dibatalkan. */
class TaskCatalogImportController extends Controller
{
    private const SESSION = 'task_catalog_import';

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('manage-team-tasks');

        // Judul kolom tidak diterjemahkan: impor mencocokkannya dengan teks ini.
        return ExcelReport::download('daftar-tugas-'.CarbonImmutable::today()->format('Y-m-d').'.xlsx', [[
            'title' => 'Daftar tugas',
            'headers' => TaskCatalogImport::HEADERS,
            'rows' => (new TaskCatalogImport($request->user()))->exportRows(),
        ]]);
    }

    /** Halaman penuh, atau (permintaan fetch dari popup) hanya isi panelnya sebagai JSON. */
    public function create(Request $request): View|JsonResponse
    {
        Gate::authorize('manage-team-tasks');

        return $request->expectsJson() ? $this->panel($request) : view('team.import', $this->state($request));
    }

    public function preview(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-team-tasks');
        $rules = ['berkas' => ['required', 'file', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:5120']];
        $names = ['berkas' => __('berkas')];
        // Aplikasi tidak memuat terjemahan pesan validasi bawaan, jadi pesannya ditulis di sini.
        $messages = [
            'berkas.required' => __('Pilih berkas Excel (.xlsx) dulu.'),
            'berkas.file' => __('Berkas tidak bisa diunggah. Coba lagi.'),
            'berkas.uploaded' => __('Berkas tidak bisa diunggah. Coba lagi.'),
            'berkas.extensions' => __('Berkas harus berformat Excel (.xlsx).'),
            'berkas.mimetypes' => __('Berkas harus berformat Excel (.xlsx).'),
            'berkas.max' => __('Ukuran berkas maksimal 5 MB.'),
        ];

        if ($request->expectsJson()) {
            $validator = Validator::make($request->all(), $rules, $messages, $names);
            if ($validator->fails()) {
                return $this->panel($request, $validator->errors()->first('berkas'), 422);
            }
            $data = $validator->validated();
        } else {
            $data = $request->validate($rules, $messages, $names);
        }

        $this->discard($request);
        $path = $data['berkas']->storeAs('task-imports', Str::uuid().'.xlsx', 'local');
        $request->session()->put(self::SESSION, [
            'path' => Storage::disk('local')->path($path),
            'name' => $data['berkas']->getClientOriginalName(),
            'user' => $request->user()->id,
        ]);

        return $request->expectsJson() ? $this->panel($request) : redirect()->route('team.catalog.import');
    }

    public function apply(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-team-tasks');
        $pending = $this->pending($request);
        if (! $pending) {
            return $this->failed($request, __('Unggah berkas dulu sebelum menyimpan.'));
        }

        try {
            $result = (new TaskCatalogImport($request->user()))->apply($pending['path']);
        } catch (InvalidTaskFile $e) {
            return $this->failed($request, $e->getMessage());
        }

        $this->discard($request);
        $status = __(':created tugas ditambahkan, :updated tugas diperbarui dari Excel.', ['created' => $result['created'], 'updated' => $result['updated']]);

        if ($request->expectsJson()) {
            $request->session()->flash('status', $status);

            return response()->json(['redirect' => route('team.catalog')]);
        }

        return redirect()->route('team.catalog')->with('status', $status);
    }

    public function cancel(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-team-tasks');
        $this->discard($request);

        return $request->expectsJson() ? $this->panel($request) : redirect()->route('team.catalog.import');
    }

    /** @return array{plan: ?array, error: ?string, file: ?string} */
    private function state(Request $request): array
    {
        $pending = $this->pending($request);
        $plan = null;
        $error = null;

        if ($pending) {
            try {
                $plan = (new TaskCatalogImport($request->user()))->plan($pending['path']);
            } catch (InvalidTaskFile $e) {
                $error = $e->getMessage();
            } catch (\Throwable) {
                $error = __('Berkas tidak bisa dibaca. Pastikan berkasnya Excel (.xlsx) hasil unduhan Kamus daftar tugas.');
            }
        }

        return ['plan' => $plan, 'error' => $error, 'file' => $pending['name'] ?? null];
    }

    /** Isi panel impor untuk popup. */
    private function panel(Request $request, ?string $uploadError = null, int $status = 200): JsonResponse
    {
        return response()->json(['html' => view('team.partials.import-panel', [...$this->state($request), 'uploadError' => $uploadError])->render()], $status);
    }

    private function failed(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? $this->panel($request, $message, 422)
            : redirect()->route('team.catalog.import')->withErrors(['berkas' => $message]);
    }

    /** @return array{path: string, name: string, user: int}|null  Hanya berkas milik pengguna yang sedang login. */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION);

        return is_array($pending) && ($pending['user'] ?? null) === $request->user()->id && is_file($pending['path'] ?? '') ? $pending : null;
    }

    private function discard(Request $request): void
    {
        if ($pending = $this->pending($request)) {
            @unlink($pending['path']);
        }
        $request->session()->forget(self::SESSION);
    }
}
