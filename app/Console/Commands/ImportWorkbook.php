<?php

namespace App\Console\Commands;

use App\Checklists\WorkbookImporter;
use Illuminate\Console\Command;

class ImportWorkbook extends Command
{
    protected $signature = 'checklist:import-workbook {path : File .xlsx checklist lama}';

    protected $description = 'Impor departemen, jabatan dan tugas dari workbook checklist lama (sheet Akun tidak dibaca)';

    public function handle(WorkbookImporter $importer): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }
        $result = $importer->import($path);
        $this->info("Departemen: {$result['departments']}, jabatan: {$result['positions']}, tugas: {$result['tasks']}.");

        return self::SUCCESS;
    }
}
