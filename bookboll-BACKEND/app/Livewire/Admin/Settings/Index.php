<?php

namespace App\Livewire\Admin\Settings;

use App\Models\Setting;
use App\Services\SettingService;
use App\Support\SettingsRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Edit the `settings` table from the admin panel.
 *
 * Every key is reachable, including ones SettingsRegistry has never heard of —
 * those render in the "Other" group from the `type` column, so a setting added
 * by code is editable the day it appears rather than the day someone remembers
 * to describe it.
 *
 * Writes go through SettingService::put(), the same path the API uses, so linked
 * keys stay in step however they were edited. Only genuinely changed values are
 * written, and each save is recorded in the activity log with before/after —
 * these values change how bookings and payments behave, so "who set this to 2?"
 * has to be answerable.
 */
#[Layout('layouts.admin')]
#[Title('Settings')]
class Index extends Component
{
    /** key => the value currently in the form. Strings throughout, as the table stores them. */
    public array $values = [];

    /** key => the value as last read from the database, to detect real changes. */
    public array $original = [];

    /** key => the `type` column, read once per load rather than per lookup. */
    public array $types = [];

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $group = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        $rows = Setting::query()->orderBy('key')->get(['key', 'value', 'type']);

        $this->values = [];
        $this->original = [];
        $this->types = [];

        foreach ($rows as $row) {
            $this->types[$row->key] = $row->type;

            $definition = SettingsRegistry::describe($row->key, $row->type);

            // Booleans are checkboxes; everything else is a string in a text input.
            $value = $definition['input'] === 'boolean'
                ? (bool) $row->value
                : (string) ($row->value ?? '');

            $this->values[$row->key] = $value;
            $this->original[$row->key] = $value;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'group']);
    }

    /** Drop edits and re-read the table. */
    public function discardChanges(): void
    {
        $this->loadSettings();
        $this->resetErrorBag();
        $this->statusMessage = 'Unsaved changes discarded.';
        $this->errorMessage = null;
    }

    /** @return array<string, mixed> only the keys whose value actually differs */
    private function changedValues(): array
    {
        $changed = [];

        foreach ($this->values as $key => $value) {
            if (! array_key_exists($key, $this->original)) {
                continue;
            }

            if ($this->isReadonly($key)) {
                continue;
            }

            if ($value !== $this->original[$key]) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    private function isReadonly(string $key): bool
    {
        return (bool) SettingsRegistry::describe($key, $this->types[$key] ?? null)['readonly'];
    }

    public function save(SettingService $settings): void
    {
        $changed = $this->changedValues();

        if ($changed === []) {
            $this->errorMessage = 'Nothing to save — no values have changed.';
            $this->statusMessage = null;

            return;
        }

        // Validate against the registry before anything is written, so a bad
        // value cannot leave half the form saved.
        $types = $this->types;

        $rules = [];
        $attributes = [];

        foreach ($changed as $key => $value) {
            $rules["values.{$key}"] = SettingsRegistry::rulesFor($key, $types[$key] ?? null);
            $attributes["values.{$key}"] = SettingsRegistry::describe($key, $types[$key] ?? null)['label'];
        }

        try {
            $this->validate($rules, [], $attributes);
        } catch (ValidationException $e) {
            $this->errorMessage = 'Some values could not be saved — see the fields marked below.';
            $this->statusMessage = null;

            throw $e;
        }

        $written = [];

        DB::transaction(function () use ($changed, $types, $settings, &$written) {
            foreach ($changed as $key => $value) {
                $definition = SettingsRegistry::describe($key, $types[$key] ?? null);

                if ($definition['transform'] === 'upper' && is_string($value)) {
                    $value = strtoupper($value);
                }

                $written += $settings->put($key, $value);
            }
        });

        activity('setting')
            ->causedBy(Auth::user())
            ->withProperties([
                'changed' => array_map(
                    fn ($key) => [
                        'key' => $key,
                        'from' => $this->original[$key] ?? null,
                        'to' => $written[$key] ?? null,
                    ],
                    array_keys($written)
                ),
            ])
            ->log('Updated '.count($written).' setting(s) from the admin panel');

        $this->loadSettings();

        $this->statusMessage = count($written) === 1
            ? 'Setting saved.'
            : count($written).' settings saved.';
        $this->errorMessage = null;
    }

    public function render()
    {
        $rows = Setting::query()->orderBy('key')->get(['key', 'value', 'type', 'description']);

        $search = trim(mb_strtolower($this->q));

        $grouped = [];

        foreach ($rows as $row) {
            $definition = SettingsRegistry::describe($row->key, $row->type);

            if ($this->group !== '' && $definition['group'] !== $this->group) {
                continue;
            }

            if ($search !== '') {
                $haystack = mb_strtolower($row->key.' '.$definition['label'].' '.($definition['help'] ?? ''));

                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }

            $grouped[$definition['group']][] = [
                'key' => $row->key,
                'definition' => $definition,
                'description' => $row->description,
            ];
        }

        // Registry order, not whatever order the rows happened to arrive in.
        $groups = [];

        foreach (SettingsRegistry::groups() as $name => $meta) {
            if (! empty($grouped[$name])) {
                $groups[$name] = $meta + ['settings' => $grouped[$name]];
            }
        }

        return view('livewire.admin.settings.index', [
            'groups' => $groups,
            'groupOptions' => array_map(fn (array $g) => $g['label'], SettingsRegistry::groups()),
            'totalShown' => array_sum(array_map(fn (array $g) => count($g['settings']), $groups)),
            'totalSettings' => $rows->count(),
            'changedCount' => count($this->changedValues()),
            'hasActiveFilters' => $this->q !== '' || $this->group !== '',
        ]);
    }
}
