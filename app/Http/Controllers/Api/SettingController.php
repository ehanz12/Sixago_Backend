<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public const ALLOWED = ['escalation_enabled', 'delegation_enabled'];

    public function index()
    {
        $out = [];
        foreach (self::ALLOWED as $key) {
            $out[$key] = Setting::bool($key, true);
        }

        return response()->json($out);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'escalation_enabled' => 'sometimes|boolean',
            'delegation_enabled' => 'sometimes|boolean',
        ]);

        foreach ($data as $key => $value) {
            if (in_array($key, self::ALLOWED, true)) {
                Setting::set($key, $value ? '1' : '0');
            }
        }

        return response()->json($this->index()->getData());
    }
}
