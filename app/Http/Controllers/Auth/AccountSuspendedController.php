<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountSuspendedController extends Controller
{
    /**
     * Display the account suspension page.
     */
    public function index(Request $request): View
    {
        $suspensionData = $request->session()->get('suspension_data', [
            'reason' => 'Your account has been suspended.',
            'suspended_at' => null,
            'suspend_until' => null,
        ]);

        return view('auth.suspended', $suspensionData);
    }
}
