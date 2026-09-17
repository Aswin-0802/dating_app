<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The admin sidebar, defined once.
 *
 * Items declare the permission they require; `sections()` filters the tree for
 * the current user and drops any group left empty. Restricted areas therefore
 * become invisible rather than merely disabled — a moderator without the
 * minor-safety permission never learns that queue exists.
 */
final class Navigation
{
    /**
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public static function sections(): array
    {
        $sections = [
            [
                'label' => null,
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'dashboard',
                        'route' => 'admin.dashboard',
                        'permission' => 'dashboard',
                    ],
                ],
            ],
            [
                'label' => 'Community',
                'items' => [
                    [
                        'label' => 'Users',
                        'icon' => 'users',
                        'route' => 'admin.users.index',
                        'permission' => 'users',
                        'active' => ['admin.users.*'],
                    ],
                    [
                        'label' => 'Verification',
                        'icon' => 'shield-check',
                        'route' => 'admin.verifications.index',
                        'permission' => 'verifications',
                        'active' => ['admin.verifications.*'],
                        'badge' => 'verifications_pending',
                    ],
                    [
                        'label' => 'Matches',
                        'icon' => 'heart',
                        'route' => 'admin.matches.index',
                        'permission' => 'matches',
                        'active' => ['admin.matches.*'],
                    ],
                    [
                        'label' => 'Conversations',
                        'icon' => 'chat',
                        'route' => 'admin.conversations.index',
                        'permission' => 'conversations',
                        'active' => ['admin.conversations.*'],
                    ],
                ],
            ],
            [
                'label' => 'Trust & Safety',
                'items' => [
                    [
                        'label' => 'Cases',
                        'icon' => 'flag',
                        'route' => 'admin.cases.index',
                        'permission' => 'cases',
                        'active' => ['admin.cases.*'],
                        'badge' => 'cases_open',
                    ],
                    [
                        'label' => 'Enforcement',
                        'icon' => 'ban',
                        'permission' => 'bans',
                        'children' => [
                            ['label' => 'Bans', 'route' => 'admin.enforcement.bans', 'permission' => 'bans'],
                            ['label' => 'Shadow ban reviews', 'route' => 'admin.enforcement.shadow-reviews', 'permission' => 'shadow_ban_users', 'badge' => 'shadow_reviews_due'],
                            ['label' => 'Banned devices', 'route' => 'admin.enforcement.devices', 'permission' => 'device_ban_users'],
                            ['label' => 'Blocks', 'route' => 'admin.enforcement.blocks', 'permission' => 'blocks'],
                        ],
                    ],
                    [
                        'label' => 'Appeals',
                        'icon' => 'scale',
                        'route' => 'admin.appeals.index',
                        'permission' => 'appeals',
                        'active' => ['admin.appeals.*'],
                        'badge' => 'appeals_open',
                    ],
                ],
            ],
            [
                'label' => 'Operations',
                'items' => [
                    [
                        'label' => 'Notifications',
                        'icon' => 'bell',
                        'permission' => 'notifications',
                        'children' => [
                            ['label' => 'Campaigns', 'route' => 'admin.notifications.campaigns', 'permission' => 'notifications'],
                            ['label' => 'Templates', 'route' => 'admin.notifications.templates', 'permission' => 'notification_templates'],
                            ['label' => 'Delivery logs', 'route' => 'admin.notifications.logs', 'permission' => 'push_logs'],
                        ],
                        'active' => ['admin.notifications.*'],
                    ],
                    [
                        'label' => 'Analytics',
                        'icon' => 'chart-bar',
                        'permission' => 'analytics',
                        'children' => [
                            ['label' => 'Funnel', 'route' => 'admin.analytics.funnel', 'permission' => 'analytics'],
                            ['label' => 'Matching health', 'route' => 'admin.analytics.matching', 'permission' => 'analytics'],
                            ['label' => 'Safety trends', 'route' => 'admin.analytics.safety', 'permission' => 'analytics'],
                            ['label' => 'Retention', 'route' => 'admin.analytics.retention', 'permission' => 'analytics'],
                        ],
                    ],
                ],
            ],
            [
                'label' => 'Administration',
                'items' => [
                    [
                        'label' => 'Staff',
                        'icon' => 'user-circle',
                        'route' => 'admin.staff.index',
                        'permission' => 'staff',
                        'active' => ['admin.staff.*'],
                    ],
                    [
                        'label' => 'Roles',
                        'icon' => 'key',
                        'route' => 'admin.roles.index',
                        'permission' => 'roles',
                        'active' => ['admin.roles.*'],
                    ],
                    [
                        'label' => 'Audit log',
                        'icon' => 'clipboard-list',
                        'route' => 'admin.audit.index',
                        'permission' => 'activity_log',
                        'active' => ['admin.audit.*'],
                    ],
                    [
                        'label' => 'Settings',
                        'icon' => 'cog',
                        'route' => 'admin.settings.general',
                        'permission' => 'settings',
                        'active' => ['admin.settings.*'],
                    ],
                    /*
                     * System sits apart from Settings on purpose.
                     *
                     * Settings is product and safety policy; System is
                     * infrastructure — mail, payment and SMS providers and
                     * their delivery logs. The people who own them are rarely
                     * the same, and mixing the two puts "switch the payment
                     * provider" next to "change what counts as a ban".
                     */
                    [
                        'label' => 'System',
                        'icon' => 'adjustments',
                        'permission' => 'settings',
                        'active' => ['admin.system.*'],
                        'children' => [
                            ['label' => 'Mail / SMTP', 'route' => 'admin.system.mail', 'permission' => 'settings'],
                            ['label' => 'Payment gateways', 'route' => 'admin.system.payments', 'permission' => 'settings'],
                            ['label' => 'SMS gateways', 'route' => 'admin.system.sms', 'permission' => 'settings'],
                            ['label' => 'Delivery logs', 'route' => 'admin.system.logs', 'permission' => 'settings'],
                            ['label' => 'Database backup', 'route' => 'admin.system.backup', 'permission' => 'run_maintenance_jobs'],
                        ],
                    ],
                ],
            ],
        ];

        return self::filter($sections);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private static function filter(array $sections): array
    {
        $visible = [];

        foreach ($sections as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (! self::allows($item['permission'] ?? null)) {
                    continue;
                }

                if (isset($item['children'])) {
                    $children = array_values(array_filter(
                        $item['children'],
                        fn (array $child): bool => self::allows($child['permission'] ?? null)
                            && self::routeExists($child['route'] ?? null),
                    ));

                    // A parent whose children are all hidden has nothing to show.
                    if ($children === []) {
                        continue;
                    }

                    $item['children'] = $children;
                } elseif (! self::routeExists($item['route'] ?? null)) {
                    continue;
                }

                $items[] = $item;
            }

            if ($items !== []) {
                $section['items'] = $items;
                $visible[] = $section;
            }
        }

        return $visible;
    }

    private static function allows(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        // `can` resolves through spatie's gate, including the Super Admin bypass.
        return $user->can($permission);
    }

    /**
     * Routes are built module by module, so the sidebar must tolerate ones that
     * do not exist yet rather than throwing during early phases.
     */
    private static function routeExists(?string $name): bool
    {
        return $name !== null && Route::has($name);
    }

    /**
     * Whether a nav item matches the current route.
     *
     * @param  array<string, mixed>  $item
     */
    public static function isActive(array $item): bool
    {
        $current = Route::currentRouteName();

        if ($current === null) {
            return false;
        }

        foreach ($item['active'] ?? [] as $pattern) {
            if (fnmatch($pattern, $current)) {
                return true;
            }
        }

        if (isset($item['route']) && $item['route'] === $current) {
            return true;
        }

        foreach ($item['children'] ?? [] as $child) {
            if (($child['route'] ?? null) === $current) {
                return true;
            }
        }

        return false;
    }
}
