<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue.
 *
 * Idempotent: re-running after adding a permission repairs the table without
 * touching existing grants. `php artisan platform:sync-permissions` wraps this.
 */
class PermissionSeeder extends Seeder
{
    /**
     * [name, label, sensitive?]
     *
     * @return array<string, array<int, array{0: string, 1: string, 2?: bool}>>
     */
    public static function catalogue(): array
    {
        return [
            'Dashboard' => [
                ['dashboard', 'View dashboard'],
                ['analytics', 'View analytics'],
            ],

            'Users' => [
                ['users', 'View users'],
                ['view_user_pii', 'View personal data (email, phone)', true],
                ['edit_users', 'Edit user profiles'],
                ['export_users', 'Export user data', true],
                ['view_user_photos', 'View user photos'],
            ],

            'Verification' => [
                ['verifications', 'View verification queue'],
                ['claim_verifications', 'Claim verification items'],
                ['decide_verifications', 'Approve or reject verifications'],
                ['restricted_minor_queue', 'Access the minor-safety queue', true],
                ['verification_settings', 'Configure verification'],
            ],

            'Matches' => [
                ['matches', 'View matches'],
            ],

            'Conversations' => [
                ['conversations', 'View conversation metadata'],
                ['view_message_content', 'Reveal message content', true],
                ['message_access_log', 'Review message access log', true],
            ],

            'Cases' => [
                ['cases', 'View cases'],
                ['claim_cases', 'Claim cases'],
                ['close_cases', 'Close cases'],
                ['reassign_cases', 'Reassign cases'],
            ],

            'Enforcement' => [
                ['bans', 'View enforcement records'],
                ['warn_users', 'Issue warnings'],
                ['limit_users', 'Apply feature limits'],
                ['shadow_ban_users', 'Apply shadow bans'],
                ['suspend_users', 'Suspend accounts'],
                ['ban_users', 'Permanently ban accounts'],
                ['device_ban_users', 'Ban devices', true],
                ['lift_enforcement', 'Lift enforcement'],
                ['extend_enforcement', 'Extend enforcement'],
                ['blocks', 'View blocks'],
            ],

            'Appeals' => [
                ['appeals', 'View appeals'],
                ['assign_appeals', 'Assign appeals'],
                ['decide_appeals', 'Decide appeals'],
                ['overturn_decisions', 'Overturn decisions'],
            ],

            'Notifications' => [
                ['notifications', 'View campaigns'],
                ['create_campaigns', 'Create campaigns'],
                ['send_notifications', 'Send campaigns'],
                ['approve_campaigns', 'Approve campaigns'],
                ['notification_templates', 'View templates'],
                ['edit_notification_templates', 'Edit templates'],
                ['push_logs', 'View delivery logs'],
            ],

            /*
             * Billing is its own group so finance and support can see money
             * without being handed safety powers, and so a moderator never
             * sees revenue at all.
             */
            'Billing' => [
                ['payments', 'View payments and subscriptions'],
                ['billing_settings', 'Change plans and payment gateways'],
                ['export_payments', 'Export payments'],
                ['grant_plans', 'Give or remove a member\'s plan'],
            ],

            'Staff' => [
                ['staff', 'View staff'],
                ['add_staff', 'Add staff'],
                ['edit_staff', 'Edit staff'],
                ['delete_staff', 'Remove staff'],
                ['staff_status_toggle', 'Activate or suspend staff'],
                ['view_staff_performance', 'View performance scorecards'],
            ],

            'Roles' => [
                ['roles', 'View roles'],
                ['add_roles', 'Create roles'],
                ['edit_roles', 'Edit roles'],
                ['delete_roles', 'Delete roles'],
                ['assign_permissions', 'Change role permissions', true],
            ],

            'Audit' => [
                ['activity_log', 'View activity log'],
                ['export_audit_logs', 'Export audit logs', true],
            ],

            'Settings' => [
                ['settings', 'View settings'],
                ['edit_general_settings', 'Edit general settings'],
                ['edit_moderation_settings', 'Edit moderation settings'],
                ['edit_risk_settings', 'Edit risk factor weights'],
                ['edit_matching_settings', 'Edit matching settings'],
                ['edit_api_settings', 'Edit API limits'],
                ['run_maintenance_jobs', 'Run maintenance jobs'],
            ],
        ];
    }

    /**
     * Permissions that were granted, displayed in the role matrix, and checked
     * by nothing.
     *
     * Each named a capability the console does not have. That is worse than a
     * missing feature: an admin who *denied* "Remove messages" to a moderator
     * reasonably believed they had restricted something real, and the matrix
     * told them so. They are deleted here rather than merely dropped from the
     * catalogue, so an instance that already has the rows is repaired too.
     *
     * If any of these capabilities get built, the permission comes back with
     * the feature and a check behind it.
     *
     * @var array<int, string>
     */
    public const OBSOLETE = [
        'delete_users',
        'impersonate_users',
        'moderate_photos',
        'unmatch_users',
        'close_conversations',
        'remove_messages',
        'merge_cases',
        'automation_rules',
        'edit_automation_rules',
        'manage_api_tokens',
        'login_log',
        'export_analytics',
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::catalogue() as $group => $permissions) {
            foreach ($permissions as $permission) {
                [$name, $label] = $permission;
                $sensitive = $permission[2] ?? false;

                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    [
                        'group_name' => $group,
                        'label' => $label,
                        'sort_order' => $sort++,
                        'is_sensitive' => $sensitive,
                    ],
                );
            }
        }

        // Removing the row removes every grant of it: spatie's pivot rows are
        // cascaded, so no role is left pointing at a permission that is gone.
        $dropped = Permission::query()->whereIn('name', self::OBSOLETE)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($dropped > 0) {
            $this->command?->warn("Removed {$dropped} permission(s) that nothing checked.");
        }

        $this->command?->info('Seeded '.Permission::query()->count().' permissions.');
    }
}
