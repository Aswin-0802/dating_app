<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * The single write path into the staff audit trail.
 *
 * Actor identity is snapshotted onto the row, so the log still reads correctly
 * after a staff member is deleted — an audit trail full of "User #14" is not
 * much of an audit trail.
 */
final class ActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $module,
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        bool $sensitive = false,
    ): ActivityLog {
        /*
         * Only staff can be an actor here.
         *
         * Plenty of logged events are set off by a member — a payment landing,
         * a report being filed — and Auth::user() would then hand back an
         * AppUser, which has no roles. This log is the staff audit trail, so
         * those rows are recorded with no actor, which reads correctly: nobody
         * on the team did it.
         */
        $actor = Auth::guard('web')->user();
        $actor = $actor instanceof User ? $actor : null;

        return ActivityLog::query()->create([
            'user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->getRoleNames()->first(),
            'module' => $module,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'is_sensitive' => $sensitive,
            'ip_address' => Request::ip(),
            'user_agent' => str(Request::userAgent() ?? '')->limit(255)->toString(),
        ]);
    }

    /**
     * Log an update, recording only the fields that actually changed.
     *
     * Storing the whole model on both sides is what makes an audit log unusable:
     * every entry looks like a total rewrite and real changes get lost.
     */
    public function logUpdate(string $module, Model $subject, array $before, ?string $description = null): ?ActivityLog
    {
        $after = $subject->getAttributes();
        $changedKeys = [];

        foreach ($before as $key => $value) {
            if (($after[$key] ?? null) != $value) {
                $changedKeys[] = $key;
            }
        }

        if ($changedKeys === []) {
            return null;
        }

        return $this->log(
            module: $module,
            action: 'updated',
            subject: $subject,
            description: $description,
            old: array_intersect_key($before, array_flip($changedKeys)),
            new: array_intersect_key($after, array_flip($changedKeys)),
        );
    }

    /** A privileged read — message content, PII export — always flagged. */
    public function logSensitiveAccess(
        string $module,
        string $action,
        Model $subject,
        string $description,
        ?array $context = null,
    ): ActivityLog {
        return $this->log(
            module: $module,
            action: $action,
            subject: $subject,
            description: $description,
            new: $context,
            sensitive: true,
        );
    }
}
