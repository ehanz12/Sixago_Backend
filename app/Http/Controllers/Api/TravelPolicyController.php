<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TravelPolicy;
use App\Models\TravelRequest;
use App\Models\User;
use App\Support\PolicyCheck;
use App\Support\Positions;
use Illuminate\Http\Request;

class TravelPolicyController extends Controller
{
    /**
     * Subjek policy: non-SA hanya boleh menanyakan plafon untuk dirinya sendiri.
     * Mengembalikan null bila user_id orang lain diminta oleh role non-SA.
     * Dipakai `applicable` (plafon) & `ApprovalLevelController@simulate`.
     */
    private function resolveSubject(Request $request, ?int $userId): ?User
    {
        $actor = $request->user();
        if ($userId === null || (int) $userId === (int) $actor->id) {
            return $actor;
        }
        if (! $actor->isSuperAdmin()) {
            return null;
        }

        return User::findOrFail($userId);
    }

    /**
     * Policy yang berlaku untuk grade pemohon — dipakai form Travel Request &
     * Uang Muka untuk menampilkan plafon otomatis (tanpa pilih manual).
     * Query: travel_request_id ATAU user_id (+ opsional start_date/end_date).
     * Tanpa keduanya → user login, days=1. Mengembalikan {policy: null} bila
     * tak ada yang cocok. Non-SA hanya boleh untuk dirinya sendiri (403).
     */
    public function applicable(Request $request)
    {
        $request->validate([
            'travel_request_id' => 'nullable|integer|exists:travel_requests,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $user = $request->filled('user_id')
            ? $this->resolveSubject($request, (int) $request->input('user_id'))
            : $request->user();

        if ($request->filled('user_id') && $user === null) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $start = $request->input('start_date');
        $end = $request->input('end_date');

        if ($request->filled('travel_request_id')) {
            $travel = TravelRequest::with('user')->findOrFail($request->input('travel_request_id'));
            $user = $travel->user;
            $start = $travel->start_date;
            $end = $travel->end_date;
        }

        if (! $user) {
            return response()->json(['policy' => null]);
        }

        $days = ($start && $end)
            ? PolicyCheck::tripDays((string) $start, (string) $end)
            : 1;
        $limit = PolicyCheck::resolve($user, $days);

        if ($limit === null) {
            return response()->json(['policy' => null]);
        }

        $p = $limit['policy'];

        return response()->json([
            'policy' => [
                'policy_code' => $p->code,
                'grade' => $p->grade,
                'user_grade' => $user->grade,
                'region' => $p->region,
                'sla' => $p->sla,
                'days' => $days,
                'limit' => $limit['total'],
                'transport_plafon' => PolicyCheck::parseRupiah($p->transport_plafon),
                'hotel_plafon' => PolicyCheck::parseRupiah($p->hotel_plafon),
                'per_diem' => PolicyCheck::parseRupiah($p->per_diem),
                'local_transport' => PolicyCheck::parseRupiah($p->local_transport),
            ],
        ]);
    }

    /**
     * Grade kanonik → apakah tercakup travel policy aktif.
     *
     * FE memakai ini untuk memberi tahu "grade ini belum punya plafon" saat
     * memilih grade di form user. Dihitung backend supaya PolicyCheck::gradeMatches
     * tidak perlu diduplikasi di frontend.
     */
    public function gradeCoverage()
    {
        $coverage = Positions::gradeCoverage();

        return response()->json([
            'grades' => Positions::grades(),
            'covered' => $coverage['covered'],
            'uncovered' => $coverage['uncovered'],
        ]);
    }

    public function index(Request $request)
    {
        $query = TravelPolicy::query();

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('grade', 'like', "%{$q}%"));
        }
        if ($request->filled('region') && $request->region !== 'all') {
            $query->where('region', $request->region);
        }
        if ($request->filled('status')) {
            $query->where('enabled', $request->status === 'active');
        }

        return response()->json($query->latest()->paginate((int) $request->input('per_page', 10)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:travel_policies,code',
            'grade' => 'required|string|max:100',
            'transport_plafon' => 'required|string|max:100',
            'hotel_plafon' => 'required|string|max:100',
            'per_diem' => 'required|string|max:100',
            'local_transport' => 'required|string|max:100',
            'sla' => 'required|string|max:100',
            'region' => 'required|string|max:50',
            'enabled' => 'sometimes|boolean',
        ]);

        return response()->json(TravelPolicy::create($data), 201);
    }

    public function show(TravelPolicy $travelPolicy)
    {
        return response()->json($travelPolicy);
    }

    public function update(Request $request, TravelPolicy $travelPolicy)
    {
        $travelPolicy->update($request->validate([
            'grade' => 'sometimes|string|max:100',
            'transport_plafon' => 'sometimes|string|max:100',
            'hotel_plafon' => 'sometimes|string|max:100',
            'per_diem' => 'sometimes|string|max:100',
            'local_transport' => 'sometimes|string|max:100',
            'sla' => 'sometimes|string|max:100',
            'region' => 'sometimes|string|max:50',
            'enabled' => 'sometimes|boolean',
        ]));

        return response()->json($travelPolicy);
    }

    public function destroy(TravelPolicy $travelPolicy)
    {
        $travelPolicy->delete();

        return response()->noContent();
    }

    /** Toggle aktif / nonaktif dari switch di tabel policy. */
    public function toggle(TravelPolicy $travelPolicy)
    {
        $travelPolicy->update(['enabled' => ! $travelPolicy->enabled]);

        return response()->json($travelPolicy);
    }
}
