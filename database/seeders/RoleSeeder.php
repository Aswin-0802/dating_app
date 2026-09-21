<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The seven Platform roles.
 *
 * Two separations are deliberate and load-bearing:
 *
 *  - Admin is platform operations and does NOT get message content, appeal
 *    decisions, or moderation policy settings. T&S Lead owns safety policy.
 *    That split is what makes the audit log meaningful — otherwise one role
 *    can both act and rewrite the rules it acted under.
 *
 *  - Moderator does NOT get `decide_appeals`. Moderators issue most
 *    first-instance decisions, so excluding them guarantees the "an appeal is
 *    never decided by its original decider" rule always has someone to route to.
 */
class RoleSeeder extends Seeder
{
    /** @return array<string, array<int, string>|string> */
    public static function matrix(): array
    {
        return [
            // '*' grants everything; also backed by a Gate::before bypass.
            Role::SUPER_ADMIN => '*',

            Role::ADMIN => [
                'dashboard', 'analytics', 'export_analytics',
                'users', 'view_user_pii', 'edit_users', 'delete_users', 'export_users',
                'view_user_photos', 'moderate_photos',
                'verifications', 'claim_verifications', 'decide_verifications',
                'matches', 'unmatch_users',
                'conversations', 'close_conversations',
                'cases', 'claim_cases', 'merge_cases', 'close_cases', 'reassign_cases',
                'bans', 'warn_users', 'limit_users', 'shadow_ban_users', 'suspend_users',
                'ban_users', 'lift_enforcement', 'extend_enforcement', 'blocks',
                'appeals', 'assign_appeals',
                'notifications', 'create_campaigns', 'send_notifications', 'approve_campaigns',
                'notification_templates', 'edit_notification_templates', 'push_logs',
                'staff', 'add_staff', 'edit_staff', 'delete_staff', 'staff_status_toggle',
                'view_staff_performance',
                'roles',
                'activity_log', 'login_log', 'export_audit_logs', 'message_access_log',
                'payments', 'export_payments', 'grant_plans', 'billing_settings',
                'settings', 'edit_general_settings', 'edit_matching_settings', 'edit_api_settings',
                'automation_rules', 'run_maintenance_jobs', 'manage_api_tokens',
            ],

            Role::TS_LEAD => [
                'dashboard', 'analytics', 'export_analytics',
                'users', 'view_user_pii', 'edit_users', 'export_users',
                'view_user_photos', 'moderate_photos',
                'verifications', 'claim_verifications', 'decide_verifications',
                'restricted_minor_queue', 'verification_settings',
                'matches', 'unmatch_users',
                'conversations', 'view_message_content', 'remove_messages', 'close_conversations',
                'message_access_log',
                'cases', 'claim_cases', 'merge_cases', 'close_cases', 'reassign_cases',
                'bans', 'warn_users', 'limit_users', 'shadow_ban_users', 'suspend_users',
                'ban_users', 'device_ban_users', 'lift_enforcement', 'extend_enforcement', 'blocks',
                'appeals', 'assign_appeals', 'decide_appeals', 'overturn_decisions',
                'staff', 'view_staff_performance',
                'activity_log', 'login_log', 'export_audit_logs',
                'settings', 'edit_moderation_settings', 'edit_risk_settings',
                'automation_rules', 'edit_automation_rules',
            ],

            Role::SENIOR_MODERATOR => [
                'dashboard', 'analytics',
                'users', 'view_user_pii', 'view_user_photos', 'moderate_photos',
                'verifications', 'claim_verifications', 'decide_verifications',
                'restricted_minor_queue',
                'matches', 'unmatch_users',
                'conversations', 'view_message_content', 'remove_messages', 'close_conversations',
                'cases', 'claim_cases', 'merge_cases', 'close_cases',
                'bans', 'warn_users', 'limit_users', 'shadow_ban_users', 'suspend_users',
                'ban_users', 'device_ban_users', 'lift_enforcement', 'extend_enforcement', 'blocks',
                'appeals', 'decide_appeals', 'overturn_decisions',
                'activity_log', 'login_log',
                'automation_rules',
            ],

            Role::MODERATOR => [
                'dashboard',
                'users', 'view_user_photos', 'moderate_photos',
                'verifications', 'claim_verifications', 'decide_verifications',
                'matches',
                'conversations',
                'cases', 'claim_cases', 'close_cases',
                'bans', 'warn_users', 'limit_users', 'shadow_ban_users', 'suspend_users',
                'lift_enforcement', 'blocks',
                'appeals',
                'activity_log',
            ],

            // Support answers real users' emails, so it needs PII — but no
            // enforcement powers and no message content.
            Role::SUPPORT => [
                'dashboard',
                'users', 'view_user_pii', 'view_user_photos',
                'verifications',
                'matches',
                'conversations',
                'cases',
                'bans', 'blocks',
                'appeals',
                'notifications', 'create_campaigns', 'notification_templates', 'push_logs',
                'payments', 'grant_plans',
            ],

            // Analysts work from rollups and de-identified lists.
            Role::ANALYST => [
                'dashboard', 'analytics', 'export_analytics',
                'users',
                'verifications',
                'matches',
                'cases',
                'bans',
                'push_logs',
            ],
        ];
    }

    public function run(): void
    {
        $all = Permission::query()->pluck('name')->all();

        foreach (self::matrix() as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $role->syncPermissions($permissions === '*' ? $all : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Seeded '.Role::query()->count().' roles.');
    }
}
