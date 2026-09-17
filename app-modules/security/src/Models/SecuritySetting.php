<?php

declare(strict_types=1);

namespace Modules\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Model representing a key-value security configuration setting.
 *
 * Stores host firewall toggles, default drop/accept policies, intrusion detection
 * thresholds (max_retry, find_time, ban_time), and vector protection options.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SecuritySetting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'security_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Retrieve a setting value by key, returning a default if not found.
     *
     * @param  string  $key  Configuration setting name
     * @param  string|null  $default  Fallback value if key is missing
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    /**
     * Retrieve a setting value as a boolean.
     *
     * @param  string  $key  Configuration setting name
     * @param  bool  $default  Fallback boolean if missing
     */
    public static function getBoolean(string $key, bool $default = false): bool
    {
        $val = static::get($key);

        if ($val === null) {
            return $default;
        }

        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retrieve a setting value as an integer.
     *
     * @param  string  $key  Configuration setting name
     * @param  int  $default  Fallback integer if missing
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = static::get($key);

        return $val !== null ? (int) $val : $default;
    }

    /**
     * Store or update a setting by key.
     *
     * @param  string  $key  Configuration setting name
     * @param  mixed  $value  Value to persist (will be stringified)
     */
    public static function set(string $key, mixed $value): self
    {
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue],
        );
    }
}
