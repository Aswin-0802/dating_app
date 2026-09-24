<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use App\Models\MatchRecord;
use App\Services\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A member deleting their own account.
 *
 * Soft-delete and anonymise, never a hard erase. The moderation record is the
 * platform's backbone: reports this member filed are evidence in cases against
 * OTHER people, and report_cases, moderation_actions and message_access_logs
 * are append-only by design. Erasing the row would break all of them. What
 * the member is owed — that nothing identifies them any more — is met by
 * pseudonymising the row and removing everything personal from it.
 *
 * Distinct from deactivation, which is reversible and keeps everything.
 * Shared by the website and the mobile API.
 */
final class AccountDeletion
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly MatchActions $matches,
    ) {}

    /**
     * @throws ValidationException when the password is wrong
     */
    public function delete(AppUser $member, string $password): void
    {
        if (! Hash::check($password, $member->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }

        DB::transaction(function () use ($member): void {
            /*
             * Conversations are closed for the other party, the same way an
             * unmatch closes them. The messages stay: they are the other
             * person's history, and they may be evidence.
             */
            MatchRecord::query()
                ->involving($member)
                ->active()
                ->get()
                ->each(fn (MatchRecord $match) => $this->matches->end($member, $match, 'unmatched'));

            // Photos are the most identifying thing on the account. Unlike a
            // member removing one photo, deleting the account removes the files.
            foreach ($member->photos()->get() as $photo) {
                Storage::disk($photo->disk)->delete(array_values(array_filter([$photo->path, $photo->thumb_path])));
                $photo->delete();
            }

            $member->profile?->forceFill([
                'bio' => null,
                'bio_contains_contact' => false,
                'prompts' => null,
                'job_title' => null,
                'company' => null,
                'school' => null,
            ])->save();

            // Every way back in is closed: API tokens, push registrations.
            $member->tokens()->delete();
            $member->pushTokens()->delete();

            $member->forceFill([
                'email' => "deleted-{$member->uuid}@invalid",
                'phone' => null,
                'display_name' => 'Deleted member',
                'last_latitude' => null,
                'last_longitude' => null,
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'account_status' => AccountStatus::Deactivated,
            ])->save();

            $this->logger->log(
                module: 'members',
                action: 'account_deleted',
                subject: $member,
                description: "Member {$member->uuid} deleted their account",
                sensitive: true,
            );

            // Soft: reports, cases, actions, bans and audit rows that point at
            // this id keep pointing at a row that exists.
            $member->delete();
        });
    }
}
