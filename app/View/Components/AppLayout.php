<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  bool  $fullHeight  Halaman setinggi layar tanpa gulir halaman (obrolan): isi yang menggulir sendiri.
     * @param  bool  $bareOnMobile  Sembunyikan header aplikasi di HP (layar percakapan punya header sendiri).
     */
    public function __construct(public ?string $title = null, public bool $fullHeight = false, public bool $bareOnMobile = false) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
