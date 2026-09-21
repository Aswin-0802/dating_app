<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Models\Order;
use App\Services\Audit\ActivityLogger;
use App\Services\Payments\Checkout;
use App\Support\Branding;
use App\Support\Currency;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every attempt to pay, successful or not.
 *
 * The failures matter as much as the takings: a run of them in one gateway is
 * how you find out a key expired, and without this screen the only evidence a
 * payment ever happened lives in the gateway's own dashboard.
 */
class Payments extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $gateway = '';

    #[Url(except: '30')]
    public string $period = '30';

    public function render(): View
    {
        $orders = $this->query()
            ->with(['appUser:id,uuid,display_name,email'])
            ->latest('created_at')
            ->paginate(25);

        return view('livewire.billing.payments', [
            'orders' => $orders,
            'totals' => $this->totals(),
            'gateways' => Order::query()->distinct()->orderBy('gateway')->pluck('gateway')->filter()->values(),
            'canExport' => auth()->user()?->can('export_payments') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Payments',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Billing'],
                ['label' => 'Payments'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function totals(): array
    {
        $paid = (clone $this->query())->where('status', 'paid');

        return [
            'count' => (clone $paid)->count(),
            // Grouped by currency: adding rupees to dollars would be a lie,
            // and an operator who changes currency mid-year has both.
            'amounts' => (clone $paid)
                ->select('currency', DB::raw('SUM(amount_minor) as minor'))
                ->groupBy('currency')
                ->pluck('minor', 'currency'),
            'failed' => (clone $this->query())->whereIn('status', ['failed', 'expired'])->count(),
            'pending' => (clone $this->query())->where('status', 'pending')->count(),
        ];
    }

    private function query(): Builder
    {
        return Order::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->gateway !== '', fn (Builder $q) => $q->where('gateway', $this->gateway))
            ->when($this->period !== '', fn (Builder $q) => $q->where('created_at', '>=', now()->subDays((int) $this->period)))
            ->when($this->search !== '', function (Builder $q): void {
                $term = trim($this->search);

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('uuid', $term)
                        ->orWhere('gateway_ref', $term)
                        ->orWhere('payment_ref', $term)
                        ->orWhereHas('appUser', fn (Builder $u) => $u
                            ->where('display_name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%"));
                });
            });
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'gateway');
        $this->period = '30';
        $this->resetPage();
    }

    /** Re-ask the gateway about a payment that never confirmed. */
    public function recheck(int $orderId, Checkout $checkout): void
    {
        $this->authorize('payments');

        $order = Order::query()->findOrFail($orderId);
        $before = $order->status;
        $order = $checkout->reconcile($order);

        session()->flash(
            $order->status === 'paid' ? 'status' : 'error',
            $order->status === 'paid'
                ? 'The gateway confirms this payment. The plan has been applied.'
                : ($before === $order->status
                    ? 'The gateway still has nothing for this payment.'
                    : "The gateway now reports it as {$order->status}."),
        );
    }

    public function export(ActivityLogger $logger): StreamedResponse
    {
        $this->authorize('export_payments');

        $query = $this->query()->with('appUser:id,display_name,email');
        $filename = str(Branding::name())->slug().'-payments-'.now()->format('Y-m-d-His').'.csv';

        $logger->log(
            module: 'billing',
            action: 'payments_exported',
            description: 'Exported the payments list',
            new: ['status' => $this->status, 'gateway' => $this->gateway, 'days' => $this->period],
            sensitive: true,
        );

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Date', 'Member', 'Email', 'Item', 'Amount', 'Currency', 'Gateway', 'Status', 'Gateway reference']);

            $query->orderBy('id')->chunk(500, function ($orders) use ($out): void {
                foreach ($orders as $order) {
                    fputcsv($out, [
                        $order->uuid,
                        $order->created_at?->toDateTimeString(),
                        $order->appUser?->display_name,
                        $order->appUser?->email,
                        $order->description,
                        number_format($order->amount(), 2, '.', ''),
                        $order->currency,
                        $order->gateway,
                        $order->status,
                        $order->payment_ref ?? $order->gateway_ref,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function formatMinor(int $minor, string $currency): string
    {
        return Currency::format(Currency::fromMinor($minor, $currency), $currency);
    }
}
