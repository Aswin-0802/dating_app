<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Veyra
|--------------------------------------------------------------------------
|
| Defaults for the admin console and trust & safety engine.
|
| Anything an operator should be able to change without a deploy also exists as
| a row in the `settings` table; the values here are the fallbacks used before
| settings are seeded, and during tests. Read them through `veyra_setting()`
| rather than `config()` so the database always wins.
|
*/

return [

    'brand' => [
        'name' => 'Veyra',
        'tagline' => 'Trust & Safety Console',
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | `scale` trades realism for seeding time and database size: tiny (600
    | members) | small (1,200) | demo (12,000) | large (48,000). `tiny` is the
    | default because the dataset exists to exercise the UI, and every queue,
    | chart and filter still has content at that size.
    |
    | `photos` controls how profile imagery is produced: `generated` draws them
    | locally with GD and needs no network at all.
    |
    */
    'seed' => [
        'scale' => env('VEYRA_SEED_SCALE', 'tiny'),
        'photos' => env('VEYRA_SEED_PHOTOS', 'generated'),
        // Fixed so analytics screenshots and assertions do not drift between runs.
        'faker_seed' => 20260917,
    ],

    /*
    |--------------------------------------------------------------------------
    | Service level agreements
    |--------------------------------------------------------------------------
    |
    | Hours a queue item may wait before it is treated as breaching. Case SLAs
    | are per-severity and live on the Severity enum; these cover everything else.
    |
    */
    'sla' => [
        'verification_hours' => 24,
        'restricted_verification_hours' => 4,
        'appeal_hours' => 72,
        // Age thresholds for the pill colour on a queue row.
        'pill' => [
            'warning_hours' => 1,
            'breach_hours' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk engine
    |--------------------------------------------------------------------------
    |
    | Band thresholds against the 0-100 score. Factor weights are database rows
    | (`risk_factor_definitions`) so trust & safety can tune them live.
    |
    */
    'risk' => [
        'bands' => [
            'low' => 0,
            'elevated' => 25,
            'high' => 50,
            'critical' => 75,
        ],
        // Scores at or above this are pushed to the top of review queues.
        'auto_queue_at' => 50,
        // Scores at or above this attract an automatic feature limit.
        'auto_limit_at' => 75,
        'recalculate_after_hours' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement
    |--------------------------------------------------------------------------
    */
    'enforcement' => [
        'suspension_durations' => [24, 168, 720], // 24h, 7d, 30d
        'shadow_ban_default_hours' => 168,
        // Shadow bans are invisible to the member, so they expire on their own
        // and force a staff review. Never open-ended.
        'shadow_ban_max_hours' => 720,
        'shadow_ban_review_after_hours' => 168,
        'feature_limit_options' => [
            'new_likes' => 'Cannot send new likes',
            'photo_upload' => 'Cannot upload photos',
            'new_messages' => 'Cannot message new matches',
            'profile_edit' => 'Cannot edit profile',
            'discovery' => 'Removed from discovery',
        ],
        'appeal_window_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    */
    'verification' => [
        'max_attempts' => 3,
        'approve_threshold' => 0.80,
        'reject_threshold' => 0.55,
        'liveness_threshold' => 0.70,
        // A face seen on this many other accounts is surfaced as the hero signal.
        'duplicate_face_alert_at' => 1,
        'expires_after_days' => 365,
        'disk' => 'verifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    */
    'tables' => [
        'per_page' => 25,
        'per_page_options' => [10, 25, 50, 100],
        'density' => 'comfortable',
        // Beyond this a bulk action is dispatched to the queue instead of run inline.
        'bulk_inline_limit' => 500,
        'export_chunk' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | Rate limits in "attempts per window". New accounts get half of the swipe
    | and message allowances, which is cheap anti-spam and also feeds the
    | velocity risk factors when somebody hits the ceiling.
    |
    */
    'api' => [
        'new_account_hours' => 24,
        'rate_limits' => [
            'api' => ['attempts' => 90, 'per_minutes' => 1],
            'auth' => ['attempts' => 8, 'per_minutes' => 1],
            'otp' => ['attempts' => 4, 'per_minutes' => 10],
            'deck' => ['attempts' => 60, 'per_minutes' => 1],
            'swipe' => ['attempts' => 120, 'per_minutes' => 1],
            'message' => ['attempts' => 30, 'per_minutes' => 1],
            'upload' => ['attempts' => 20, 'per_minutes' => 60],
            'report' => ['attempts' => 10, 'per_minutes' => 60],
            'verification' => ['attempts' => 3, 'per_minutes' => 1440],
        ],
        'daily_like_limit' => [
            'free' => 100,
            'premium' => null, // unlimited
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Reading a member's message content is a privileged, logged act. These
    | control how long a reveal lasts and what must be captured to justify it.
    |
    */
    'privacy' => [
        'message_reveal_minutes' => 15,
        'message_context_window' => 10, // messages either side of the anchor
        'reveal_reasons' => [
            'case_review' => 'Reviewing a reported case',
            'appeal_review' => 'Reviewing an appeal',
            'legal_request' => 'Legal or law enforcement request',
            'safety_escalation' => 'Active safety escalation',
        ],
    ],
];
