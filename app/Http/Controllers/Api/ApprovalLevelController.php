<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLevel;
use App\Models\ApprovalScheme;
use App\Models\User;
use Illuminate\Http\Request;

class ApprovalLevelController extends Controller
{
    /**
     * Subjek policy: non-SA hanya boleh mensimulasikan untuk dirinya sendiri.
     * Mengembalikan null bila user_id orang lain diminta oleh role non-SA.
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

    /** Daftar skema + level (scheme switcher & hierarchy builder). */
    public function schemes()
    {
        return response()->json(ApprovalScheme::with('levels')->get());
    }

    public function storeScheme(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:approval_schemes,name',
            'description' => 'nullable|string',
        ]);

        return response()->json(ApprovalScheme::create($data), 201);
    }

    public function updateScheme(Request $request, ApprovalScheme $scheme)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255|unique:approval_schemes,name,'.$scheme->id,
            'description' => 'nullable|string',
        ]);

        $scheme->update($data);

        return response()->json($scheme->fresh()->load('levels'));
    }

    public function destroyScheme(ApprovalScheme $scheme)
    {
        // Skema yang sedang aktif sedang dipakai ApprovalChain untuk
        // membangun antrean. Menghapusnya di tengah jalan membuat perjalanan
        // tidak bisa diajukan karena tidak ada policy yang cocok.
        if ($scheme->is_active) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: skema ini sedang aktif. Aktifkan skema lain lebih dulu.',
            ], 409);
        }

        // Level anak ikut terhapus lewat FK ON DELETE CASCADE, jadi tidak ada
        // baris yatim — tapi dicek eksplisit agar pesan error jelas kalau
        // migrasi FK someday berubah jadi RESTRICT.
        $levelCount = $scheme->levels()->count();
        if ($levelCount > 0) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: skema masih memiliki '.$levelCount
                    .' level approval. Hapus levelnya lebih dulu.',
            ], 409);
        }

        $scheme->delete();

        return response()->noContent();
    }

    /** Aktifkan satu skema (nonaktifkan sisanya). */
    public function activateScheme(ApprovalScheme $scheme)
    {
        ApprovalScheme::query()->update(['is_active' => false]);
        $scheme->update(['is_active' => true]);

        return response()->json(['message' => "Skema {$scheme->name} diaktifkan.", 'data' => $scheme->load('levels')]);
    }

    public function index(Request $request)
    {
        $query = ApprovalLevel::with('scheme');
        if ($request->filled('scheme_id')) {
            $query->where('scheme_id', $request->scheme_id);
        }

        return response()->json($query->orderBy('step')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'scheme_id' => 'required|exists:approval_schemes,id',
            'step' => 'required|integer|min:1',
            'title' => 'required|string|max:255',
            'role' => 'required|string|max:100',
            'trigger' => 'nullable|string|max:255',
            'sla' => 'nullable|string|max:100',
        ]);

        return response()->json(ApprovalLevel::create($data), 201);
    }

    public function show(ApprovalLevel $approvalLevel)
    {
        return response()->json($approvalLevel->load('scheme'));
    }

    public function update(Request $request, ApprovalLevel $approvalLevel)
    {
        $approvalLevel->update($request->validate([
            'step' => 'sometimes|integer|min:1',
            'title' => 'sometimes|string|max:255',
            'role' => 'sometimes|string|max:100',
            'trigger' => 'nullable|string|max:255',
            'sla' => 'nullable|string|max:100',
            'locked' => 'sometimes|boolean',
        ]));

        return response()->json($approvalLevel);
    }

    public function destroy(ApprovalLevel $approvalLevel)
    {
        if ($approvalLevel->locked) {
            return response()->json(['message' => 'Step terkunci sistem tidak dapat dihapus.'], 422);
        }
        $approvalLevel->delete();

        return response()->noContent();
    }

    /** Simulasi uji alur otorisasi + badge kepatuhan policy berdasarkan nominal. */
    public function simulate(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
            'destination' => 'nullable|string',
            'has_advance' => 'sometimes|boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        // Simulasi plafon atas nama pemohon tertentu (SA). Non-SA hanya dirinya.
        $subject = $this->resolveSubject($request, isset($data['user_id']) ? (int) $data['user_id'] : null);
        if ($subject === null) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        // Satu sumber kebenaran dengan eksekusi nyata (ApprovalChain::levelsFor).
        // Catatan: kas bon (has_advance) diajukan terpisah setelah TR, sehingga
        // tidak memicu Level 3 di tahap pengajuan — hanya threshold nominal.
        $steps = array_map(
            fn ($level) => \App\Support\ApprovalChain::TITLES[$level],
            \App\Support\ApprovalChain::levelsFor((float) $data['amount'])
        );
        $steps[] = 'Level 5 - Auto-Ticketing';

        // Pratinjau badge policy memakai aturan yang sama persis dengan store().
        // Tanpa tanggal, days=1 (diekspos di days_assumed agar eksplisit).
        $days = isset($data['start_date'], $data['end_date'])
            ? \App\Support\PolicyCheck::tripDays($data['start_date'], $data['end_date'])
            : 1;
        $policy = \App\Support\PolicyCheck::check($subject, (float) $data['amount'], $days);

        return response()->json([
            'steps' => $steps,
            'total_levels' => count($steps),
            'policy' => $policy ? array_merge($policy, [
                'days_assumed' => $days,
                // Plafon = total policy; within_limit dipakai FE untuk mematikan
                // tombol submit sebelum backend menolak (422).
                'limit' => $policy['total'],
                'within_limit' => (float) $data['amount'] <= $policy['total'],
            ]) : null,
        ]);
    }
}
