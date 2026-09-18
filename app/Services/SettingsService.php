<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SettingsService
 *
 * Reads and writes platform settings.
 *
 * Storage model: the `settings` table only holds rows administrators have
 * explicitly saved. Every key without a stored row resolves to the default
 * defined in config/settings.php (which may itself fall back to other config
 * such as the existing Bakong values), so existing behaviour is preserved
 * until an administrator changes a setting.
 *
 * Masks sensitive values — keys flagged as `sensitive` are never returned to
 * the API. The frontend therefore can never accidentally expose credentials.
 */
class SettingsService
{
    /**
     * Per-request resolved value cache (key => value).
     *
     * @var array<string, mixed>
     */
    private array $resolved = [];

    /**
     * The full settings catalog from config/settings.php.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array
    {
        return config('settings', []);
    }

    /**
     * Resolve a single setting's value.
     *
     * Priority: stored DB row -> default from the catalog -> caller fallback.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $catalog = $this->catalog();

        if (! isset($catalog[$key])) {
            return $default;
        }

        $definition = $catalog[$key];
        $stored = DB::table('settings')->where('key', $key)->value('value');
        $fallback = $default ?? $this->resolveDefault($definition['default'] ?? null);

        $value = $stored !== null
            ? $this->cast($stored, $definition['type'] ?? 'string')
            : $fallback;

        return $this->resolved[$key] = $value;
    }

    /**
     * Resolve every setting into a flat key => value map (grouped also
     * available via valuesByGroup()).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];
        foreach (array_keys($this->catalog()) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Resolve every setting grouped by its UI group.
     *
     * @return array<string, array<string, mixed>>
     */
    public function valuesByGroup(): array
    {
        $grouped = [];
        foreach ($this->catalog() as $key => $definition) {
            $group = $definition['group'] ?? 'general';
            $grouped[$group][$key] = $this->get($key);
        }

        return $grouped;
    }

    /**
     * Resolve the public-facing settings the UI needs to render branding,
     * enforce booking constraints and show maintenance state. Only
     * non-sensitive keys are exposed — credentials never leave this list.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        $keys = [
            'general.platform_name',
            'general.platform_description',
            'general.support_email',
            'general.support_phone',
            'general.default_currency',
            'general.timezone',
            'general.language',
            'appearance.logo',
            'appearance.favicon',
            'appearance.primary_color',
            'appearance.secondary_color',
            'appearance.theme',
            'appearance.tagline',
            'appearance.footer_copyright',
            'booking.enabled',
            'booking.min_tickets',
            'booking.max_tickets',
            'booking.cancellation_enabled',
            'booking.cancellation_deadline',
            'booking.require_phone',
            'booking.require_email_verification',
            'payment.enabled',
            'payment.bakong_enabled',
            'payment.currency',
            'user.registration_enabled',
            'user.google_login',
            'event.allow_cancellation',
            'event.admin_approval_required',
            'ticket.qr_enabled',
            'ticket.allow_download',
            'ticket.allow_printing',
            'system.maintenance_mode',
            'system.maintenance_message',
        ];

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Persist a subset of settings. Unknown keys are ignored (never inserted);
     * known keys are validated against their catalog rules and upserted using
     * updateOrCreate so only the `settings` table is touched.
     *
     * @param  array<string, mixed>  $values  key => value map
     * @param  int|null  $updaterId  user performing the update (audit)
     * @return array<string, mixed> the newly persisted resolved values
     *
     * @throws ValidationException
     */
    public function update(array $values, ?int $updaterId = null): array
    {
        $catalog = $this->catalog();
        $upsert = [];
        // Build the nested payload ("general.platform_name" -> settings ->
        // general -> platform_name) so dotted keys validate correctly.
        // Start from the CURRENT resolved values so cross-field rules (e.g.
        // max_tickets gte min_tickets) can see values that were not included
        // in this request, then overlay the incoming values.
        $nested = [];
        foreach ($this->all() as $currentKey => $currentValue) {
            data_set($nested, $currentKey, $currentValue);
        }

        $rulesMap = [];

        foreach ($values as $key => $value) {
            if (! isset($catalog[$key])) {
                continue;
            }

            $definition = $catalog[$key];
            $rules = $definition['rules'] ?? [];
            data_set($nested, $key, $value);
            $rulesMap["settings.{$key}"] = $rules;

            $upsert[$key] = [
                'type' => $definition['type'] ?? 'string',
                'group' => $definition['group'] ?? 'general',
                'description' => $definition['desc'] ?? null,
                'value' => $this->normalizeForStorage($value, $definition['type'] ?? 'string'),
                'updated_by' => $updaterId,
            ];
        }

        if ($upsert === []) {
            return $this->all();
        }

        // Friendly attribute names in message text (must be set BEFORE
        // validation runs, then the "settings." prefix is stripped from the
        // error keys so the frontend can match them to its fields).
        $attributeNames = [];
        foreach ($upsert as $key => $_) {
            $label = $catalog[$key]['label'] ?? $key;
            $attributeNames["settings.{$key}"] = $this->humanize($label);
        }

        $validator = Validator::make(['settings' => $nested], $rulesMap);
        $validator->setAttributeNames($attributeNames);

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[preg_replace('/^settings\./', '', $field)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($upsert) {
            foreach ($upsert as $key => $attributes) {
                DB::table('settings')->updateOrInsert(['key' => $key], $attributes);
            }
        });

        $this->resolved = [];
        Cache::forget('settings:values');

        return $this->all();
    }

    /**
     * Cast a stored DB string back to its typed value.
     */
    protected function cast(?string $stored, string $type): mixed
    {
        if ($stored === null) {
            return null;
        }

        return match ($type) {
            'boolean' => in_array($stored, ['1', 'true', 'TRUE', 'on', 'yes'], true),
            'integer' => (int) $stored,
            default => $stored,
        };
    }

    /**
     * Normalize a typed value to its string representation for storage.
     */
    protected function normalizeForStorage(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) (int) $value,
            'string', 'color' => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * Turn an i18n label ("settings.general.supportEmail") into a friendly
     * attribute name for validation messages ("Support Email").
     */
    protected function humanize(string $label): string
    {
        $segment = substr($label, strrpos($label, '.') + 1);
        $words = preg_split('/(?=[A-Z])/', $segment, -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map(ucfirst(...), $words));
    }

    /**
     * A catalog default may be a closure (so it can defer to other config
     * at resolution time) or a plain value.
     */
    protected function resolveDefault(mixed $default): mixed
    {
        return $default instanceof \Closure
            ? $default()
            : $default;
    }
}