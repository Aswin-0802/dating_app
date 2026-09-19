<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Enums\AccountStatus;
use App\Enums\Gender;
use App\Enums\LadderStep;
use App\Enums\RiskBand;
use App\Enums\VerificationStatus;
use App\Livewire\Concerns\AppliesEnforcement;
use App\Livewire\Concerns\WithBulkActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\AppUser;
use App\Models\City;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use App\Support\Masters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    use AppliesEnforcement;
    use WithBulkActions;
    use WithDataTable;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $verification = '';

    #[Url(except: '')]
    public string $risk = '';

    #[Url(except: '')]
    public string $gender = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $premium = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $photos = '';

    #[Url(except: '')]
    public string $reported = '';

    #[Url(except: '')]
    public string $joined = '';

    #[Url(except: '')]
    public string $lastActive = '';

    public bool $filtersOpen = false;

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $users = $this->applySort($this->baseQuery())
            ->with(['city.country', 'primaryPhoto'])
            ->withCount(['reportsAgainst', 'photos'])
            ->paginate($this->perPage);

        return view('livewire.users.index', [
            'users' => $users,
            'cities' => City::query()->with('country')->orderBy('name')->get(),
        ])->layout('components.layouts.admin', [
            'title' => 'Users',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Users'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return [
            'display_name', 'created_at', 'last_active_at', 'risk_score',
            'profile_completion', 'birthdate',
        ];
    }

    /**
     * The filtered, unsorted, unpaginated query.
     *
     * Bulk actions reuse this, so "select all matching" always means exactly the
     * same set the table is showing.
     */
    protected function baseQuery(): Builder
    {
        return AppUser::query()
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $q) => $q->where('account_status', $this->status))
            ->when($this->verification !== '', fn (Builder $q) => $q->where('verification_status', $this->verification))
            ->when($this->risk !== '', fn (Builder $q) => $q->where('risk_band', $this->risk))
            ->when($this->gender !== '', fn (Builder $q) => $q->where('gender', $this->gender))
            ->when($this->city !== '', fn (Builder $q) => $q->where('city_id', $this->city))
            ->when($this->premium !== '', fn (Builder $q) => $q->where('is_premium', $this->premium === 'yes'))
            ->when($this->source !== '', fn (Builder $q) => $q->where('signup_source', $this->source))
            ->when($this->photos === 'none', fn (Builder $q) => $q->whereDoesntHave('photos'))
            ->when($this->photos === 'some', fn (Builder $q) => $q->whereHas('photos'))
            ->when($this->reported === 'yes', fn (Builder $q) => $q->whereHas('reportsAgainst'))
            ->when($this->reported === 'open', fn (Builder $q) => $q->whereHas(
                'cases',
                fn (Builder $c) => $c->whereIn('status', ['new', 'claimed', 'in_review']),
            ))
            ->when($this->joined !== '', fn (Builder $q) => $q->where(
                'created_at',
                '>=',
                now()->subDays((int) $this->joined),
            ))
            ->when($this->lastActive !== '', fn (Builder $q) => $q->where(
                'last_active_at',
                '>=',
                now()->subDays((int) $this->lastActive),
            ));
    }

    protected function pageIds(): array
    {
        return $this->applySort($this->baseQuery())
            ->paginate($this->perPage)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    // Any filter change invalidates both the page and the selection: keeping a
    // selection across a filter change is how the wrong accounts get actioned.
    public function updated(string $property): void
    {
        if (in_array($property, $this->filterProperties(), true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    public function resetFilters(): void
    {
        $this->reset($this->filterProperties());
        $this->search = '';
        $this->resetPage();
        $this->clearSelection();
    }

    /** @return array<int, string> */
    private function filterProperties(): array
    {
        return [
            'status', 'verification', 'risk', 'gender', 'city',
            'premium', 'source', 'photos', 'reported', 'joined', 'lastActive',
        ];
    }

    /** Active filters, for the chip row and the "reset" affordance. */
    public function activeFilters(): array
    {
        $labels = [
            'status' => AccountStatus::labels(),
            'verification' => VerificationStatus::labels(),
            'risk' => RiskBand::labels(),
            'gender' => Gender::labels(),
            'premium' => ['yes' => 'Premium', 'no' => 'Free'],
            'source' => ['ios' => 'iOS', 'android' => 'Android', 'web' => 'Web'],
            'photos' => ['none' => 'No photos', 'some' => 'Has photos'],
            'reported' => ['yes' => 'Ever reported', 'open' => 'Open case'],
            'joined' => ['1' => 'Joined today', '7' => 'Joined this week', '30' => 'Joined this month'],
            'lastActive' => ['1' => 'Active today', '7' => 'Active this week', '30' => 'Active this month'],
        ];

        $active = [];

        foreach ($this->filterProperties() as $property) {
            $value = $this->{$property};

            if ($value === '') {
                continue;
            }

            $active[$property] = $property === 'city'
                ? (City::query()->find($value)?->name ?? 'City')
                : ($labels[$property][$value] ?? $value);
        }

        return $active;
    }

    public function clearFilter(string $property): void
    {
        if (in_array($property, $this->filterProperties(), true)) {
            $this->{$property} = '';
            $this->resetPage();
            $this->clearSelection();
        }
    }

    // ---- actions --------------------------------------------------------------

    /** Set when the enforcement dialog was opened from one row's menu. */
    #[Locked]
    public ?int $rowTarget = null;

    public function openRowStep(string $step, int $appUserId): void
    {
        $this->openStep($step);
        $this->rowTarget = $appUserId;
    }

    public function openBulkStep(string $step): void
    {
        $this->openStep($step);
        $this->rowTarget = null;
    }

    public function confirmStep(): void
    {
        $limit = (int) config('veyra.tables.bulk_inline_limit', 500);

        $subjects = $this->rowTarget !== null
            ? AppUser::query()->whereKey($this->rowTarget)->get()
            : $this->bulkQuery()->limit($limit + 1)->get();

        if ($subjects->count() > $limit) {
            $this->addError('reasonCode', "That is more than {$limit} members. Narrow the filters and try again.");

            return;
        }

        $step = LadderStep::tryFrom($this->pendingStep);
        $count = $this->applyStepTo($subjects);

        if ($count > 0) {
            $this->clearSelection();
            $this->rowTarget = null;
            session()->flash('status', "{$step?->label()} applied to {$count} ".str('member')->plural($count).'.');
        }
    }

    /**
     * CSV of the selection, or of everything matching the filters when nothing
     * is selected. Email and phone are included only for staff allowed to see
     * personal data, and every export is recorded as sensitive in the audit log.
     */
    public function export(ActivityLogger $logger): StreamedResponse
    {
        $this->authorize('export_users');

        $query = $this->hasSelection() ? $this->bulkQuery() : $this->baseQuery();
        $withPii = auth()->user()->can('view_user_pii');
        $count = (clone $query)->count();

        $logger->log(
            module: 'users',
            action: 'exported',
            description: "Exported {$count} ".str('member')->plural($count).($withPii ? ' including contact details' : ''),
            new: ['count' => $count, 'filters' => $this->activeFilters(), 'personal_data' => $withPii],
            sensitive: true,
        );

        $filename = str(Branding::name())->slug().'-members-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $withPii): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_filter([
                'ID', 'Name', 'Age', 'Gender', 'City', 'Status', 'Verification', 'Risk band', 'Premium', 'Joined', 'Last active',
                $withPii ? 'Email' : null, $withPii ? 'Phone' : null,
            ]));

            $query->with('city')->orderBy('id')->chunk(500, function ($members) use ($out, $withPii): void {
                foreach ($members as $m) {
                    fputcsv($out, array_values(array_filter([
                        'id' => $m->uuid,
                        'name' => $m->display_name,
                        'age' => $m->age,
                        'gender' => $m->gender?->label(),
                        'city' => $m->city?->name,
                        'status' => $m->account_status?->label(),
                        'verification' => $m->verification_status?->label(),
                        'risk' => $m->risk_band?->label(),
                        'premium' => $m->is_premium ? (Masters::plan($m->premium_tier)?->name ?? ucfirst((string) ($m->premium_tier ?? 'yes'))) : 'No',
                        'joined' => $m->created_at?->toDateString(),
                        'active' => $m->last_active_at?->toDateTimeString(),
                        'email' => $withPii ? $m->email : null,
                        'phone' => $withPii ? ($m->phone ?? '') : null,
                    ], fn ($v, $k) => ! (in_array($k, ['email', 'phone'], true) && ! $withPii), ARRAY_FILTER_USE_BOTH)));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
