<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** Peran yang mereview langsung ke daftar review; staff ke checklist hari ini. */
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->role->reviews()
            ? redirect()->route('checklists.index')
            : redirect()->route('checklists.today');
    }
}
