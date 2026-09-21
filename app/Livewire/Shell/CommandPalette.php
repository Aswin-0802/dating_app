<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Models\AppUser;
use App\Models\ReportCase;
use App\Models\Verification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Global search, opened with Cmd/Ctrl+K.
 *
 * Results are grouped by type and carry their status inline, because the common
 * question is not "does this member exist" but "what state are they in" — and
 * answering that in the palette saves opening the record at all.
 */
class CommandPalette extends Component
{
    public bool $open = false;

    public string $query = '';

    public function render(): View
    {
        return view('livewire.shell.command-palette', [
            'groups' => strlen(trim($this->query)) >= 2 ? $this->search() : [],
            'shortcuts' => $this->shortcuts(),
        ]);
    }

    public function openPalette(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function search(): array
    {
        $term = trim($this->query);
        $user = auth()->user();
        $groups = [];

        if ($user?->can('users')) {
            $members = AppUser::query()
                ->search($term)
                ->with('primaryPhoto')
                ->limit(5)
                ->get();

            if ($members->isNotEmpty()) {
                $groups['Members'] = $members->map(fn (AppUser $m): array => [
                    'label' => $m->display_name,
                    // Status in the result row: the question is usually "what
                    // state is this account in", not "does it exist".
                    'meta' => $m->account_status->label().' · '.($m->city?->name ?? 'No city'),
                    'badge' => $m->account_status->badgeClasses(),
                    'badge_label' => $m->account_status->label(),
                    'url' => route('admin.users.show', $m),
                    'photo' => $m->primaryPhoto?->thumb_url,
                ])->all();
            }
        }

        if ($user?->can('cases')) {
            $cases = ReportCase::query()
                ->where('case_number', 'like', "%{$term}%")
                ->orWhereHas('subject', fn ($q) => $q->search($term))
                ->with('subject')
                ->limit(5)
                ->get();

            if ($cases->isNotEmpty()) {
                $groups['Cases'] = $cases->map(fn (ReportCase $c): array => [
                    'label' => $c->case_number,
                    'meta' => ($c->subject?->display_name ?? 'Unknown').' · '.$c->reports_count.' reports',
                    'badge' => $c->severity->badgeClasses(),
                    'badge_label' => $c->severity->label(),
                    'url' => route('admin.cases.show', $c),
                    'photo' => null,
                ])->all();
            }
        }

        if ($user?->can('verifications')) {
            $verifications = Verification::query()
                ->open()
                ->where('queue', 'standard')
                ->whereHas('appUser', fn ($q) => $q->search($term))
                ->with('appUser')
                ->limit(5)
                ->get();

            if ($verifications->isNotEmpty()) {
                $groups['Verifications'] = $verifications->map(fn (Verification $v): array => [
                    'label' => $v->appUser?->display_name ?? 'Unknown',
                    'meta' => 'Waiting '.platform_duration($v->submitted_at),
                    'badge' => $v->status->badgeClasses(),
                    'badge_label' => $v->status->label(),
                    'url' => route('admin.verifications.review', $v),
                    'photo' => $v->appUser?->primaryPhoto?->thumb_url,
                ])->all();
            }
        }

        return $groups;
    }

    /**
     * Where somebody most often wants to go, shown before they type.
     *
     * An empty palette is a dead end; these turn it into a launcher.
     *
     * @return array<int, array<string, string>>
     */
    private function shortcuts(): array
    {
        $user = auth()->user();

        return collect([
            ['label' => 'Open cases', 'icon' => 'flag', 'route' => 'admin.cases.index', 'permission' => 'cases'],
            ['label' => 'Verification queue', 'icon' => 'shield-check', 'route' => 'admin.verifications.index', 'permission' => 'verifications'],
            ['label' => 'Shadow ban reviews', 'icon' => 'eye-off', 'route' => 'admin.enforcement.shadow-reviews', 'permission' => 'shadow_ban_users'],
            ['label' => 'Appeals', 'icon' => 'scale', 'route' => 'admin.appeals.index', 'permission' => 'appeals'],
            ['label' => 'Audit log', 'icon' => 'clipboard-list', 'route' => 'admin.audit.index', 'permission' => 'activity_log'],
        ])
            ->filter(fn (array $s): bool => $user?->can($s['permission']) && Route::has($s['route']))
            ->map(fn (array $s): array => [
                'label' => $s['label'],
                'icon' => $s['icon'],
                'url' => route($s['route']),
            ])
            ->values()
            ->all();
    }
}
