<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Nilai boolean ('1'/'0') dengan default bila belum ada. */
    public static function bool(string $key, bool $default = true): bool
    {
        $v = static::get($key);
        if ($v === null) {
            return $default;
        }

        return in_array($v, ['1', 'true', 'yes'], true);
    }
}
