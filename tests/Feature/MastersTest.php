<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReasonCode;
use App\Enums\ReportCategory;
use App\Enums\Severity;
use App\Livewire\Billing\Plans;
use App\Livewire\Masters\Interests;
use App\Livewire\Masters\ProfileQuestions;
use App\Livewire\Masters\Reasons;
use App\Livewire\Masters\ReportCategories;
use App\Livewire\Notifications\Templates;
use App\Models\AppUser;
use App\Models\Interest;
use App\Models\NotificationTemplate;
use App\Models\Plan;
use App\Models\ProfileOption;
use App\Models\Role;
use App\Models\User;
use App\Services\Members\SwipeRecorder;
use App\Support\ProfileOptions;
use Database\Seeders\InterestSeeder;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class MastersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class, InterestSeeder::class, MasterSeeder::class]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['status' => 'active'])->syncRoles([$role]);
    }

    // ---- plans ----------------------------------------------------------------

    public function test_plan_features_decide_what_a_member_gets(): void
    {
        $swipes = app(SwipeRecorder::class);
        $member = AppUser::factory()->create(['is_premium' => true, 'premium_tier' => 'plus']);

        $this->assertTrue($member->hasPremiumFeature('see_likers'));
        $this->assertNull($swipes->likesLeftToday($member));

        // Take unlimited likes off Plus: the member is back on the daily budget.
        Plan::query()->where('slug', 'plus')->firstOrFail()->update(['features' => ['see_likers']]);

        $this->assertFalse($member->fresh()->hasPremiumFeature('unlimited_likes'));
        $this->assertIsInt($swipes->likesLeftToday($member->fresh()));

        // A lapsed subscription unlocks nothing.
        $member->forceFill(['premium_until' => now()->subDay()])->save();
        $this->assertNull($member->fresh()->activePlan());
    }

    public function test_admin_creates_a_plan_and_the_website_lists_it(): void
    {
        Livewire::actingAs($this->staff(Role::ADMIN))
            ->test(Plans::class)
            ->call('create')
            ->set('name', 'Platinum')
            ->assertSet('slug', 'platinum')
            ->set('monthlyPrice', '39.99')
            ->set('yearlyPrice', '299')
            ->set('features', ['unlimited_likes', 'profile_badge'])
            ->set('perks', "Everything in Gold\nMonthly profile review")
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::query()->where('slug', 'platinum')->firstOrFail();
        $this->assertSame(['unlimited_likes', 'profile_badge'], $plan->features);
        $this->assertSame(38, $plan->yearlySavingPercent());

        $this->get('/')->assertOk()->assertSee('Platinum')->assertSee('Monthly profile review');

        Livewire::actingAs($this->staff(Role::ADMIN))->test(Plans::class)->call('toggleActive', $plan->id);
        $this->get('/')->assertDontSee('Platinum');
    }

    public function test_plan_validation_and_delete_guard(): void
    {
        $admin = $this->staff(Role::ADMIN);

        Livewire::actingAs($admin)->test(Plans::class)
            ->call('create')
            ->set('name', 'Plus again')->set('slug', 'plus')->set('monthlyPrice', '-1')->set('badgeColor', 'red')
            ->call('save')
            ->assertHasErrors(['slug' => 'unique', 'monthlyPrice' => 'min', 'badgeColor' => 'regex']);

        AppUser::factory()->create(['is_premium' => true, 'premium_tier' => 'gold']);
        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();

        Livewire::actingAs($admin)->test(Plans::class)->call('delete', $gold->id);
        $this->assertModelExists($gold);
    }

    public function test_only_staff_who_edit_general_settings_can_change_plans(): void
    {
        // T&S Lead can open Masters but owns safety policy, not pricing.
        Livewire::actingAs($this->staff(Role::TS_LEAD))
            ->test(Plans::class)
            ->call('create')
            ->assertForbidden();

        $this->actingAs($this->staff(Role::MODERATOR))->get(route('admin.billing.plans'))->assertForbidden();
    }

    // ---- interests --------------------------------------------------------------

    public function test_a_hidden_interest_leaves_the_picker_and_the_api(): void
    {
        $interest = Interest::query()->where('slug', 'chess')->firstOrFail();

        Livewire::actingAs($this->staff(Role::ADMIN))->test(Interests::class)->call('toggleActive', $interest->id);

        Sanctum::actingAs(AppUser::factory()->create(), ['*']);
        $this->getJson(route('api.v1.interests'))->assertOk()->assertJsonMissing(['slug' => 'chess']);
        $this->putJson(route('api.v1.me.interests'), ['slugs' => ['chess']])->assertUnprocessable();
    }

    public function test_an_interest_in_use_cannot_be_deleted(): void
    {
        $interest = Interest::query()->where('slug', 'coffee')->firstOrFail();
        AppUser::factory()->create()->interests()->attach($interest);

        Livewire::actingAs($this->staff(Role::ADMIN))->test(Interests::class)->call('delete', $interest->id);
        $this->assertModelExists($interest);

        Livewire::actingAs($this->staff(Role::ADMIN))->test(Interests::class)
            ->call('create')->set('name', 'Coffee')->set('interestCategory', 'Food & Drink')->call('save')
            ->assertHasErrors(['name' => 'unique']);
    }

    // ---- report categories and reasons -----------------------------------------

    public function test_report_categories_can_be_reworded_rerated_and_hidden(): void
    {
        $lead = $this->staff(Role::TS_LEAD);

        Livewire::actingAs($lead)->test(ReportCategories::class)
            ->call('edit', 'spam_promotion')
            ->set('label', 'Spam or selling something')
            ->set('severity', 'medium')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Spam or selling something', ReportCategory::SpamPromotion->label());
        $this->assertSame(Severity::Medium, ReportCategory::SpamPromotion->defaultSeverity());

        Livewire::actingAs($lead)->test(ReportCategories::class)->call('toggleActive', 'spam_promotion');
        $this->assertNotContains(ReportCategory::SpamPromotion, ReportCategory::selectable());

        $reporter = AppUser::factory()->create();
        Sanctum::actingAs($reporter, ['*']);
        $this->postJson(route('api.v1.reports.store'), [
            'reported_id' => AppUser::factory()->create()->uuid,
            'category' => 'spam_promotion',
        ])->assertUnprocessable();
    }

    public function test_safety_categories_stay_on_and_critical(): void
    {
        Livewire::actingAs($this->staff(Role::TS_LEAD))->test(ReportCategories::class)
            ->call('toggleActive', 'underage_suspected')
            ->call('edit', 'underage_suspected')
            ->set('severity', 'low')
            ->set('isActive', false)
            ->call('save');

        $this->assertTrue(ReportCategory::UnderageSuspected->isActive());
        $this->assertSame(Severity::Critical, ReportCategory::UnderageSuspected->defaultSeverity());
    }

    public function test_reason_wording_feeds_the_statement_and_system_reasons_stay_on(): void
    {
        $lead = $this->staff(Role::TS_LEAD);

        Livewire::actingAs($lead)->test(Reasons::class)
            ->call('edit', 'romance_scam')
            ->set('statement', 'Your account was restricted because it was used to deceive other members for money.')
            ->set('policyClause', '5.2 Scams')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('5.2 Scams', ReasonCode::RomanceScam->policyClause());
        $this->assertStringContainsString('deceive other members', ReasonCode::RomanceScam->statement());

        Livewire::actingAs($lead)->test(Reasons::class)
            ->call('toggleActive', 'stolen_photos')
            ->call('toggleActive', 'appeal_upheld');

        $offered = collect(ReasonCode::grouped())->flatten();
        $this->assertFalse($offered->contains(ReasonCode::StolenPhotos));
        $this->assertTrue(ReasonCode::AppealUpheld->isActive());
    }

    // ---- profile questions ------------------------------------------------------

    public function test_profile_options_can_be_added_renamed_and_hidden(): void
    {
        $admin = $this->staff(Role::ADMIN);

        Livewire::actingAs($admin)->test(ProfileQuestions::class)
            ->call('selectGroup', 'prompt')
            ->call('create')
            ->set('label', 'My ideal first date')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayHasKey('My ideal first date', ProfileOptions::options('prompt'));

        $often = ProfileOption::query()->where('group', 'drinking')->where('key', 'often')->firstOrFail();

        Livewire::actingAs($admin)->test(ProfileQuestions::class)
            ->call('selectGroup', 'drinking')
            ->call('edit', $often->id)
            ->set('label', 'Enjoys a drink most days')
            ->call('save')
            ->call('toggleActive', $often->id);

        $this->assertArrayNotHasKey('often', ProfileOptions::options('drinking'));
        // A member who already answered "often" keeps it, with the new wording.
        $this->assertSame('Enjoys a drink most days', ProfileOptions::forSelect('drinking', 'often')['often']);

        // Fixed groups take no new values.
        Livewire::actingAs($admin)->test(ProfileQuestions::class)
            ->call('selectGroup', 'drinking')
            ->call('create')
            ->assertForbidden();
    }

    public function test_the_last_visible_option_cannot_be_hidden(): void
    {
        $admin = $this->staff(Role::ADMIN);
        $options = ProfileOption::query()->where('group', 'smoking')->get();

        $component = Livewire::actingAs($admin)->test(ProfileQuestions::class)->call('selectGroup', 'smoking');

        foreach ($options as $option) {
            $component->call('toggleActive', $option->id);
        }

        $this->assertCount(1, ProfileOptions::options('smoking'));
    }

    // ---- notification templates -------------------------------------------------

    public function test_templates_can_be_added_and_deleted_but_notices_are_protected(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $admin = $this->staff(Role::ADMIN);

        Livewire::actingAs($admin)->test(Templates::class)
            ->call('create')
            ->set('newCategory', 'engagement')
            ->set('newName', 'Weekend reminder')
            ->assertSet('newKey', 'engagement.weekend_reminder')
            ->set('newBody', 'Hi {{ first_name }}, {{ city }} is busy this weekend.')
            ->call('store')
            ->assertHasErrors('newBody')
            ->set('newPlaceholders', 'first_name, city')
            ->call('store')
            ->assertHasNoErrors();

        $template = NotificationTemplate::query()->where('key', 'engagement.weekend_reminder')->firstOrFail();
        $this->assertSame(['first_name', 'city'], $template->placeholders);

        Livewire::actingAs($admin)->test(Templates::class)->call('delete', $template->id);
        $this->assertModelMissing($template);

        $notice = NotificationTemplate::query()->where('is_transactional', true)->firstOrFail();
        Livewire::actingAs($admin)->test(Templates::class)->call('delete', $notice->id);
        $this->assertModelExists($notice);
    }

    public function test_every_masters_screen_opens(): void
    {
        $admin = $this->staff(Role::SUPER_ADMIN);

        foreach (['interests', 'profile-options', 'report-categories', 'reasons', 'locations'] as $screen) {
            $this->actingAs($admin)->get(route('admin.masters.'.$screen))->assertOk();
        }

        // Plans moved to Billing, where the rest of the money lives.
        $this->actingAs($admin)->get(route('admin.billing.plans'))->assertOk();
    }
}
