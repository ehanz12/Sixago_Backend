<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** Riwayat audit — filter jenis entitas / aksi / user. */
    public function index(Request $request)
    {
        $query = AuditLog::with('user')->latest();

        if ($request->filled('type')) {
            $query->where('auditable_type', 'like', '%\\'.$request->type);
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', "%{$request->action}%");
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        return response()->json($query->paginate((int) $request->input('per_page', 20)));
    }
}
