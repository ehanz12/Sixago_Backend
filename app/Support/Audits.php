<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Audits
{
    public static function record(string $action, ?Model $model = null, array $old = [], array $new = [], ?Request $request = null): AuditLog
    {
        $request ??= request();

        return AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => $request->ip(),
        ]);
    }
}
