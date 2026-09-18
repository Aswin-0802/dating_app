@php
    use App\Support\Branding;

    $name = Branding::name();
    $company = Branding::get('business.name', $name);
    $email = Branding::get('business.email', Branding::get('brand.support_email'));
    $address = Branding::get('business.address');
    $minAge = (int) veyra_setting('general.min_age', 18);
    $isTerms = $page === 'terms';
@endphp

<x-layouts.site :title="$isTerms ? 'Terms of use' : 'Privacy policy'">
    <article class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <p class="text-sm font-semibold text-primary">Legal</p>
        <h1 class="mt-2 text-4xl font-bold tracking-tight">{{ $isTerms ? 'Terms of use' : 'Privacy policy' }}</h1>

        {{-- A starting point, not legal advice. Shown only to signed-in staff so
             the operator sees the reminder and members never do. --}}
        @auth
            <div class="mt-6 flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-subtle p-4 text-sm text-warning-subtle-foreground">
                <x-ui.icon name="warning" size="sm" class="mt-0.5 shrink-0" />
                <p>Template text. Have it reviewed for your jurisdiction and replace it in <code class="font-mono">resources/views/site/legal.blade.php</code> before launch. Only staff see this notice.</p>
            </div>
        @endauth

        <div class="mt-10 space-y-8 text-[15px] leading-relaxed text-muted-foreground [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground">
            @if ($isTerms)
                <section>
                    <h2>1. Who we are</h2>
                    <p>{{ $name }} is operated by {{ $company }}. By creating an account you agree to these terms.</p>
                </section>
                <section>
                    <h2>2. Eligibility</h2>
                    <p>You must be at least {{ $minAge }} years old. Accounts that appear to belong to someone younger are removed.</p>
                </section>
                <section>
                    <h2>3. Your account</h2>
                    <p>Use your own photos and accurate details, keep your password to yourself, and have one account. You are responsible for what you post and send.</p>
                </section>
                <section>
                    <h2>4. Community rules</h2>
                    <p>No harassment, hate, threats, nudity without consent, scams, requests for money, impersonation, spam or commercial promotion. Breaking these rules can lead to warnings, feature limits, suspension or a permanent ban.</p>
                </section>
                <section>
                    <h2>5. Moderation and appeals</h2>
                    <p>If we restrict your account we tell you what we did, which rule it relates to and for how long. You can appeal, and a different person from the one who made the decision reviews it.</p>
                </section>
                <section>
                    <h2>6. Premium</h2>
                    <p>Paid plans renew monthly until cancelled. Features included in each plan are described on our pricing page.</p>
                </section>
                <section>
                    <h2>7. Ending your account</h2>
                    <p>You can deactivate your account at any time from Account settings. We may close accounts that break these terms.</p>
                </section>
            @else
                <section>
                    <h2>1. Who is responsible</h2>
                    <p>{{ $company }} is responsible for your personal data on {{ $name }}.</p>
                </section>
                <section>
                    <h2>2. What we collect</h2>
                    <p>Your account details (name, email, date of birth, gender), your profile (photos, bio, interests, preferences and city), your activity (likes, matches and messages), device and sign-in information, and verification selfies if you choose to verify.</p>
                </section>
                <section>
                    <h2>3. Why we use it</h2>
                    <p>To run the service and show you to people you might like, to keep members safe (verification, detecting fake accounts and scams, investigating reports), and to meet our legal obligations.</p>
                </section>
                <section>
                    <h2>4. Verification selfies</h2>
                    <p>Stored separately from your profile, visible only to our review team, never shown to other members. Every time a reviewer opens one is recorded.</p>
                </section>
                <section>
                    <h2>5. Your messages</h2>
                    <p>Only you and your match can read your conversation. A moderator can open it only while investigating a report, must record a reason, and each access is logged.</p>
                </section>
                <section>
                    <h2>6. Your rights</h2>
                    <p>You can access, correct, export or delete your data. Contact us using the details below.</p>
                </section>
            @endif

            <section>
                <h2>Contact</h2>
                <p>
                    {{ $company }}
                    @if ($address)<br><span class="whitespace-pre-line">{{ $address }}</span>@endif
                    @if ($email)<br><a href="mailto:{{ $email }}" class="font-medium text-primary hover:underline">{{ $email }}</a>@endif
                </p>
            </section>
        </div>
    </article>
</x-layouts.site>
