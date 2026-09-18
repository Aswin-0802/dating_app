<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\Appeal;
use App\Models\Ban;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * What a suspended or banned member sees instead of the app.
 *
 * DSA Article 17: the statement of reasons — what was restricted, which rule,
 * until when, and whether it was an automated decision. Article 20: a way to
 * appeal from the same screen. The appeal lands in the admin appeals queue,
 * carrying the original decider so it can never be routed back to them.
 */
class Restricted extends Component
{
    use InteractsWithMember;

    public string $statement = '';

    public function render(): View
    {
        $ban = $this->ban();

        return view('livewire.member.restricted', [
            'ban' => $ban,
            'action' => $ban?->moderationAction,
            'appeal' => $ban ? Appeal::query()->where('ban_id', $ban->id)->latest()->first() : null,
        ])->layout('components.layouts.member-auth', ['title' => 'Account restricted']);
    }

    public function appeal(): void
    {
        $this->validate(
            ['statement' => ['required', 'string', 'min:20', 'max:2000']],
            ['statement.min' => 'Tell us a little more — at least a couple of sentences.'],
        );

        $ban = $this->ban();
        abort_if($ban === null, 404);

        // One open appeal per restriction; a second would only split the
        // reviewer's attention across duplicates.
        if (Appeal::query()->where('ban_id', $ban->id)->exists()) {
            return;
        }

        Appeal::query()->create([
            'uuid' => (string) Str::uuid(),
            'app_user_id' => $this->member()->id,
            'ban_id' => $ban->id,
            'moderation_action_id' => $ban->moderation_action_id,
            'original_decider_id' => $ban->moderationAction?->actor_id ?? $ban->issued_by,
            'status' => 'new',
            'user_statement' => trim($this->statement),
            'sla_due_at' => now()->addHours((int) config('veyra.sla.appeal_hours', 72)),
        ]);

        $this->statement = '';
    }

    public function signOut(): void
    {
        auth('member')->logout();
        session()->regenerateToken();
        $this->redirectRoute('home');
    }

    private function ban(): ?Ban
    {
        $me = $this->member();

        return $me->activeBan
            ?? Ban::query()->where('app_user_id', $me->id)->active()->whereIn('type', ['suspension', 'permanent_ban', 'device_ban'])->latest('starts_at')->first();
    }
}
