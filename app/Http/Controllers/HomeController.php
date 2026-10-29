<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** Staff ke checklist hari ini; HOD / HR / GM / Administrator ke dashboard progres checklist per departemen. */
    public function __invoke(Request $request): RedirectResponse
    {
        return match ($request->user()->role) {
            Role::Staff => redirect()->route('checklists.today'),
            default => redirect()->route('dashboard'),
        };
    }
}
