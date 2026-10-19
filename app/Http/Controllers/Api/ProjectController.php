<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Project::with('pm');

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('wbs', 'like', "%{$q}%")
                ->orWhereHas('pm', fn ($p) => $p->where('name', 'like', "%{$q}%")));
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate((int) $request->input('per_page', 10)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:projects,code',
            'wbs' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'pm_id' => 'required|exists:users,id',
            'department' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'budget_total' => 'required|numeric|min:0',
        ]);

        return response()->json(Project::create($data)->load('pm'), 201);
    }

    public function show(Project $project)
    {
        return response()->json($project->load('pm'));
    }

    public function update(Request $request, Project $project)
    {
        $project->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'department' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'pm_id' => 'sometimes|exists:users,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'budget_total' => 'sometimes|numeric|min:0',
            'budget_used' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|string|max:50',
        ]));

        return response()->json($project->load('pm'));
    }

    public function destroy(Project $project)
    {
        if ($project->travelRequests()->exists()) {
            return response()->json(['message' => 'Tidak dapat dihapus: proyek masih memiliki travel request.'], 409);
        }

        $project->delete();

        return response()->noContent();
    }

    /** Simulasi sinkronisasi master data ERP SAP S/4HANA. */
    public function syncSap()
    {
        return response()->json([
            'message' => 'Sinkronisasi master data ERP SAP S/4HANA berhasil diperbarui.',
            'synced_at' => now()->toDateTimeString(),
            'total_projects' => Project::count(),
        ]);
    }
}
