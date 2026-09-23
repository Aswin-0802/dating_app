<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AppUser;
use App\Models\MatchRecord;
use Illuminate\Support\Facades\DB;

/**
 * Ending a match, shared by the website and the mobile API.
 *
 * A match and its conversation are one thing to a member: "we are no longer
 * talking". They were two things in the code, and each caller remembered to
 * write both rows or did not — the API unmatched without closing the thread,
 * so the other party kept sending messages into a match that no longer
 * existed. This class is the single owner of that pair.
 */
final class MatchActions
{
    /**
     * End one match and close the thread that belongs to it.
     *
     * @param  string  $status  'unmatched' when a member chose to, 'blocked' when it
     *                          followed from a block — the moderation queue reads the
     *                          difference, so it is not collapsed into one value.
     */
    public function end(AppUser $actor, MatchRecord $match, string $status = 'unmatched'): void
    {
        abort_unless(
            in_array($actor->id, [$match->app_user_one_id, $match->app_user_two_id], true),
            403,
            'This match is not yours.',
        );

        DB::transaction(function () use ($actor, $match, $status): void {
            $match->forceFill(['status' => $status, 'unmatched_by' => $actor->id])->save();

            // Closing the conversation is what actually stops the messages:
            // MessageSender refuses anything that is not open.
            $match->conversation()->update(['status' => 'closed']);
        });
    }

    /**
     * End every match between two members.
     *
     * The pair is unique, so this is one row in practice — but blocking must
     * not depend on that holding, because a missed row is a member who can
     * still message somebody who blocked them.
     */
    public function endAllBetween(AppUser $actor, AppUser $other, string $status = 'unmatched'): void
    {
        MatchRecord::query()
            ->involving($actor)
            ->involving($other)
            ->get()
            ->each(fn (MatchRecord $match) => $this->end($actor, $match, $status));
    }
}
