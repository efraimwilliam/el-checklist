<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jenis permintaan tamu (dulu daftar tetap GuestRequest::KINDS): diatur Administrator di Kamus ketik bebas.
        // department_id kosong = bawaan (Housekeeping). Kunci tidak pernah berubah, jadi guest_requests.kind lama tetap terbaca.
        Schema::create('guest_request_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 80);
            $table->boolean('has_quantity')->default(true);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        // Tujuan "Lainnya" yang sudah diatur di Pengaturan > Ketik bebas pindah ke jenis Lainnya.
        $other = DB::table('settings')->where('key', 'quick_other_department_id')->value('value');
        $other = $other && DB::table('departments')->where('id', (int) $other)->exists() ? (int) $other : null;
        $rows = [
            ['mineral_water', 'Air mineral', true], ['towel', 'Handuk', true], ['tissue', 'Tisu / toilet paper', true], ['pillow', 'Bantal', true],
            ['extra_bed', 'Extra bed', true], ['amenities', 'Amenities', false], ['laundry', 'Ambil laundry', false],
            ['drying_rack', 'Jemuran / drying rack', false], ['mur', 'Make up room (MUR)', false], ['other', 'Lainnya', true],
        ];
        foreach ($rows as $index => [$key, $label, $quantity]) {
            DB::table('guest_request_kinds')->insert([
                'key' => $key, 'label' => $label, 'has_quantity' => $quantity, 'department_id' => $key === 'other' ? $other : null,
                'sort' => ($index + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_request_kinds');
    }
};
