<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * "Forgot password" for both audiences.
 *
 * Staff and members have separate brokers over separate tables (a member and a
 * staff account may share an email address), so the route passes which
 * audience it is serving and everything else is shared.
 */
class PasswordResetController extends Controller
{
    private const AUDIENCES = [
        'staff' => [
            'broker' => 'users',
            'request_view' => 'auth.forgot-password',
            'reset_view' => 'auth.reset-password',
            'login_route' => 'login',
        ],
        'member' => [
            'broker' => 'app_users',
            'request_view' => 'site.auth.forgot-password',
            'reset_view' => 'site.auth.reset-password',
            'login_route' => 'member.login',
        ],
    ];

    public function create(Request $request): View
    {
        return view($this->audience($request)['request_view']);
    }

    /**
     * Sends the link — and says the same thing whether or not the address
     * exists, so the form cannot be used to find out who has an account.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $status = Password::broker($this->audience($request)['broker'])
            ->sendResetLink(['email' => Str::lower($request->string('email')->toString())]);

        if ($status === Password::RESET_THROTTLED) {
            return back()->withInput()->withErrors(['email' => 'Please wait a minute before asking for another link.']);
        }

        return back()->with('status', 'If an account exists for that email, a reset link is on its way. It expires in 60 minutes.');
    }

    public function edit(Request $request, string $token): View
    {
        return view($this->audience($request)['reset_view'], [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $audience = $this->audience($request);

        $status = Password::broker($audience['broker'])->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Phones signed in with the old password should not keep working.
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }

                if ($user instanceof User) {
                    app(ActivityLogger::class)->log(
                        module: 'auth',
                        action: 'password_reset',
                        subject: $user,
                        description: "{$user->name} reset their password",
                    );
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return redirect()->route($audience['login_route'])
            ->with('status', 'Your password has been changed. You can sign in now.');
    }

    /** @return array{broker: string, request_view: string, reset_view: string, login_route: string} */
    private function audience(Request $request): array
    {
        return self::AUDIENCES[$request->route('audience') ?? 'member'];
    }
}
