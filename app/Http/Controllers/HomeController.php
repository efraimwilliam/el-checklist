<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** Staff ke checklist hari ini, HOD ke halaman isi, HR / GM / Administrator ke daftar checklist. */
    public function __invoke(Request $request): RedirectResponse
    {
        return match ($request->user()->role) {
            Role::Staff => redirect()->route('checklists.today'),
            Role::Hod => redirect()->route('fill.create'),
            default => redirect()->route('checklists.index'),
        };
    }
}
