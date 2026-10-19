<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\TravelPolicy;
use App\Models\TravelRequest;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    private function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Batasi baris sesuai skop visibilitas role (null = semua). */
    private function travelScope($query, \Illuminate\Http\Request $request, bool $viaTravel = false)
    {
        $ids = $request->user()->visibleUserIds();
        if ($ids === null) {
            return $query;
        }

        return $viaTravel
            ? $query->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids))
            : $query->whereIn('user_id', $ids);
    }

    public function travelRequests(\Illuminate\Http\Request $request)
    {
        $rows = $this->travelScope(TravelRequest::with(['user', 'project'])->latest(), $request)->get()->map(fn ($t) => [
            $t->request_no, $t->user?->name, $t->user?->nik, $t->project?->name,
            $t->route, $t->transport, $t->start_date, $t->end_date, $t->cost, $t->status,
        ]);

        return $this->csv('travel-requests.csv',
            ['No. Request', 'Pemohon', 'NIK', 'Proyek', 'Rute', 'Transport', 'Mulai', 'Selesai', 'Estimasi Biaya', 'Status'],
            $rows);
    }

    public function advances(\Illuminate\Http\Request $request)
    {
        $rows = $this->travelScope(Advance::with(['travelRequest.user'])->latest(), $request, true)->get()->map(fn ($a) => [
            $a->request_no, $a->travelRequest?->request_no, $a->travelRequest?->user?->name,
            $a->purpose, $a->destination, $a->amount, $a->plafon, $a->account, $a->method, $a->status,
        ]);

        return $this->csv('uang-muka.csv',
            ['No. Advance', 'Travel ID', 'Pemohon', 'Keperluan', 'Tujuan', 'Nominal', 'Plafon', 'Rekening', 'Metode', 'Status'],
            $rows);
    }

    public function reimbursements(\Illuminate\Http\Request $request)
    {
        $rows = $this->travelScope(Reimbursement::with(['travelRequest.user'])->latest(), $request, true)->get()->map(fn ($r) => [
            $r->request_no, $r->travelRequest?->request_no, $r->travelRequest?->user?->name,
            $r->actual_expense, $r->advance, $r->reconciliation, $r->receipt_count, $r->ocr_status, $r->status,
        ]);

        return $this->csv('reimbursement.csv',
            ['No. Klaim', 'Ref TR', 'Pemohon', 'Pengeluaran Riil', 'Kas Bon', 'Rekonsiliasi', 'Jml Resi', 'OCR', 'Status'],
            $rows);
    }

    public function users()
    {
        $rows = User::with('department')->latest()->get()->map(fn ($u) => [
            $u->nik, $u->name, $u->email, $u->department?->name, $u->grade, $u->role, $u->status,
        ]);

        return $this->csv('users.csv',
            ['NIK', 'Nama', 'Email', 'Departemen', 'Grade', 'Role', 'Status'],
            $rows);
    }

    public function departments()
    {
        // withCount('users') — kolom `members` tidak pernah diisi (selalu 0).
        $rows = Department::with('leader')->withCount('users')->latest()->get()->map(fn ($d) => [
            $d->code, $d->name, $d->leader?->name, $d->users_count,
            $d->budget_used, $d->budget_total, $d->sla,
        ]);

        return $this->csv('departments.csv',
            ['Kode', 'Nama', 'Kepala', 'Anggota', 'Terpakai', 'Plafon', 'SLA'],
            $rows);
    }

    public function projects()
    {
        $rows = Project::with('pm')->latest()->get()->map(fn ($p) => [
            $p->code, $p->wbs, $p->name, $p->pm?->name, $p->department,
            $p->location, $p->start_date, $p->end_date, $p->budget_used, $p->budget_total, $p->status,
        ]);

        return $this->csv('projects.csv',
            ['Kode', 'WBS', 'Nama', 'PM', 'Departemen', 'Lokasi', 'Mulai', 'Selesai', 'Terpakai', 'Plafon', 'Status'],
            $rows);
    }

    public function travelPolicies()
    {
        $rows = TravelPolicy::latest()->get()->map(fn ($p) => [
            $p->code, $p->grade, $p->transport_plafon, $p->hotel_plafon,
            $p->per_diem, $p->local_transport, $p->sla, $p->region, $p->enabled ? 'Aktif' : 'Non-aktif',
        ]);

        return $this->csv('travel-policies.csv',
            ['Kode', 'Grade', 'Plafon Transport', 'Plafon Hotel', 'Per Diem', 'Transport Lokal', 'SLA', 'Wilayah', 'Status'],
            $rows);
    }

    public function auditLogs()
    {
        $rows = AuditLog::with('user')->latest()->limit(2000)->get()->map(fn ($a) => [
            $a->created_at?->toDateTimeString(), $a->user?->name, $a->action,
            $a->auditable_type, $a->auditable_id, $a->ip,
        ]);

        return $this->csv('audit-logs.csv',
            ['Waktu', 'Pelaku', 'Aksi', 'Entitas', 'ID Entitas', 'IP'],
            $rows);
    }
}
