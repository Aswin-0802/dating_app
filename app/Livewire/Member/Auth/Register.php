<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Enums\Gender;
use App\Models\City;
use App\Models\Country;
use App\Services\Members\MemberAccounts;
use App\Support\ProfileOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Two short steps rather than one long form: the account first, then the
 * handful of things discovery needs. Everything else is filled in afterwards
 * from the profile page, where the completion checklist explains why.
 */
class Register extends Component
{
    public int $step = 1;

    public string $display_name = '';

    public string $email = '';

    public string $password = '';

    public string $birthdate = '';

    public string $gender = '';

    /** @var array<int, string> */
    public array $interested_in = [];

    public ?int $city_id = null;

    public bool $terms = false;

    public function render(): View
    {
        return view('livewire.member.auth.register', [
            'genders' => collect(Gender::cases())->mapWithKeys(fn (Gender $g): array => [$g->value => $g->label()])->all(),
            'minAge' => $this->minAge(),
        ])->layout('components.layouts.member-auth', ['title' => 'Join']);
    }

    /** @return array<string, array<int, string>> "country · state" => [city id => name] */
    #[Computed(persist: true)]
    public function cities(): array
    {
        /*
         * Grouped "Country · State" rather than a second dropdown: a cascade
         * means an extra tap and a chance to get stuck, while the optgroup
         * puts the same information in front of the member for free. Cities in
         * a hidden state drop out of the list.
         */
        return City::query()
            ->whereHas('country', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('state_id')->orWhereHas('state', fn ($s) => $s->where('is_active', true)))
            ->with(['country:id,name', 'state:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'country_id', 'state_id'])
            ->groupBy(fn (City $city): string => ($city->country?->name ?? 'Other')
                .($city->state === null ? '' : ' · '.$city->state->name))
            ->sortKeys()
            ->map(fn ($group) => $group->pluck('name', 'id')->all())
            ->all();
    }

    public function next(): void
    {
        $this->validate($this->accountRules(), $this->messages());
        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    public function register(MemberAccounts $accounts): void
    {
        $this->validate($this->accountRules() + [
            'gender' => ['required', Rule::enum(Gender::class)],
            'interested_in' => ['required', 'array', 'min:1'],
            'interested_in.*' => [Rule::enum(Gender::class)],
            'city_id' => ['required', Rule::exists('cities', 'id')->whereIn('country_id', Country::query()->where('is_active', true)->pluck('id')->all())],
            'terms' => ['accepted'],
        ], $this->messages());

        $member = $accounts->register([
            'display_name' => trim($this->display_name),
            'email' => $this->email,
            'password' => $this->password,
            'birthdate' => $this->birthdate,
            'gender' => $this->gender,
            'interested_in' => array_values($this->interested_in),
            'city_id' => $this->city_id,
        ], 'web');

        Auth::guard('member')->login($member, remember: true);
        session()->regenerate();
        $accounts->recordLogin($member, request()->ip(), succeeded: true);

        session()->flash('status', 'Welcome! Add a photo and a few details so people can find you.');
        $this->redirectRoute('member.profile');
    }

    /** @return array<string, mixed> */
    private function accountRules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:60', ProfileOptions::NAME_RULE],
            'email' => ['required', 'email', 'max:255', 'unique:app_users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
            // Enforced here as well as on the API: an under-age account is a
            // safety incident, not a validation warning.
            'birthdate' => ['required', 'date', 'before_or_equal:'.now()->subYears($this->minAge())->toDateString(), 'after:'.now()->subYears(100)->toDateString()],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'birthdate.before_or_equal' => "You need to be {$this->minAge()} or older to join.",
            'email.unique' => 'There is already an account with that email. Try signing in.',
            'display_name.regex' => ProfileOptions::NAME_MESSAGE,
            'interested_in.required' => 'Choose at least one.',
            'city_id.required' => 'Choose the city you are in.',
            'terms.accepted' => 'Please accept the terms to continue.',
        ];
    }

    private function minAge(): int
    {
        return max(18, (int) veyra_setting('general.min_age', 18));
    }
}
