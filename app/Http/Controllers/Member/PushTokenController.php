<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Support\Branding;
use App\Support\PushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Browser push: the config the page needs, and the token it comes back with.
 */
class PushTokenController extends Controller
{
    /** Store (or move) a browser token for the signed-in member. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        abort_unless(PushSettings::webEnabled(), 403, 'Browser notifications are switched off.');

        PushToken::register(
            member: $request->user('member'),
            token: $data['token'],
            platform: 'web',
            label: $data['label'] ?? $this->browserLabel($request),
        );

        return response()->json(['status' => 'registered']);
    }

    /** Called when a member turns notifications off in the web app. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        PushToken::query()
            ->where('app_user_id', $request->user('member')->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['status' => 'removed']);
    }

    /**
     * The Firebase service worker, rendered rather than shipped as a static
     * file so it carries whatever project the operator configured — and so
     * turning push off actually stops the browser asking.
     *
     * It must be served from the site root: a service worker cannot control
     * pages above its own path.
     */
    public function serviceWorker(): Response
    {
        $config = PushSettings::webConfig();
        $name = Branding::name();

        $body = <<<JS
        /* {$name} — Firebase messaging service worker (generated). */
        importScripts('https://www.gstatic.com/firebasejs/12.19.0/firebase-app-compat.js');
        importScripts('https://www.gstatic.com/firebasejs/12.19.0/firebase-messaging-compat.js');

        firebase.initializeApp({$this->json($config)});

        const messaging = firebase.messaging();

        /*
         * Messages carrying a notification block are shown by the browser on
         * their own, so nothing is drawn here — doing both is what makes a
         * phone buzz twice for one message.
         */
        messaging.onBackgroundMessage(function () {});
        JS;

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    private function json(array $config): string
    {
        return json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }

    /** A name the member will recognise in their device list. */
    private function browserLabel(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform === null ? $browser : "{$browser} on {$platform}";
    }
}
