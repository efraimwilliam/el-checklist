<?php

namespace App\Http\Controllers;

use App\Reports\ExcelReport;
use App\Tasks\InvalidTaskFile;
use App\Tasks\TaskCatalogImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

    public function create(Request $request): View
    {
        Gate::authorize('manage-team-tasks');
        $pending = $this->pending($request);
        $plan = null;
        $error = null;

        if ($pending) {
            try {
                $plan = (new TaskCatalogImport($request->user()))->plan($pending['path']);
            } catch (InvalidTaskFile $e) {
                $error = $e->getMessage();
            } catch (\Throwable) {
                $error = __('Berkas tidak bisa dibaca. Pastikan berkasnya Excel (.xlsx) hasil unduhan Daftar tugas.');
            }
        }

        return view('team.import', ['plan' => $plan, 'error' => $error, 'file' => $pending['name'] ?? null]);
    }

    public function preview(Request $request): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $data = $request->validate(['berkas' => ['required', 'file', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:5120']], [], ['berkas' => __('berkas')]);

        $this->discard($request);
        $path = $data['berkas']->storeAs('task-imports', Str::uuid().'.xlsx', 'local');
        $request->session()->put(self::SESSION, [
            'path' => Storage::disk('local')->path($path),
            'name' => $data['berkas']->getClientOriginalName(),
            'user' => $request->user()->id,
        ]);

        return redirect()->route('team.catalog.import');
    }

    public function apply(Request $request): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('team.catalog.import')->withErrors(['berkas' => __('Unggah berkas dulu sebelum menyimpan.')]);
        }

        try {
            $result = (new TaskCatalogImport($request->user()))->apply($pending['path']);
        } catch (InvalidTaskFile $e) {
            return redirect()->route('team.catalog.import')->withErrors(['berkas' => $e->getMessage()]);
        }

        $this->discard($request);

        return redirect()->route('team.catalog')->with('status', __(':created tugas ditambahkan, :updated tugas diperbarui dari Excel.', [
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]));
    }

    public function cancel(Request $request): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $this->discard($request);

        return redirect()->route('team.catalog.import');
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
