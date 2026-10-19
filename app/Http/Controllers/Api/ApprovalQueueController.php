<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalQueue;
use App\Models\Notification;
use App\Models\TravelRequest;
use App\Support\TravelStatuses;
use Illuminate\Http\Request;

class ApprovalQueueController extends Controller
{
    public function index(Request $request)
    {
        // documents ikut dimuat: FE menghitung jumlah lampiran dari
        // travel_request.documents, tanpa ini selalu "0 Lampiran".
        $query = ApprovalQueue::with(['travelRequest.user.department', 'travelRequest.project', 'travelRequest.documents', 'decidedBy', 'onBehalfOf', 'approver']);

        // Skop requester sesuai role + delegasi yang di-Plt-kan
        $ids = $request->user()->visibleUserIdsWithDelegation();
        if ($ids !== null) {
            $query->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        if ($request->filled('type') && $request->type !== 'all') {
            // travel | advance | urgent
            if ($request->type === 'urgent') {
                $query->where('urgent', true);
            } else {
                $query->where('type', $request->type === 'advance' ? 'Uang Muka' : 'Travel');
            }
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $statuses = explode(',', (string) $request->status);
            $query->whereIn('status', count($statuses) > 1 ? $statuses : [$request->status]);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->where('justification', 'like', "%{$q}%")
                    ->orWhereHas('travelRequest', fn ($t) => $t->where('request_no', 'like', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")));
            });
        }

        // Setiap baris diberi can_decide/decided_by_me/waiting_for supaya FE
        // bisa membedakan "giliran saya" vs "menunggu orang lain" — bukan
        // sekadar cek role (yang membuat tombol approve muncul di baris
        // milik approver lain → klik → 403).
        $paginator = $query->latest()->paginate((int) $request->input('per_page', 10));
        $me = $request->user();
        $paginator->getCollection()->transform(
            fn (ApprovalQueue $q) => \App\Support\ApprovalChain::appendDecisionMeta($q, $me)
        );

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'travel_request_id' => 'required|exists:travel_requests,id',
            'type' => 'required|string|max:50',
            'level' => 'required|string|max:50',
            'amount' => 'nullable|numeric|min:0',
            'justification' => 'nullable|string',
            'urgent' => 'sometimes|boolean',
        ]);
        $data['status'] = 'pending';
        // SLA default 24 jam sejak antrean dibuat (dipakai scheduler eskalasi)
        $data['sla_due_at'] = now()->addHours(24);

        return response()->json(ApprovalQueue::create($data), 201);
    }

    public function show(ApprovalQueue $approvalQueue)
    {
        return response()->json($approvalQueue->load(['travelRequest.user', 'travelRequest.project']));
    }

    /**
     * Otorisasi tulis antrean approval: Super Admin saja.
     *
     * SEBELUMNYA update()/destroy() tidak punya cek sama sekali dan route-nya
     * tanpa middleware role — user mana pun yang login bisa menulis baris
     * approval milik siapa saja, termasuk menandainya `approved`.
     */
    private function assertSuperAdmin(Request $request): void
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Hanya Super Admin yang boleh mengubah data antrean approval.');
        }
    }

    public function update(Request $request, ApprovalQueue $approvalQueue)
    {
        $this->assertSuperAdmin($request);

        $data = $request->validate([
            'level' => 'sometimes|string|max:50',
            'urgent' => 'sometimes|boolean',
            // `status` SENGAJA dihapus dari validasi. Status baris antrean
            // adalah hasil keputusan approver — jika boleh ditulis langsung,
            // siapa pun bisa menandai pengajuan `approved` tanpa pernah
            // melalui rantai approval. Transisi hanya lewat approve/reject().
        ]);

        $approvalQueue->update($data);

        return response()->json($approvalQueue);
    }

    public function destroy(Request $request, ApprovalQueue $approvalQueue)
    {
        $this->assertSuperAdmin($request);

        // Baris yang sudah diputus adalah jejak keputusan approval — hapus
        // hanya boleh untuk antrean yang belum diputus.
        if (! in_array($approvalQueue->status, [TravelStatuses::Q_PENDING, TravelStatuses::Q_QUEUED], true)) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: baris approval sudah diputus ('
                    .$approvalQueue->status.') dan menjadi jejak audit.',
            ], 409);
        }

        $approvalQueue->delete();

        return response()->noContent();
    }

    /** Keputusan approve / reject satu item (dengan catatan resmi terekam). */
    public function decide(Request $request, ApprovalQueue $approvalQueue)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string',
        ]);
        // Penolakan tanpa alasan tidak bisa ditindaklanjuti pemohon — wajib diisi.
        if ($data['action'] === 'reject' && mb_strlen(trim((string) ($data['note'] ?? ''))) < 3) {
            return response()->json([
                'message' => 'Alasan penolakan wajib diisi.',
                'errors' => ['note' => ['Alasan penolakan wajib diisi (minimal 3 karakter).']],
            ], 422);
        }

        // Dieksekusi sekuensial via rantai berjenjang (aktifasi level berikut / finalisasi).
        $result = \App\Support\ApprovalChain::decide(
            $approvalQueue, $request->user(), $data['action'], $data['note'] ?? null, $request
        );

        return response()->json([
            'message' => $result['message'],
            'data' => $approvalQueue->fresh(['decidedBy', 'onBehalfOf']),
        ]);
    }

    /** Batch approve massal dari checkbox tabel. */
    public function batchApprove(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'exists:approval_queues,id']);

        // Hanya baris pending — tanpa filter ini baris yang sudah diputus ikut
        // diproses lalu melempar 409 dan membatalkan seluruh batch.
        $items = ApprovalQueue::with('travelRequest')
            ->whereIn('id', $data['ids'])
            ->where('status', 'pending')
            ->get();

        $result = \App\Support\ApprovalChain::batchDecide(
            $items,
            $request->user(),
            $request,
            '{count} permohonan berhasil diproses.'
        );

        return response()->json(
            array_filter([
                'message' => $result['message'],
                'count' => $result['count'] ?? 0,
                'failed' => $result['failed'] ?? null,
            ], fn ($v) => $v !== null),
            $result['ok'] ? 200 : 422
        );
    }
}
