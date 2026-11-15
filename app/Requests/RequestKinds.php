<?php

namespace App\Requests;

use App\Models\GuestRequest;
use App\Models\GuestRequestKind;
use Illuminate\Support\Collection;

/**
 * Daftar jenis permintaan tamu dari database (tabel guest_request_kinds), dibaca sekali per permintaan (scoped).
 * Jenis nonaktif tidak bisa dipilih / dibaca dari ketik bebas, tetapi namanya tetap dipakai untuk riwayat.
 */
class RequestKinds
{
    /** @var Collection<string, GuestRequestKind>|null */
    private ?Collection $kinds = null;

    /** @return Collection<string, GuestRequestKind> semua jenis (termasuk nonaktif), berurutan, berkunci `key` */
    public function all(): Collection
    {
        return $this->kinds ??= GuestRequestKind::with('department')->orderBy('sort')->orderBy('id')->get()->keyBy('key');
    }

    /** @return Collection<string, GuestRequestKind> */
    public function active(): Collection
    {
        return $this->all()->where('is_active', true);
    }

    /** @return array<string, string> */
    public function labels(bool $activeOnly = false): array
    {
        return ($activeOnly ? $this->active() : $this->all())->map(fn (GuestRequestKind $kind) => __($kind->label))->all();
    }

    public function hasQuantity(string $key): bool
    {
        return (bool) $this->all()->get($key)?->has_quantity;
    }

    /** Departemen tujuan jenis ini: yang diatur di jenisnya, selain itu Housekeeping. */
    public function departmentFor(string $key): ?int
    {
        return $this->all()->get($key)?->department_id ?? GuestRequest::defaultDepartmentId();
    }

    public function refresh(): void
    {
        $this->kinds = null;
    }
}
