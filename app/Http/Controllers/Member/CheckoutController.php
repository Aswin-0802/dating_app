<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Services\Payments\Checkout;
use App\Services\Payments\PaymentFailed;
use App\Support\Masters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Buying a plan from the member app.
 *
 * Three steps, and the middle one happens on the gateway's own site: start,
 * come back, and a status page for when the answer is not instant.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly Checkout $checkout) {}

    /** Send the member off to pay. */
    public function start(Request $request): RedirectResponse
    {
        $member = $request->user('member');
        $gateways = $this->checkout->availableGateways();

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(Masters::plans()->pluck('slug')->all())],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'gateway' => ['nullable', 'string', Rule::in($gateways->pluck('slug')->all())],
        ], [
            'plan.in' => 'That plan is no longer on sale.',
            'gateway.in' => 'That payment method is not available.',
        ]);

        if ($gateways->isEmpty()) {
            return back()->with('error', 'Online payment is not set up yet. Please contact support to upgrade.');
        }

        // One gateway on: no need to ask. Two or more: the member chose.
        $gateway = $data['gateway'] ?? null;
        $chosen = $gateway === null
            ? $gateways->first()
            : $gateways->firstWhere('slug', $gateway);

        $plan = Masters::plan($data['plan']);

        if ($plan === null || ($data['period'] === 'yearly' && $plan->yearly_price === null)) {
            return back()->with('error', 'That plan is not available right now.');
        }

        try {
            $started = $this->checkout->start(
                member: $member,
                plan: $plan,
                period: $data['period'],
                gateway: $chosen,
                returnUrl: route('member.checkout.return', ['order' => '__ORDER__']),
                cancelUrl: route('member.checkout.show', ['order' => '__ORDER__']),
            );
        } catch (PaymentFailed $e) {
            report($e);

            return back()->with('error', $e->memberMessage);
        }

        return redirect()->away($started['redirect_url']);
    }

    /**
     * Where the gateway sends the member back to.
     *
     * The browser's word is never enough, so the gateway is asked directly
     * before anything is unlocked.
     */
    public function return(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->app_user_id === $request->user('member')->id, 403);

        $gateway = $this->checkout->gatewayFor($order);
        $driver = $this->checkout->driverFor($gateway);

        if ($driver !== null) {
            $driver->readReturn($request, $order);
        }

        $order = $this->checkout->reconcile($order);

        return redirect()->route('member.checkout.show', $order);
    }

    /** The status page: paid, still waiting, or failed. */
    public function show(Request $request, Order $order): View
    {
        abort_unless($order->app_user_id === $request->user('member')->id, 403);

        return view('member.checkout', [
            'order' => $order,
            'me' => $request->user('member'),
        ])->with('title', 'Your payment');
    }

    /** "Check again" on the status page, for when a webhook has not arrived. */
    public function refresh(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->app_user_id === $request->user('member')->id, 403);

        $order = $this->checkout->reconcile($order);

        return redirect()->route('member.checkout.show', $order)->with(
            $order->isPaid() ? 'status' : 'error',
            $order->isPaid()
                ? 'Payment confirmed.'
                : 'The payment has not been confirmed yet. If money has left your account, it can take a minute — try again shortly.',
        );
    }

    /** Which gateways a member may choose between. */
    public static function gatewayOptions(Checkout $checkout): array
    {
        return $checkout->availableGateways()
            ->map(fn (PaymentGateway $gateway): array => [
                'slug' => $gateway->slug,
                'name' => $gateway->name,
                'test' => (bool) $gateway->is_test_mode,
            ])
            ->all();
    }
}
