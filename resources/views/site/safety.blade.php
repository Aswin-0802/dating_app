@php
    use App\Support\Branding;

    $name = Branding::name();
    $support = Branding::get('brand.support_email');
@endphp

<x-layouts.site title="Safety centre">
    <section class="border-b border-border bg-muted/30">
        <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6">
            <p class="text-sm font-semibold text-primary">Safety centre</p>
            <h1 class="mt-2 text-4xl font-bold tracking-tight sm:text-5xl">Date safely, on and off {{ $name }}</h1>
            <p class="mt-4 max-w-2xl text-lg text-muted-foreground">
                The tools we build, and the habits that keep the first few dates relaxed.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl space-y-16 px-4 py-16 sm:px-6">
        <section>
            <h2 class="text-2xl font-bold tracking-tight">What we do</h2>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                @foreach ([
                    ['check-badge', 'Photo verification', 'A person compares your selfie with your photos. Look for the badge on profiles, or switch on verified-only in your preferences.'],
                    ['flag', 'Reports reviewed by people', 'Every report reaches a trained moderator. The most serious ones are prioritised above everything else.'],
                    ['scale', 'Explained decisions', 'If we restrict an account we say which rule was broken. Anyone can appeal, and a different person reviews the appeal.'],
                    ['lock', 'Private messages', 'Staff only open a conversation while investigating a report, must record why, and every access is logged.'],
                ] as [$icon, $title, $body])
                    <div class="rounded-2xl border border-border bg-card p-5">
                        <x-ui.icon :name="$icon" size="lg" class="text-primary" />
                        <h3 class="mt-3 font-semibold">{{ $title }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section>
            <h2 class="text-2xl font-bold tracking-tight">Before you meet</h2>
            <ul class="mt-6 space-y-4">
                @foreach ([
                    ['Keep the chat here at first', 'Someone pushing to move to another app straight away is the most common sign of a scam. Take your time.'],
                    ['Never send money', 'Not for a ticket, an emergency, or an investment. No genuine match will ask. Report anyone who does.'],
                    ['Video call first', 'A quick call confirms they are who their photos say, and whether you actually get on.'],
                    ['Meet somewhere public', 'Choose a busy café or bar, get yourself there and back, and tell a friend where you will be.'],
                    ['Trust your instincts', 'You can leave any date, at any point, for any reason. You do not owe anyone an explanation.'],
                ] as $i => [$title, $body])
                    <li class="flex gap-4">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-subtle text-sm font-semibold text-primary-subtle-foreground">{{ $i + 1 }}</span>
                        <div>
                            <p class="font-semibold">{{ $title }}</p>
                            <p class="mt-0.5 text-sm text-muted-foreground">{{ $body }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-2xl border border-destructive/30 bg-destructive-subtle p-6">
            <h2 class="flex items-center gap-2 text-lg font-semibold text-destructive-subtle-foreground">
                <x-ui.icon name="warning" size="md" /> In immediate danger?
            </h2>
            <p class="mt-2 text-sm text-destructive-subtle-foreground">
                Call your local emergency number first. Then report the account in the app so we can act on it too.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-bold tracking-tight">Reporting someone</h2>
            <p class="mt-3 text-muted-foreground">
                Open their profile or your conversation, tap <strong class="text-foreground">Report</strong>, pick what happened and add anything that helps.
                They are never told who reported them. You can block them at the same time.
                @if ($support)
                    For anything else, email <a href="mailto:{{ $support }}" class="font-medium text-primary hover:underline">{{ $support }}</a>.
                @endif
            </p>
        </section>
    </div>
</x-layouts.site>
