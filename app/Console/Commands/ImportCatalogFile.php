<?php

namespace App\Console\Commands;

use App\Tasks\CatalogFileImport;
use Illuminate\Console\Command;

/** Memasang katalog jabatan, tugas, dan parameter awal dari berkas JSON ke server. Simulasi dulu, baru --terapkan. */
class ImportCatalogFile extends Command
{
    protected $signature = 'checklist:katalog-impor {file? : Berkas JSON (bawaan database/data/katalog-awal.json)} {--terapkan : Simpan ke database; tanpa ini hanya simulasi}';

    protected $description = 'Impor katalog jabatan dan tugas dari berkas JSON (departemen dikenali lewat kode)';

    public function handle(CatalogFileImport $import): int
    {
        $file = $this->argument('file') ?? database_path('data/katalog-awal.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data) || ! isset($data['departemen'])) {
            $this->error("Berkas tidak ditemukan atau bukan katalog yang valid: {$file}");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('terapkan');
        $result = $import->run($data, $apply);

        $this->table(
            ['Kode', 'Departemen', 'Jabatan baru', 'Jabatan ada', 'Tugas baru', 'Tugas ada'],
            array_map(fn (array $row) => [$row['code'], $row['name'], $row['positions_new'], $row['positions_existing'], $row['tasks_new'], $row['tasks_existing']], $result['rows']),
        );
        foreach ($result['missing'] as $department) {
            $this->warn("Departemen tidak ditemukan di database (dilewati): {$department}");
        }

        $newTasks = array_sum(array_column($result['rows'], 'tasks_new'));
        $newPositions = array_sum(array_column($result['rows'], 'positions_new'));
        $this->info(($apply ? 'Tersimpan' : 'SIMULASI (belum disimpan)').": {$newPositions} jabatan baru, {$newTasks} tugas baru, {$result['parameters_new']} parameter baru.");
        if (! $apply) {
            $this->line('Jalankan lagi dengan --terapkan untuk menyimpan.');
        }

        return self::SUCCESS;
    }
}
