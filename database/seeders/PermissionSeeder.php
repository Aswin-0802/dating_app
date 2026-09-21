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
 * touching existing grants. `php artisan veyra:sync-permissions` wraps this.
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
                ['export_analytics', 'Export analytics'],
            ],

            'Users' => [
                ['users', 'View users'],
                ['view_user_pii', 'View personal data (email, phone)', true],
                ['edit_users', 'Edit user profiles'],
                ['delete_users', 'Delete users'],
                ['export_users', 'Export user data', true],
                ['impersonate_users', 'Impersonate a user', true],
                ['view_user_photos', 'View user photos'],
                ['moderate_photos', 'Approve or remove photos'],
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
                ['unmatch_users', 'Force unmatch'],
            ],

            'Conversations' => [
                ['conversations', 'View conversation metadata'],
                ['view_message_content', 'Reveal message content', true],
                ['remove_messages', 'Remove messages'],
                ['close_conversations', 'Freeze or close conversations'],
                ['message_access_log', 'Review message access log', true],
            ],

            'Cases' => [
                ['cases', 'View cases'],
                ['claim_cases', 'Claim cases'],
                ['merge_cases', 'Merge or split cases'],
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
                ['login_log', 'View login log'],
                ['export_audit_logs', 'Export audit logs', true],
            ],

            'Settings' => [
                ['settings', 'View settings'],
                ['edit_general_settings', 'Edit general settings'],
                ['edit_moderation_settings', 'Edit moderation settings'],
                ['edit_risk_settings', 'Edit risk factor weights'],
                ['edit_matching_settings', 'Edit matching settings'],
                ['edit_api_settings', 'Edit API limits'],
                ['automation_rules', 'View automation rules'],
                ['edit_automation_rules', 'Edit automation rules'],
                ['run_maintenance_jobs', 'Run maintenance jobs'],
                ['manage_api_tokens', 'Manage API tokens', true],
            ],
        ];
    }

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Seeded '.Permission::query()->count().' permissions.');
    }
}
