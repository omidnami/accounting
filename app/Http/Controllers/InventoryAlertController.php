<?php

namespace App\Http\Controllers;

use App\Models\InventoryAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    public function acknowledge(Request $request, InventoryAlert $alert): RedirectResponse
    {
        abort_unless($request->user()?->canViewInventoryReports(), 403);
        $alert->update(['acknowledged_at' => now()]);

        return back();
    }
}
