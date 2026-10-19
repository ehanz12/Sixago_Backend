<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Department;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\Role;
use App\Models\TravelRequest;
use App\Models\User;
use App\Support\FinanceFlow;
use App\Support\TravelStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alur keputusan finance: uang muka & reimbursement.
 *
 *ptiKLAS masalah: controller menerima aksi apa pun dari status berapa pun, jadi
 * verifikasi klaim yang sudah terverifikasi jadi no-op tak terlihat (tombol
 * "tidak bereaksi") sambil mengirim ulang notifikasi ke pemohon. FE juga
 * menyimpulkan "giliran siapa" dari teks label, bukan dari status.
 */
class FinanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;
    private User $employee;
    private TravelRequest $travel;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => []]);
        }

        $dept = Department::create(['code' => 'CC-F', 'name' => 'Finance', 'budget_total' => 1, 'sla' => 'T']);
        $make = fn (string $e, string $r, string $g) => User::create([
            'name' => $e, 'nik' => 'NIK-'.$e, 'email' => $e, 'password' => Hash::make('password123'),
            'department_id' => $dept->id, 'grade' => $g, 'role' => $r, 'status' => 'Aktif',
        ]);
        $this->finance = $make('fin@x.id', 'Finance Approver', 'M2');
        $this->employee = $make('emp@x.id', 'Employee', 'P4');

        $project = Project::create([
            'code' => 'PRJ-F', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->finance->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1000,
        ]);

        $this->travel = TravelRequest::create([
            'request_no' => 'TR-F-1', 'user_id' => $this->employee->id, 'project_id' => $project->id,
            'route' => 'A → B', 'transport' => 'KA', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-02', 'cost' => 1000000, 'status' => 'Approved',
        ]);
    }

    private function makeReimbursement(string $status): Reimbursement
    {
        return Reimbursement::create([
            'request_no' => 'REIM-'.$status,
            'travel_request_id' => $this->travel->id,
            'actual_expense' => 1000000,
            'advance' => 0,
            'ocr_status' => 'Valid',
            'receipt_count' => 1,
            'status' => $status,
        ]);
    }

    private function makeAdvance(string $status): Advance
    {
        return Advance::create([
            'request_no' => 'ADV-'.$status,
            'travel_request_id' => $this->travel->id,
            'amount' => 500000,
            'plafon' => 1000000,
            'method' => 'Transfer',
            'status' => $status,
        ]);
    }

    // ============ FinanceFlow (unit) ============

    public function test_flow_rejects_invalid_transitions(): void
    {
        // Lompong tahap: settlement hanya sah setelah verify.
        $this->assertNull(FinanceFlow::nextStatus('settlement', TravelStatuses::REIM_AUDIT));
        $this->assertSame(
            TravelStatuses::REIM_VERIFY,
            FinanceFlow::nextStatus('verify', TravelStatuses::REIM_AUDIT)
        );

        // Mundur: klaim yang sudah disettle tak bisa di-verify lagi.
        $this->assertNull(FinanceFlow::nextStatus('verify', TravelStatuses::REIM_APPROVED));
        $this->assertNull(FinanceFlow::nextStatus('verify', TravelStatuses::REIM_VERIFY));
    }

    public function test_flow_flags_per_status(): void
    {
        $audit = FinanceFlow::flagsFor(TravelStatuses::REIM_AUDIT);
        $this->assertTrue($audit['can_verify']);
        $this->assertFalse($audit['can_settle']);

        $verify = FinanceFlow::flagsFor(TravelStatuses::REIM_VERIFY);
        $this->assertFalse($verify['can_verify'], 'Inilah yang dulu bikin tombol tetap muncul');
        $this->assertTrue($verify['can_settle']);

        $settled = FinanceFlow::flagsFor(TravelStatuses::REIM_APPROVED);
        $this->assertFalse($settled['can_verify']);
        $this->assertFalse($settled['can_settle']);
        $this->assertNotNull($settled['block_reason']);

        $ready = FinanceFlow::flagsFor(TravelStatuses::ADV_READY, true);
        $this->assertTrue($ready['can_disburse']);
        $this->assertFalse(FinanceFlow::flagsFor(TravelStatuses::ADV_PENDING, true)['can_disburse']);
    }

    // ============ Reimbursement ============

    public function test_verify_records_who_and_when(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])->assertOk();

        $fresh = $reim->fresh();
        $this->assertSame(TravelStatuses::REIM_VERIFY, $fresh->status);
        $this->assertSame($this->finance->id, (int) $fresh->decided_by);
        $this->assertNotNull($fresh->decided_at);
    }

    public function test_repeated_verify_returns_409_and_sends_no_second_notification(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])->assertOk();

        $notifCount = \DB::table('notifications')
            ->where('user_id', $this->employee->id)
            ->where('reference_id', $reim->request_no)
            ->count();

        // Klik kedua pada tombol yang sama.
        Sanctum::actingAs($this->finance);
        $res = $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])
            ->assertStatus(409);
        $this->assertStringContainsString($this->finance->name, (string) $res->json('message'));

        $this->assertSame(TravelStatuses::REIM_VERIFY, $reim->fresh()->status, 'Status tidak berubah');
        $this->assertSame(
            $notifCount,
            \DB::table('notifications')
                ->where('user_id', $this->employee->id)
                ->where('reference_id', $reim->request_no)
                ->count(),
            'Pemohon tidak boleh dikirimi notifikasi kedua'
        );
    }

    public function test_settlement_before_verify_returns_409(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'settlement'])
            ->assertStatus(409);
    }

    public function test_full_reimbursement_flow_ends_at_settled(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])->assertOk();
        $this->assertSame(TravelStatuses::REIM_VERIFY, $reim->fresh()->status);

        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'settlement'])->assertOk();
        $this->assertSame(TravelStatuses::REIM_APPROVED, $reim->fresh()->status);

        // Terminal: tidak ada aksi lagi.
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])->assertStatus(409);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'settlement'])->assertStatus(409);
    }

    public function test_reimbursement_index_exposes_flow_flags_and_decider(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_VERIFY);
        $reim->update(['decided_by' => $this->finance->id, 'decided_at' => now()]);

        Sanctum::actingAs($this->finance);
        $row = collect($this->getJson('/api/reimbursements')->assertOk()->json('data'))
            ->firstWhere('id', $reim->id);

        $this->assertFalse($row['can_verify'], 'Sudah diverifikasi → tombol Verifikasi harus hilang');
        $this->assertTrue($row['can_settle']);
        $this->assertSame($this->finance->name, $row['decided_by']['name']);
        $this->assertNotNull($row['decided_at']);
    }

    public function test_reimbursement_reject_requires_note_and_records_decider(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_VERIFY);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'reject'])
            ->assertStatus(422);

        $this->postJson("/api/reimbursements/{$reim->id}/verify", [
            'action' => 'reject', 'note' => 'Nota kurang lengkap',
        ])->assertOk();

        $this->assertSame(TravelStatuses::REIM_REJECTED, $reim->fresh()->status);
        $this->assertSame($this->finance->id, (int) $reim->fresh()->decided_by);
    }

    // ============ Advance ============

    public function test_advance_approve_twice_returns_409(): void
    {
        $adv = $this->makeAdvance(TravelStatuses::ADV_PENDING);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/advances/{$adv->id}/decide", ['action' => 'approve'])->assertOk();
        $this->postJson("/api/advances/{$adv->id}/decide", ['action' => 'approve'])
            ->assertStatus(409);

        $this->assertSame(TravelStatuses::ADV_READY, $adv->fresh()->status);
        $this->assertSame($this->finance->id, (int) $adv->fresh()->decided_by);
    }

    public function test_advance_disburse_before_approve_returns_409(): void
    {
        $adv = $this->makeAdvance(TravelStatuses::ADV_PENDING);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/advances/{$adv->id}/disburse", ['transfer_ref' => 'TRX-1'])
            ->assertStatus(409);
        $this->assertSame(TravelStatuses::ADV_PENDING, $adv->fresh()->status);
    }

    public function test_advance_disburse_twice_returns_409(): void
    {
        $adv = $this->makeAdvance(TravelStatuses::ADV_READY);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/advances/{$adv->id}/disburse", ['transfer_ref' => 'TRX-1'])->assertOk();
        $this->postJson("/api/advances/{$adv->id}/disburse", ['transfer_ref' => 'TRX-2'])
            ->assertStatus(409);

        $fresh = $adv->fresh();
        $this->assertSame(TravelStatuses::ADV_PAID, $fresh->status);
        $this->assertSame('TRX-1', $fresh->transfer_ref, 'Nomor transfer pertama tidak boleh tertimpa');
        $this->assertSame($this->finance->id, (int) $fresh->decided_by);
    }

    public function test_advance_index_exposes_flow_flags_and_decider(): void
    {
        $adv = $this->makeAdvance(TravelStatuses::ADV_READY);

        Sanctum::actingAs($this->finance);
        $row = collect($this->getJson('/api/advances')->assertOk()->json('data'))
            ->firstWhere('id', $adv->id);

        $this->assertTrue($row['can_disburse']);
        $this->assertFalse($row['can_decide']);

        $adv->update(['status' => TravelStatuses::ADV_PAID, 'decided_by' => $this->finance->id, 'decided_at' => now()]);
        $row2 = collect($this->getJson('/api/advances')->assertOk()->json('data'))
            ->firstWhere('id', $adv->id);
        $this->assertFalse($row2['can_disburse'], 'Sudah dicairkan → tombol Disburse harus hilang');
        $this->assertNotNull($row2['block_reason']);
    }

    public function test_finance_only_can_act(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])
            ->assertForbidden();
        $this->assertSame(TravelStatuses::REIM_AUDIT, $reim->fresh()->status);
    }
}