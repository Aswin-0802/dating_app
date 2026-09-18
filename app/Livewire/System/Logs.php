<?php

declare(strict_types=1);

namespace App\Livewire\System;

use App\Livewire\Concerns\WithDataTable;
use App\Models\AppUserLogin;
use App\Models\EmailLog;
use App\Models\PaymentLog;
use App\Models\SmsLog;
use App\Support\Branding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Delivery and transaction logs for the platform integrations.
 *
 * One component for four log types: they differ in columns, not behaviour, and
 * four near-identical classes would drift apart the first time somebody fixed a
 * bug in only one of them.
 */
class Logs extends Component
{
    use WithDataTable;

    /** email | sms | payment | login */
    public string $kind = 'email';

    #[Url(except: '')]
    public string $status = '';

    private const KINDS = [
        'email' => ['label' => 'Email log', 'model' => EmailLog::class],
        'sms' => ['label' => 'SMS log', 'model' => SmsLog::class],
        'payment' => ['label' => 'Payment log', 'model' => PaymentLog::class],
        'login' => ['label' => 'Member sign-ins', 'model' => AppUserLogin::class],
    ];

    public function mount(string $kind = 'email'): void
    {
        $this->kind = array_key_exists($kind, self::KINDS) ? $kind : 'email';
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        return view('livewire.system.logs', [
            'rows' => $this->rows(),
            'kinds' => self::KINDS,
            'summary' => $this->summary(),
        ])->layout('components.layouts.admin', [
            'title' => self::KINDS[$this->kind]['label'],
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'System'],
                ['label' => self::KINDS[$this->kind]['label']],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['created_at'];
    }

    protected function baseQuery(): Builder
    {
        $model = self::KINDS[$this->kind]['model'];

        return $model::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where(
                $this->kind === 'login' ? 'succeeded' : 'status',
                $this->kind === 'login' ? $this->status === 'succeeded' : $this->status,
            ))
            ->when($this->search !== '', fn (Builder $q) => $this->applySearch($q));
    }

    private function applySearch(Builder $query): Builder
    {
        return match ($this->kind) {
            'email' => $query->where(fn (Builder $q) => $q
                ->where('to', 'like', "%{$this->search}%")
                ->orWhere('subject', 'like', "%{$this->search}%")),
            'sms' => $query->where('to', 'like', "%{$this->search}%"),
            'payment' => $query->where(fn (Builder $q) => $q
                ->where('gateway_reference', 'like', "%{$this->search}%")
                ->orWhereHas('appUser', fn (Builder $u) => $u->search($this->search))),
            default => $query->whereHas('appUser', fn (Builder $u) => $u->search($this->search)),
        };
    }

    private function rows(): LengthAwarePaginator
    {
        $query = $this->applySort($this->baseQuery());

        if (in_array($this->kind, ['payment', 'login'], true)) {
            $query->with('appUser');
        }

        return $query->paginate($this->perPage);
    }

    /**
     * Headline numbers per log type.
     *
     * A failure rate is the only thing worth glancing at here; the rows are for
     * when something specific has gone wrong.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return match ($this->kind) {
            'email' => [
                'Total' => DB::table('email_logs')->count(),
                'Delivered' => DB::table('email_logs')->where('status', 'delivered')->count(),
                'Bounced' => DB::table('email_logs')->where('status', 'bounced')->count(),
                'Failed' => DB::table('email_logs')->where('status', 'failed')->count(),
            ],
            'sms' => [
                'Total' => DB::table('sms_logs')->count(),
                'Delivered' => DB::table('sms_logs')->where('status', 'delivered')->count(),
                'Failed' => DB::table('sms_logs')->where('status', 'failed')->count(),
                'Segments' => (int) DB::table('sms_logs')->sum('segments'),
            ],
            'payment' => [
                'Succeeded' => DB::table('payment_logs')->where('status', 'succeeded')->count(),
                'Failed' => DB::table('payment_logs')->where('status', 'failed')->count(),
                'Refunded' => DB::table('payment_logs')->where('status', 'refunded')->count(),
                // Surfaced prominently: repeated disputes are a fraud signal,
                // not just a finance one.
                'Disputed' => DB::table('payment_logs')->where('status', 'disputed')->count(),
            ],
            default => [
                'Sign-ins (30d)' => DB::table('app_user_logins')
                    ->where('created_at', '>=', now()->subDays(30))->count(),
                'Failed (30d)' => DB::table('app_user_logins')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->where('succeeded', false)->count(),
            ],
        };
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return match ($this->kind) {
            'email' => ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'bounced' => 'Bounced', 'failed' => 'Failed'],
            'sms' => ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed'],
            'payment' => ['pending' => 'Pending', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'refunded' => 'Refunded', 'disputed' => 'Disputed'],
            default => ['succeeded' => 'Successful', 'failed' => 'Failed'],
        };
    }
}
