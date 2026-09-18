<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MemberSessionController extends Controller
{
    /**
     * Signs the member out without invalidating the whole session.
     *
     * Staff and members share one session cookie under separate guards.
     * Invalidating it here would also sign out a staff member testing the
     * website in the same browser, which is surprising and never the intent.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'You are signed out. See you soon.');
    }
}
