@php
    use App\Support\Branding;

    $support = Branding::get('brand.support_email');
@endphp

<x-layouts.member :title="'Your payment'">
    <div class="mx-auto max-w-lg space-y-6">
        @if ($order->isPaid())
            <div class="rounded-3xl bg-gradient-to-br from-primary to-accent p-8 text-center text-primary-foreground">
                <x-ui.icon name="check-circle" size="xl" class="mx-auto" />
                <h1 class="mt-3 text-2xl font-bold tracking-tight">You are on {{ $order->description }}</h1>
                <p class="mt-1 text-primary-foreground/85">
                    Paid {{ $order->formattedAmount() }}@if ($me->premium_until) · runs until {{ veyra_date($me->premium_until) }} @endif
                </p>
            </div>

            <x-ui.card>
                <p class="text-sm">Everything your plan unlocks is available straight away.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-ui.button :href="route('member.discover')" wire:navigate>Start swiping</x-ui.button>
                    <x-ui.button variant="outline" :href="route('member.premium')" wire:navigate>See your plan</x-ui.button>
                </div>
            </x-ui.card>
        @elseif ($order->status === 'pending')
            <x-ui.card title="Waiting for your bank">
                <p class="text-sm text-muted-foreground">
                    {{ $order->description }} · {{ $order->formattedAmount() }}
                </p>
                <p class="mt-3 text-sm">
                    We have not had confirmation of this payment yet. If money has left your account it usually
                    arrives within a minute or two.
                </p>

                <form method="POST" action="{{ route('member.checkout.refresh', $order) }}" class="mt-4 flex flex-wrap gap-2">
                    @csrf
                    <x-ui.button type="submit" icon="arrow-path">Check again</x-ui.button>
                    <x-ui.button variant="ghost" :href="route('member.premium')" wire:navigate>Back to plans</x-ui.button>
                </form>
            </x-ui.card>
        @else
            <x-ui.card title="That payment did not go through">
                <p class="text-sm text-muted-foreground">
                    {{ $order->description }} · {{ $order->formattedAmount() }}
                </p>
                <p class="mt-3 text-sm">
                    Nothing has been taken from your account. You can try again, or use a different card.
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-ui.button :href="route('member.premium')" wire:navigate>Try again</x-ui.button>
                    @if ($support)
                        <x-ui.button variant="ghost" :href="'mailto:'.$support">Contact support</x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        @endif

        <p class="text-center text-xs text-muted-foreground">
            Reference {{ $order->uuid }}
        </p>
    </div>
</x-layouts.member>
