<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Enums\ReasonCode;
use App\Models\ReasonCodeSetting;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Enforcement and verification reasons: the wording staff pick from and the
 * statement of reasons members receive.
 *
 * Changing a statement affects future decisions only — every past action
 * keeps the message it was sent with. Reasons the system records by itself
 * (appeal outcomes, expiry, corrections) can be reworded but not switched off.
 */
class Reasons extends Component
{
    public bool $formOpen = false;

    #[Locked]
    public ?string $editingKey = null;

    public string $label = '';

    public string $policyClause = '';

    public string $statement = '';

    public bool $isActive = true;

    public function render(): View
    {
        $counts = DB::table('moderation_actions')->groupBy('reason_code')->selectRaw('reason_code, COUNT(*) c')->pluck('c', 'reason_code');

        $grouped = collect(ReasonCode::cases())->groupBy(fn (ReasonCode $r): string => $r->group());

        return view('livewire.masters.reasons', [
            'grouped' => $grouped,
            'counts' => $counts,
            'canEdit' => auth()->user()?->can('edit_moderation_settings') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Enforcement reasons',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Masters'],
                ['label' => 'Enforcement reasons'],
            ],
        ]);
    }

    public function edit(string $key): void
    {
        $this->authorize('edit_moderation_settings');
        $reason = ReasonCode::from($key);

        $this->resetValidation();
        $this->editingKey = $reason->value;
        $this->label = $reason->label();
        $this->policyClause = $reason->policyClause();
        $this->statement = $reason->statement();
        $this->isActive = $reason->isActive();
        $this->formOpen = true;
    }

    public function restoreDefaults(): void
    {
        $this->authorize('edit_moderation_settings');
        $reason = ReasonCode::from((string) $this->editingKey);

        $this->label = $reason->builtInLabel();
        $this->policyClause = $reason->builtInPolicyClause();
        $this->statement = $reason->builtInStatement();
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorize('edit_moderation_settings');
        $reason = ReasonCode::from((string) $this->editingKey);

        $this->validate([
            'label' => ['required', 'string', 'min:2', 'max:80'],
            'policyClause' => ['required', 'string', 'min:2', 'max:80'],
            'statement' => ['required', 'string', 'min:20', 'max:600'],
            'isActive' => ['boolean'],
        ], [
            'statement.min' => 'The statement is what the member reads, so give them a full sentence.',
        ], ['label' => 'name', 'policyClause' => 'policy clause', 'statement' => 'statement to the member']);

        $setting = ReasonCodeSetting::query()->firstOrNew(['key' => $reason->value]);
        $old = $setting->exists ? $setting->only(['label', 'policy_clause', 'statement', 'is_active']) : null;

        $setting->fill([
            'label' => trim($this->label),
            'policy_clause' => trim($this->policyClause),
            'statement' => trim($this->statement),
            'is_active' => $reason->isSystem() ? true : $this->isActive,
        ])->save();

        $logger->log(module: 'masters', action: 'reason_updated', description: "Updated enforcement reason {$setting->label}", old: $old, new: ['key' => $setting->key] + $setting->only(['label', 'policy_clause', 'statement', 'is_active']));

        $this->formOpen = false;
        session()->flash('status', "{$setting->label} saved.");
    }

    public function toggleActive(string $key, ActivityLogger $logger): void
    {
        $this->authorize('edit_moderation_settings');
        $reason = ReasonCode::from($key);

        if ($reason->isSystem()) {
            session()->flash('error', "{$reason->label()} is recorded by the system, so it cannot be switched off.");

            return;
        }

        $setting = ReasonCodeSetting::query()->firstOrCreate(['key' => $reason->value], [
            'label' => $reason->builtInLabel(),
            'policy_clause' => $reason->builtInPolicyClause(),
            'statement' => $reason->builtInStatement(),
        ]);
        $setting->update(['is_active' => ! $reason->isActive()]);

        $logger->log(module: 'masters', action: $setting->is_active ? 'reason_shown' : 'reason_hidden', description: ($setting->is_active ? 'Enabled' : 'Disabled')." enforcement reason {$setting->label}", new: ['key' => $setting->key]);
        session()->flash('status', $setting->is_active ? "Staff can choose “{$setting->label}” again." : "“{$setting->label}” is no longer offered. Past decisions keep it.");
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }
}
