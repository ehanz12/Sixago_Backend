<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\Notification;
use App\Models\TravelRequest;
use Illuminate\Http\Request;
use App\Support\FinanceFlow;
use App\Support\PolicyCheck;
use App\Support\TravelStatuses;

class AdvanceController extends Controller
{
    /**
     * Plafon & pembatas uang muka dari policy grade pemohon travel.
     * Return null bila tak ada policy aktif yang cocok (tanpa pembatas).
     *
     * @return ?array{policy:\App\Models\TravelPolicy,total:float}
     */
    private function policyLimitFor(?TravelRequest $travel): ?array
    {
        if (! $travel || ! $travel->user) {
            return null;
        }

        $days = PolicyCheck::tripDays((string) $travel->start_date, (string) $travel->end_date);

        return PolicyCheck::resolve($travel->user, $days);
    }

    private function overPlafonMessage(?TravelRequest $travel, float $amount, array $limit): string
    {
        return 'Nominal Rp '.number_format($amount, 0, ',', '.')
            .' melebihi plafon policy '.$limit['policy']->code
            .' untuk grade '.($travel?->user?->grade ?? '-')
            .' (maks Rp '.number_format($limit['total'], 0, ',', '.').').';
    }

    public function index(Request $request)
    {
        $query = Advance::with(['travelRequest.user.department', 'decidedBy']);

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
        if ($request->filled('method') && $request->method !== 'all') {
            $query->where('method', $request->method);
        }

        $page = $query->latest()->paginate((int) $request->input('per_page', 10));
        $page->getCollection()->transform(function (Advance $a) {
            $status = (string) $a->status;
            $flags = FinanceFlow::flagsFor($status, true);
            // can_decide = aksi PUTUSAN (approve), bukan "ada aksi apa saja".
            // Kalau memakai allowedActions() != [], status "Ready to Pay" ikut
            // bernilai true dan tombol Setujui/Tolak muncul lagi.
            $flags['can_decide'] = in_array('approve', FinanceFlow::allowedActions($status, true), true);
            $flags['can_reject'] = in_array('reject', FinanceFlow::allowedActions($status, true), true);

            foreach ($flags as $key => $value) {
                $a->setAttribute($key, $value);
            }

            return $a;
        });

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'travel_request_id' => 'required|exists:travel_requests,id',
            'amount' => 'required|numeric|min:0',
            'plafon' => 'nullable|numeric|min:0',
            'account' => 'nullable|string|max:100',
            'account_name' => 'nullable|string|max:100',
            'method' => 'required|string|max:50',
            'purpose' => 'nullable|string',
            'destination' => 'nullable|string|max:255',
        ]);
        // Pengajuan hanya untuk travel milik sendiri (kecuali SA/Finance)
        $travel = TravelRequest::findOrFail($data['travel_request_id']);
        $me = $request->user();
        if (! $me->isSuperAdmin() && ! $me->hasRole('Finance Approver') && $travel->user_id !== $me->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        // Plafon di-set server dari policy grade pemohon (bukan input manual),
        // dan nominal tidak boleh melebihi plafon tersebut.
        $limit = $this->policyLimitFor($travel);
        if ($limit !== null) {
            $data['plafon'] = $limit['total'];
            if ((float) $data['amount'] > $limit['total']) {
                return response()->json([
                    'message' => $this->overPlafonMessage($travel, (float) $data['amount'], $limit),
                    'errors' => ['amount' => [
                        'Nominal melebihi plafon policy '.$limit['policy']->code
                        .' (maks Rp '.number_format($limit['total'], 0, ',', '.').').',
                    ]],
                    'policy' => ['policy_code' => $limit['policy']->code, 'limit' => $limit['total']],
                ], 422);
            }
        }

        $data['request_no'] = 'ADV-' . now()->format('Y') . '-' . str_pad((string) (Advance::max('id') + 1), 3, '0', STR_PAD_LEFT);
        $data['status'] = TravelStatuses::ADV_PENDING;

        return response()->json(Advance::create($data), 201);
    }

    /** Tempel flag alur finance (can_disburse/block_reason/…) ke baris advance. */
    private function withFlowFlags(Advance $a): Advance
    {
        $status = (string) $a->status;
        $flags = FinanceFlow::flagsFor($status, true);
        $flags['can_decide'] = in_array('approve', FinanceFlow::allowedActions($status, true), true);
        $flags['can_reject'] = in_array('reject', FinanceFlow::allowedActions($status, true), true);

        foreach ($flags as $key => $value) {
            $a->setAttribute($key, $value);
        }

        return $a;
    }

    public function show(Advance $advance)
    {
        return response()->json(
            $this->withFlowFlags($advance->load(['travelRequest.user', 'travelRequest.project', 'decidedBy']))
        );
    }

    public function byRequestNo(string $requestNo)
    {
        $adv = Advance::with(['travelRequest.user', 'travelRequest.project', 'decidedBy'])
            ->where('request_no', $requestNo)
            ->firstOrFail();

        return response()->json($this->withFlowFlags($adv));
    }

    /**
     * Otorisasi tulis data uang muka: Finance Approver atau Super Admin.
     *
     * SEBELUMNYA update()/destroy() tidak punya cek sama sekali dan route-nya
     * tanpa middleware role — user mana pun yang login bisa mengubah/menghapus
     * advance milik siapa saja, termasuk advance berstatus Paid beserta
     * nomor transfer dan buktinya.
     */
    private function assertFinance(Request $request): void
    {
        if (! $request->user()->isSuperAdmin() && ! $request->user()->hasRole('Finance Approver')) {
            abort(403, 'Akses ditolak untuk role Anda.');
        }
    }

    public function update(Request $request, Advance $advance)
    {
        $this->assertFinance($request);

        $data = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'account' => 'nullable|string|max:100',
            'account_name' => 'nullable|string|max:100',
            'method' => 'sometimes|string|max:50',
            'purpose' => 'nullable|string',
            'destination' => 'nullable|string|max:255',
            // `status` SENGAJA tidak bisa ditulis langsung — transisi status
            // hanya lewat decide()/disburse() yang dijaga FinanceFlow. Jika
            // diterima di sini, siapa pun bisa menandai "Paid" tanpa pencairan.
        ]);

        // Plafon selalu dihitung ulang dari policy grade pemohon; nominal
        // efektif (lama + perubahan) tidak boleh melebihi plafon itu.
        $limit = $this->policyLimitFor($advance->travelRequest);
        if ($limit !== null) {
            $data['plafon'] = $limit['total'];
            $effectiveAmount = (float) ($data['amount'] ?? $advance->amount);
            if ($effectiveAmount > $limit['total']) {
                return response()->json([
                    'message' => $this->overPlafonMessage($advance->travelRequest, $effectiveAmount, $limit),
                    'errors' => ['amount' => [
                        'Nominal melebihi plafon policy '.$limit['policy']->code
                        .' (maks Rp '.number_format($limit['total'], 0, ',', '.').').',
                    ]],
                    'policy' => ['policy_code' => $limit['policy']->code, 'limit' => $limit['total']],
                ], 422);
            }
        }

        $advance->update($data);

        return response()->json($advance);
    }

    public function destroy(Request $request, Advance $advance)
    {
        $this->assertFinance($request);

        // Record pencairan (Paid) adalah bukti transfer — tidak boleh hilang.
        if (in_array($advance->status, [TravelStatuses::ADV_PAID, TravelStatuses::ADV_REJECTED], true)) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: pengajuan sudah final ('
                    .$advance->status.'). Koreksi lewat perubahan data, bukan hapus.',
            ], 409);
        }

        $advance->delete();

        return response()->noContent();
    }

    /** Approve / reject pengajuan uang muka oleh finance. */
    public function decide(Request $request, Advance $advance)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'note' => 'nullable|string']);
        if ($data['action'] === 'reject' && mb_strlen(trim((string) ($data['note'] ?? ''))) < 3) {
            return response()->json([
                'message' => 'Alasan penolakan wajib diisi.',
                'errors' => ['note' => ['Alasan penolakan wajib diisi (minimal 3 karakter).']],
            ], 422);
        }
        // Transisi dijaga FinanceFlow: approve dua kali / setujui yang sudah
        // dibayar ditolak 409, bukan diam-diam menulis ulang status yang sama.
        $advance->loadMissing('decidedBy');
        $next = FinanceFlow::nextStatus($data['action'], (string) $advance->status, true);
        if ($next === null) {
            return response()->json([
                'message' => FinanceFlow::rejectionMessage($advance, $data['action'], true)
                    .' '.FinanceFlow::blockReason((string) $advance->status, true),
                'errors' => ['action' => [FinanceFlow::rejectionMessage($advance, $data['action'], true)]],
                'status' => $advance->status,
                'allowed_actions' => FinanceFlow::allowedActions((string) $advance->status, true),
            ], 409);
        }

        $old = $advance->only('status');
        $advance->update([
            'status' => $next,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        \App\Support\Audits::record(
            $data['action'] === 'approve' ? 'advance.approve' : 'advance.reject',
            $advance, $old, $advance->only('status'), $request
        );

        return response()->json(['message' => "Pengajuan {$advance->request_no}: {$advance->status}.", 'data' => $advance]);
    }

    /**
     * Pencairan dana oleh finance dengan bukti transfer manual
     * (nomor referensi bank + file bukti, menggantikan simulasi H2H).
     */
    public function disburse(Request $request, Advance $advance)
    {
        $data = $request->validate([
            'transfer_ref' => 'required|string|max:100',
            'proof' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);

        // Cair hanya dari "Approved-Ready to Pay".
        $advance->loadMissing('decidedBy');
        $next = FinanceFlow::nextStatus('disburse', (string) $advance->status, true);
        if ($next === null) {
            return response()->json([
                'message' => FinanceFlow::rejectionMessage($advance, 'disburse', true)
                    .' '.FinanceFlow::blockReason((string) $advance->status, true),
                'errors' => ['transfer_ref' => [FinanceFlow::rejectionMessage($advance, 'disburse', true)]],
                'status' => $advance->status,
                'allowed_actions' => FinanceFlow::allowedActions((string) $advance->status, true),
            ], 409);
        }

        $payload = [
            'status' => $next,
            'transfer_ref' => $data['transfer_ref'],
            'disbursed_at' => now(),
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ];
        if ($request->hasFile('proof')) {
            $payload['proof_path'] = $request->file('proof')->store('transfer-proofs', 'public');
        }
        $old = $advance->only('status');
        $advance->update($payload);

        \App\Support\Audits::record('advance.disburse', $advance, $old, $advance->only('status', 'transfer_ref'), $request);

        $user = $advance->travelRequest?->user;
        if ($user) {
            \App\Support\Notify::user(
                $user,
                "Uang muka {$advance->request_no} berhasil ditransfer",
                "Pencairan dana Rp {$advance->amount} ke {$advance->account} telah diproses. Ref: {$advance->transfer_ref}.",
                'Finance', 'finance', $advance->request_no,
                "Dana {$advance->request_no} cair"
            );
        }

        return response()->json([
            'message' => "Pencairan {$advance->request_no} tercatat dengan ref {$advance->transfer_ref}.",
            'data' => $advance->fresh(),
        ]);
    }

    public function summary(Request $request)
    {
        $base = Advance::query();
        $ids = $request->user()->visibleUserIds();
        if ($ids !== null) {
            $base->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        return response()->json([
            'total_disbursed' => (clone $base)->where('status', TravelStatuses::ADV_PAID)->sum('amount'),
            'pending_count' => (clone $base)->where('status', TravelStatuses::ADV_PENDING)->count(),
            'pending_amount' => (clone $base)->where('status', TravelStatuses::ADV_PENDING)->sum('amount'),
            'ready_count' => (clone $base)->where('status', TravelStatuses::ADV_READY)->count(),
            'ready_amount' => (clone $base)->where('status', TravelStatuses::ADV_READY)->sum('amount'),
        ]);
    }
}
