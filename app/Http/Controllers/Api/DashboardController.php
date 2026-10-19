<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\ApprovalQueue;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\TravelRequest;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        // Angka travel/keuangan dibatasi skop role; master proyek tetap global
        $ids = $request->user()->visibleUserIds();
        $travel = $ids === null ? TravelRequest::query() : TravelRequest::whereIn('user_id', $ids);
        $queue = ApprovalQueue::query();
        $adv = Advance::query();
        $reim = Reimbursement::query();
        if ($ids !== null) {
            $queue->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
            $adv->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
            $reim->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        return response()->json([
            'total_requests' => (clone $travel)->count(),
            'pending_approval' => (clone $queue)->where('status', 'pending')->count(),
            'in_review' => (clone $travel)->where('status', 'In Review')->count(),
            'approved' => (clone $travel)->where('status', 'Approved')->count(),
            'total_commitment' => (clone $travel)->sum('cost'),
            'active_projects' => Project::where('status', 'Active')->count(),
            'total_budget_used' => Project::sum('budget_used'),
            'pending_advances' => (clone $adv)->where('status', 'Pending Finance')->count(),
            'pending_reimbursements' => (clone $reim)->whereIn('status', ['Menunggu Audit', 'Menunggu Verifikasi Finance'])->count(),
        ]);
    }

    /**
     * Badge sidebar: antrean approval pending + notifikasi unread,
     * keduanya otomatis ter-scope sesuai hak akses user login.
     */
    public function sidebarBadges(Request $request)
    {
        $me = $request->user();
        $ids = $me->visibleUserIds();

        $queue = ApprovalQueue::where('status', 'pending');
        if ($ids !== null) {
            $queue->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        return response()->json([
            'approval_pending' => $queue->count(),
            'notifications_unread' => \App\Models\Notification::where('user_id', $me->id)->where('read', false)->count(),
        ]);
    }

    /**
     * Data grafik: tren biaya per bulan (12 bulan terakhir) +
     * distribusi biaya per departemen (berdasarkan requester).
     */
    public function charts(Request $request)
    {
        $ids = $request->user()->visibleUserIds();
        $scoped = function ($q) use ($ids) {
            if ($ids !== null) {
                $q->whereIn('user_id', $ids);
            }

            return $q;
        };

        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $d = now()->subMonths($i);
            $q = TravelRequest::whereYear('start_date', $d->year)->whereMonth('start_date', $d->month);
            $scoped($q);
            $monthly[] = ['label' => $d->translatedFormat('M'), 'total' => (float) $q->sum('cost')];
        }

        $deptRows = TravelRequest::with('user.department')
            ->when($ids !== null, fn ($q) => $q->whereIn('user_id', $ids))
            ->get()
            ->groupBy(fn ($t) => $t->user?->department?->name ?? 'Lainnya')
            ->map(fn ($g, $name) => ['name' => $name, 'total' => (float) $g->sum('cost')])
            ->sortByDesc('total')
            ->take(6)
            ->values();

        return response()->json(['monthly' => $monthly, 'by_department' => $deptRows]);
    }
}
