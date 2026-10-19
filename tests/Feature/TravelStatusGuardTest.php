<?php

namespace Tests\Feature;

use App\Models\ApprovalQueue;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\TravelPolicy;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aturan status travel request:
 * - Pemohon (dan siapa pun) tidak bisa mengubah status via PUT, kecuali
 *   penarikan pra-approval ('Cancelled' saat Submitted + nol baris decided).
 * - policy_status milik sistem. Edit field dihitung ulang badgenya.
 * - Reject approver via decide() tidak tersentuh aturan ini.
 */
class TravelStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private User $lineManager;
    private User $divisionHead;
    private User $sa;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => []]);
        }

        $dept = Department::create(['code' => 'CC-TECH-502', 'name' => 'IT', 'budget_total' => 1000000, 'sla' => 'Tier 1']);

        $make = fn (string $email, string $role, ?int $managerId = null) => User::create([
            'name' => $email, 'nik' => 'NIK-'.$email, 'email' => $email,
            'password' => Hash::make('password123'), 'department_id' => $dept->id,
            'grade' => 'P4', 'role' => $role, 'status' => 'Aktif', 'manager_id' => $managerId,
        ]);

        $this->sa = $make('sa@x.id', 'Super Admin');
        $this->lineManager = $make('lm@x.id', 'Line Manager');
        $this->employee = $make('emp@x.id', 'Employee', $this->lineManager->id);
        $this->divisionHead = $make('dh@x.id', 'Division Head');
        $dept->update(['leader_id' => $this->divisionHead->id]);

        $this->project = Project::create([
            'code' => 'PRJ-1', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->lineManager->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1000000,
        ]);

        TravelPolicy::create([
            'code' => 'POL-1', 'grade' => 'P3 - P5',
            'transport_plafon' => 'Maks Rp 4.500.000', 'hotel_plafon' => 'Maks Rp 1.100.000 / malam',
            'per_diem' => 'Rp 450.000', 'local_transport' => 'Maks Rp 200.000 / hari',
            'sla' => '3 Hari Kerja', 'region' => 'Domestik', 'enabled' => true,
        ]);
    }

    private function makeTr(int $cost = 3000000, string $status = 'Submitted'): TravelRequest
    {
        $tr = TravelRequest::create([
            'request_no' => 'TR-'.random_int(100, 999999), 'user_id' => $this->employee->id,
            'project_id' => $this->project->id, 'route' => 'A → B', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => $cost,
            'status' => $status,
        ]);
        ApprovalQueue::create([
            'travel_request_id' => $tr->id, 'type' => 'Travel', 'level' => '1 of 1',
            'status' => 'pending', 'amount' => $cost,
        ]);

        return $tr;
    }

    public function test_owner_cannot_put_rejected_even_pre_approval(): void
    {
        $tr = $this->makeTr();

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['status' => 'Rejected'])
            ->assertForbidden();
        $this->assertSame('Submitted', $tr->fresh()->status);
    }

    public function test_owner_cannot_self_approve_via_put(): void
    {
        $tr = $this->makeTr();

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['status' => 'Approved'])
            ->assertForbidden();
        $this->assertSame('Submitted', $tr->fresh()->status);
    }

    public function test_sa_cannot_put_rejected_either(): void
    {
        $tr = $this->makeTr();

        Sanctum::actingAs($this->sa);
        $this->putJson("/api/travel-requests/{$tr->id}", ['status' => 'Rejected'])
            ->assertForbidden();
    }

    public function test_owner_cannot_write_policy_status(): void
    {
        $tr = $this->makeTr();

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['policy_status' => 'Sesuai'])
            ->assertForbidden();
        Sanctum::actingAs($this->sa);
        $this->putJson("/api/travel-requests/{$tr->id}", ['policy_status' => 'Sesuai'])
            ->assertForbidden();
    }

    public function test_owner_can_cancel_pre_approval_and_queue_freezes(): void
    {
        $tr = $this->makeTr();

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['status' => 'Cancelled'])
            ->assertOk();

        $this->assertSame('Cancelled', $tr->fresh()->status);
        $this->assertSame(
            0,
            ApprovalQueue::where('travel_request_id', $tr->id)->whereIn('status', ['pending', 'queued'])->count()
        );
    }

    public function test_owner_cannot_cancel_after_l1_approval(): void
    {
        $tr = $this->makeTr();
        $q = ApprovalQueue::where('travel_request_id', $tr->id)->firstOrFail();

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$q->id}/decide", ['action' => 'approve'])->assertOk();

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['status' => 'Cancelled'])
            ->assertStatus(409);
        // Rantai satu level (L1) → approval mengakhiri TR sebagai Approved.
        $this->assertSame('Approved', $tr->fresh()->status);
    }

    public function test_sa_can_cancel_pre_approval_for_others(): void
    {
        $tr = $this->makeTr();

        Sanctum::actingAs($this->sa);
        $this->putJson("/api/travel-requests/{$tr->id}", ['status' => 'Cancelled'])
            ->assertOk();
        $this->assertSame('Cancelled', $tr->fresh()->status);
    }

    public function test_edit_cost_within_plafon_recomputes_policy_badge(): void
    {
        // 3 hari: total plafon 8,65jt. Edit tetap di dalam plafon → badge Sesuai.
        $tr = $this->makeTr(3000000);

        Sanctum::actingAs($this->employee);
        $res = $this->putJson("/api/travel-requests/{$tr->id}", ['cost' => 8000000])
            ->assertOk()->json();

        $this->assertSame('Sesuai', $res['policy_status']);
    }

    public function test_edit_cost_over_plafon_rejected(): void
    {
        // Melebihi plafon policy → 422 dan biaya lama tidak berubah.
        $tr = $this->makeTr(3000000);

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['cost' => 20000000])
            ->assertStatus(422)->assertJsonValidationErrors('cost');

        $this->assertSame(3000000.0, (float) $tr->fresh()->cost);
    }

    public function test_finalized_badge_frozen_on_late_edit(): void
    {
        $tr = $this->makeTr(3000000);
        $tr->update(['status' => 'Approved', 'policy_status' => 'Policy Approved']);

        Sanctum::actingAs($this->employee);
        $res = $this->putJson("/api/travel-requests/{$tr->id}", ['cost' => 8000000])
            ->assertOk()->json();

        $this->assertSame('Policy Approved', $res['policy_status']);
    }

    public function test_owner_can_delete_draft_without_rows(): void
    {
        $tr = TravelRequest::create([
            'request_no' => 'TR-DRAFT', 'user_id' => $this->employee->id,
            'project_id' => $this->project->id, 'route' => 'A → B', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 100000,
            'status' => 'Draft',
        ]);

        Sanctum::actingAs($this->employee);
        $this->deleteJson("/api/travel-requests/{$tr->id}")->assertNoContent();
        $this->assertDatabaseMissing('travel_requests', ['id' => $tr->id]);
    }

    public function test_reject_requires_note_on_queue_decide(): void
    {
        $tr = $this->makeTr();
        $q = ApprovalQueue::where('travel_request_id', $tr->id)->firstOrFail();

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$q->id}/decide", ['action' => 'reject'])
            ->assertStatus(422);
        $this->postJson("/api/approval-queue/{$q->id}/decide", ['action' => 'reject', 'note' => 'AB'])
            ->assertStatus(422);
        $this->postJson("/api/approval-queue/{$q->id}/decide", ['action' => 'reject', 'note' => 'Biaya kemahalan'])
            ->assertOk();
        $this->assertSame('Rejected', $tr->fresh()->status);
    }

    public function test_reject_requires_note_on_advance_decide(): void
    {
        $tr = $this->makeTr();
        $adv = \App\Models\Advance::create([
            'travel_request_id' => $tr->id, 'request_no' => 'ADV-NOTE',
            'amount' => 500000, 'method' => 'Bank Transfer', 'status' => 'Pending Finance',
        ]);

        Sanctum::actingAs($this->sa);
        $this->postJson("/api/advances/{$adv->id}/decide", ['action' => 'reject'])
            ->assertStatus(422);
        $this->postJson("/api/advances/{$adv->id}/decide", ['action' => 'reject', 'note' => 'Dana tidak tersedia'])
            ->assertOk();
    }

    public function test_reject_requires_note_on_reimbursement_verify(): void
    {
        $tr = $this->makeTr();
        $reim = \App\Models\Reimbursement::create([
            'travel_request_id' => $tr->id, 'request_no' => 'REIM-NOTE',
            'actual_expense' => 1000000, 'advance' => 0, 'ocr_status' => 'Valid',
            'status' => 'Menunggu Audit',
        ]);

        Sanctum::actingAs($this->sa);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'reject'])
            ->assertStatus(422);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'reject', 'note' => 'Nota tidak valid'])
            ->assertOk();
    }

    public function test_field_edit_records_audit_trail(): void
    {
        $tr = $this->makeTr(3000000);

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/travel-requests/{$tr->id}", ['route' => 'JKT → DPS'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'travel.update']);
    }

    public function test_approver_reject_after_l1_still_works_via_decide(): void
    {
        // L1 setuju lalu L2 menolak via decide() — perilaku sah, tak tersentuh guard PUT.
        $tr = TravelRequest::create([
            'request_no' => 'TR-L2', 'user_id' => $this->employee->id,
            'project_id' => $this->project->id, 'route' => 'A → B', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 9000000,
            'status' => 'In Review',
        ]);
        $q1 = ApprovalQueue::create([
            'travel_request_id' => $tr->id, 'type' => 'Travel', 'level' => '1 of 2',
            'status' => 'pending', 'amount' => 9000000,
        ]);
        $q2 = ApprovalQueue::create([
            'travel_request_id' => $tr->id, 'type' => 'Travel', 'level' => '2 of 2',
            'status' => 'pending', 'amount' => 9000000,
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$q1->id}/decide", ['action' => 'approve'])->assertOk();
        Sanctum::actingAs($this->divisionHead);
        $this->postJson("/api/approval-queue/{$q2->id}/decide", ['action' => 'reject', 'note' => 'Biaya kemahalan'])
            ->assertOk();

        $this->assertSame('Rejected', $tr->fresh()->status);
    }
}
