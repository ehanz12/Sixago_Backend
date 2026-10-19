<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Reimbursement;
use Illuminate\Http\Request;
use App\Support\FinanceFlow;
use App\Support\TravelStatuses;

class ReimbursementController extends Controller
{
    public function index(Request $request)
    {
        $query = Reimbursement::with(['travelRequest.user.department', 'travelRequest.project', 'decidedBy']);

        $ids = $request->user()->visibleUserIds();
        if ($ids !== null) {
            $query->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->where('request_no', 'like', "%{$q}%")
                    ->orWhereHas('travelRequest', fn ($t) => $t->where('request_no', 'like', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")));
            });
        }

        $page = $query->latest()->paginate((int) $request->input('per_page', 10));
        $page->getCollection()->transform(fn (Reimbursement $r) => $this->withFlowFlags($r));

        return response()->json($page);
    }

    /**
     * Tempelkan flag alur finance ke baris reimbursement.
     *
     * FE TIDAK boleh menyimpulkan "giliran siapa" dari teks label — dulu
     * `auditLabel.includes('Menunggu')` membuat tombol "Verifikasi" tetap
     * muncul setelah klaim sudah diverifikasi, sehingga diklik tidak
     * mengubah apa pun. Flag ini dikirim apa adanya dari FinanceFlow.
     */
    private function withFlowFlags(Reimbursement $r): Reimbursement
    {
        $status = (string) $r->status;
        $flags = FinanceFlow::flagsFor($status);
        $flags['can_reject'] = FinanceFlow::allowedActions($status) !== [];

        foreach ($flags as $key => $value) {
            $r->setAttribute($key, $value);
        }

        return $r;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'travel_request_id' => 'required|exists:travel_requests,id',
            'actual_expense' => 'required|numeric|min:0',
            'advance' => 'nullable|numeric|min:0',
            'ocr_status' => 'required|string|max:50',
            'receipt_count' => 'nullable|integer|min:0',
            'status' => 'nullable|string|max:50',
        ]);
        // Klaim hanya untuk travel milik sendiri (kecuali SA/Finance)
        $travel = \App\Models\TravelRequest::findOrFail($data['travel_request_id']);
        $me = $request->user();
        if (! $me->isSuperAdmin() && ! $me->hasRole('Finance Approver') && $travel->user_id !== $me->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $data['request_no'] = 'REIM-' . now()->format('Y') . '-' . str_pad((string) (Reimbursement::max('id') + 1), 3, '0', STR_PAD_LEFT);
        $data['status'] ??= TravelStatuses::REIM_AUDIT;
        // Rekonsiliasi otomatis: selisih expense vs advance
        $diff = $data['actual_expense'] - ($data['advance'] ?? 0);
        $data['reconciliation'] = $diff >= 0 ? "+ Rp {$diff} Reimbursable" : "- Rp " . abs($diff) . ' Setor Balik Kas';

        $reim = Reimbursement::create($data);

        return response()->json($reim, 201);
    }

    public function show(Reimbursement $reimbursement)
    {
        return response()->json(
            $this->withFlowFlags($reimbursement->load(['travelRequest.user', 'travelRequest.project', 'decidedBy']))
        );
    }

    public function byRequestNo(string $requestNo)
    {
        $reim = Reimbursement::with(['travelRequest.user', 'travelRequest.project', 'decidedBy'])
            ->where('request_no', $requestNo)
            ->firstOrFail();

        return response()->json($this->withFlowFlags($reim));
    }

    /**
     * Otorisasi tulis data klaim: Finance Approver atau Super Admin.
     *
     * SEBELUMNYA update()/destroy() tidak punya cek sama sekali dan route-nya
     * tanpa middleware role — user mana pun yang login bisa mengubah/menghapus
     * klaim milik siapa saja, termasuk yang sudah disettle.
     */
    private function assertFinance(Request $request): void
    {
        if (! $request->user()->isSuperAdmin() && ! $request->user()->hasRole('Finance Approver')) {
            abort(403, 'Akses ditolak untuk role Anda.');
        }
    }

    public function update(Request $request, Reimbursement $reimbursement)
    {
        $this->assertFinance($request);

        $data = $request->validate([
            'actual_expense' => 'sometimes|numeric|min:0',
            'advance' => 'sometimes|numeric|min:0',
            'ocr_status' => 'sometimes|string|max:50',
            'receipt_count' => 'sometimes|integer|min:0',
            // `status` SENGAJA tidak bisa ditulis langsung — transisi hanya
            // lewat verify() yang dijaga FinanceFlow (verify/settlement/reject).
        ]);

        $reimbursement->update($data);

        return response()->json($reimbursement);
    }

    public function destroy(Request $request, Reimbursement $reimbursement)
    {
        $this->assertFinance($request);

        // Klaim yang sudah disettle/ditolak adalah hasil keputusan finance —
        // menghapusnya menghilangkan jejak penyelesaian dana.
        if (in_array($reimbursement->status, [TravelStatuses::REIM_APPROVED, TravelStatuses::REIM_REJECTED], true)) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: klaim sudah final ('
                    .$reimbursement->status.'). Koreksi lewat perubahan data, bukan hapus.',
            ], 409);
        }

        $reimbursement->delete();

        return response()->noContent();
    }

    /**
     * Verifikasi / tolak / settle klaim oleh finance + notifikasi ke pemohon.
     *
     * Transisi status dijaga FinanceFlow: aksi yang tidak berlaku pada status
     * sekarang ditolak 409. Sebelumnya aksi apa pun diterima dari status mana
     * pun — memverifikasi klaim yang sudah terverifikasi jadi no-op tak terlihat
     * (tombol "tidak bereaksi") sambil mengirim ulang notifikasi ke pemohon.
     */
    public function verify(Request $request, Reimbursement $reimbursement)
    {
        $data = $request->validate(['action' => 'required|in:verify,reject,settlement', 'note' => 'nullable|string']);
        if ($data['action'] === 'reject' && mb_strlen(trim((string) ($data['note'] ?? ''))) < 3) {
            return response()->json([
                'message' => 'Alasan penolakan wajib diisi.',
                'errors' => ['note' => ['Alasan penolakan wajib diisi (minimal 3 karakter).']],
            ], 422);
        }

        $reimbursement->loadMissing('decidedBy');
        $next = FinanceFlow::nextStatus($data['action'], (string) $reimbursement->status);

        if ($next === null) {
            return response()->json([
                'message' => FinanceFlow::rejectionMessage($reimbursement, $data['action'])
                    .' '.FinanceFlow::blockReason((string) $reimbursement->status),
                'errors' => ['action' => [FinanceFlow::rejectionMessage($reimbursement, $data['action'])]],
                'status' => $reimbursement->status,
                'allowed_actions' => FinanceFlow::allowedActions((string) $reimbursement->status),
            ], 409);
        }

        $old = $reimbursement->only('status');
        $reimbursement->update([
            'status' => $next,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        \App\Support\Audits::record('reimbursement.'.$data['action'], $reimbursement, $old, $reimbursement->only('status'), $request);

        $requester = $reimbursement->travelRequest?->user;
        if ($requester) {
            \App\Support\Notify::user(
                $requester,
                "Reimbursement {$reimbursement->request_no}: {$next}",
                $data['note'] ?? "Status klaim Anda: {$next}.",
                'Finance', 'finance', $reimbursement->request_no,
                "Reimbursement {$reimbursement->request_no}: {$next}"
            );
        }

        return response()->json([
            'message' => "Klaim {$reimbursement->request_no}: {$next}.",
            'data' => $reimbursement->fresh()->load('decidedBy'),
        ]);
    }

    public function summary(Request $request)
    {
        $base = Reimbursement::query();
        $ids = $request->user()->visibleUserIds();
        if ($ids !== null) {
            $base->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        $claimed = (clone $base)->sum('actual_expense');
        $adv = (clone $base)->sum('advance');

        return response()->json([
            'total_claimed' => $claimed,
            'total_advance' => $adv,
            'net_reimbursement' => $claimed - $adv,
            'pending_count' => (clone $base)->whereIn('status', [TravelStatuses::REIM_AUDIT, TravelStatuses::REIM_VERIFY])->count(),
        ]);
    }
}
