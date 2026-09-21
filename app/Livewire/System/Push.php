<?php

declare(strict_types=1);

namespace App\Livewire\System;

use App\Models\AppUser;
use App\Models\PushToken;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Services\Push\FcmSender;
use App\Services\Push\PushMessage;
use App\Support\Branding;
use App\Support\PushSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Firebase credentials for push notifications.
 *
 * Two things an operator must fetch from the Firebase console: the service
 * account JSON (so this server may send) and the web config plus VAPID key
 * (so browsers may receive). The screen says which is which, because
 * "it doesn't work" is nearly always one of them pasted into the other.
 */
class Push extends Component
{
    use WithFileUploads;

    public bool $enabled = false;

    public bool $webEnabled = false;

    /** The uploaded service-account file, or JSON pasted into the box. */
    public $serviceAccountFile = null;

    public string $serviceAccountJson = '';

    /** @var array<string, string> */
    public array $web = [];

    public string $vapidKey = '';

    public string $testEmail = '';

    public function mount(): void
    {
        $this->enabled = (bool) veyra_setting('push.enabled', false);
        $this->webEnabled = (bool) veyra_setting('push.web_enabled', false);
        $this->vapidKey = PushSettings::vapidKey();

        foreach (array_keys(PushSettings::WEB_FIELDS) as $field) {
            $this->web[$field] = (string) veyra_setting("push.{$field}", '');
        }
    }

    public function render(): View
    {
        return view('livewire.system.push', [
            'account' => PushSettings::serviceAccount(),
            'webReady' => PushSettings::webConfigured(),
            'canEdit' => auth()->user()?->can('edit_general_settings') ?? false,
            'tokenCounts' => PushToken::query()
                ->selectRaw('platform, COUNT(*) c')->groupBy('platform')->pluck('c', 'platform'),
            'webFields' => PushSettings::WEB_FIELDS,
        ])->layout('components.layouts.admin', [
            'title' => 'Push notifications',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'System'],
                ['label' => 'Push notifications'],
            ],
        ]);
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');

        $this->validate([
            'serviceAccountFile' => ['nullable', 'file', 'max:64', 'mimetypes:application/json,text/plain'],
            'serviceAccountJson' => ['nullable', 'string', 'max:16000'],
            'vapidKey' => ['nullable', 'string', 'max:255'],
            'web.*' => ['nullable', 'string', 'max:255'],
        ], [
            'serviceAccountFile.max' => 'That file is larger than a Firebase key file should be.',
            'serviceAccountFile.mimetypes' => 'Upload the .json key file Firebase gave you.',
        ]);

        // A new key file replaces the old one; leaving both empty keeps it.
        $json = $this->serviceAccountFile !== null
            ? (string) file_get_contents($this->serviceAccountFile->getRealPath())
            : trim($this->serviceAccountJson);

        if ($json !== '') {
            if (! PushSettings::storeServiceAccount($json)) {
                $this->addError('serviceAccountJson', 'That is not a Firebase service account file. It should be JSON containing "type": "service_account", a project_id and a private_key.');

                return;
            }

            $logger->log(
                module: 'system',
                action: 'push_credentials_updated',
                description: 'Replaced the Firebase service account for push',
                new: ['project' => PushSettings::projectId()],
                sensitive: true,
            );
        }

        foreach (array_keys(PushSettings::WEB_FIELDS) as $field) {
            Setting::put("push.{$field}", trim((string) ($this->web[$field] ?? '')), auth()->id());
        }

        Setting::put('push.web_vapid_key', trim($this->vapidKey), auth()->id());

        // Neither switch can be on without the credentials behind it, or the
        // member app would offer a button that always fails.
        $this->enabled = $this->enabled && PushSettings::hasServiceAccount();
        $this->webEnabled = $this->webEnabled && $this->enabled && PushSettings::webConfigured();

        Setting::put('push.enabled', $this->enabled, auth()->id());
        Setting::put('push.web_enabled', $this->webEnabled, auth()->id());

        $this->reset('serviceAccountFile', 'serviceAccountJson');
        Setting::flush();

        $logger->log(module: 'system', action: 'push_settings_updated', description: 'Updated push notification settings');

        session()->flash('status', 'Push settings saved.');
    }

    public function removeServiceAccount(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');

        PushSettings::forgetServiceAccount();
        Setting::put('push.enabled', false, auth()->id());
        Setting::put('push.web_enabled', false, auth()->id());
        Setting::flush();

        $this->enabled = false;
        $this->webEnabled = false;

        $logger->log(module: 'system', action: 'push_credentials_removed', description: 'Removed the Firebase service account', sensitive: true);

        session()->flash('status', 'Firebase credentials removed. Push is off.');
    }

    /** Ask Google whether the saved service account still works. */
    public function checkCredentials(FcmSender $sender): void
    {
        $this->authorize('edit_general_settings');

        $result = $sender->verifyCredentials();

        if ($result['ok']) {
            session()->flash('status', "Firebase accepted these credentials for project {$result['project']}.");

            return;
        }

        session()->flash('error', $result['error']);
    }

    /** Send a real notification to one member's devices. */
    public function sendTest(FcmSender $sender): void
    {
        $this->authorize('edit_general_settings');

        $this->validate(['testEmail' => ['required', 'email']], [], ['testEmail' => 'member email']);

        $member = AppUser::query()->where('email', $this->testEmail)->first();

        if ($member === null) {
            $this->addError('testEmail', 'No member has that email address.');

            return;
        }

        if (! PushSettings::enabled()) {
            session()->flash('error', 'Turn push on and save the service account first.');

            return;
        }

        $name = Branding::name();
        $result = $sender->sendToMember($member, new PushMessage(
            title: "{$name} test",
            body: 'Push notifications are working.',
            link: route('member.discover'),
            data: ['type' => 'test'],
        ));

        if ($result['skipped']) {
            session()->flash('error', "{$member->display_name} has no device registered yet. They need to allow notifications in the app or the website once.");

            return;
        }

        if ($result['sent'] === 0) {
            session()->flash('error', 'Firebase refused every device for that member. The delivery log has the reason.');

            return;
        }

        session()->flash('status', "Test sent to {$result['sent']} ".str('device')->plural($result['sent'])." for {$member->display_name}.");
    }
}
