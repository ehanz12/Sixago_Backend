<?php

namespace Tests\Feature;

use App\Models\ApprovalQueue;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Role;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $sa;
    private User $employee;
    private User $lineManager;
    private User $divisionHead;
    private User $finance;
    private TravelRequest $tr;
    private ApprovalQueue $q1;
    private ApprovalQueue $q2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => $name === 'Super Admin' ? ['*'] : []]);
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
        $this->finance = $make('fin@x.id', 'Finance Approver');

        $project = Project::create([
            'code' => 'PRJ-1', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->lineManager->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1000000,
        ]);

        $this->tr = TravelRequest::create([
            'request_no' => 'TR-1', 'user_id' => $this->employee->id, 'project_id' => $project->id,
            'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-03', 'cost' => 1000000, 'status' => 'In Review',
            'policy_status' => 'In Review (L1)',
        ]);

        $this->q1 = ApprovalQueue::create([
            'travel_request_id' => $this->tr->id, 'type' => 'Travel', 'level' => '1 of 3',
            'status' => 'pending', 'amount' => 1000000,
        ]);
        $this->q2 = ApprovalQueue::create([
            'travel_request_id' => $this->tr->id, 'type' => 'Travel', 'level' => '2 of 3',
            'status' => 'pending', 'amount' => 1000000,
        ]);
    }

    public function test_employee_cannot_decide(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson("/api/approval-queue/{$this->q1->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
    }

    public function test_line_manager_can_decide_level1_but_not_level2(): void
    {
        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$this->q1->id}/decide", ['action' => 'approve'])
            ->assertOk();
        $this->postJson("/api/approval-queue/{$this->q2->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
    }

    public function test_decide_updates_status_and_policy_status_and_audit(): void
    {
        Sanctum::actingAs($this->divisionHead);
        $this->postJson("/api/approval-queue/{$this->q2->id}/decide", ['action' => 'approve'])
            ->assertOk();

        $this->assertDatabaseHas('travel_requests', [
            'id' => $this->tr->id, 'status' => 'Approved', 'policy_status' => 'Policy Approved',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'travel.approve']);
    }

    public function test_disburse_requires_ref_and_finance_role(): void
    {
        $adv = \App\Models\Advance::create([
            'travel_request_id' => $this->tr->id, 'request_no' => 'ADV-1',
            'amount' => 500000, 'method' => 'Bank Transfer', 'status' => 'Approved-Ready to Pay',
        ]);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/advances/{$adv->id}/disburse", ['transfer_ref' => 'X'])->assertForbidden();

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/advances/{$adv->id}/disburse", [])->assertStatus(422);
        $this->postJson("/api/advances/{$adv->id}/disburse", ['transfer_ref' => 'TRX-1'])->assertOk();
        $this->assertDatabaseHas('advances', ['id' => $adv->id, 'status' => 'Paid', 'transfer_ref' => 'TRX-1']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->employee->update(['status' => 'Non-Aktif']);
        $this->postJson('/api/login', ['corporateId' => $this->employee->email, 'password' => 'password123'])
            ->assertForbidden();
    }

    public function test_must_change_password_flow(): void
    {
        $this->employee->update(['must_change_password' => true]);
        $res = $this->postJson('/api/login', ['corporateId' => $this->employee->email, 'password' => 'password123'])
            ->assertOk();
        $this->assertTrue($res->json('must_change_password'));

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/user/password', [
            'current_password' => 'password123',
            'password' => 'baru12345',
            'password_confirmation' => 'baru12345',
        ])->assertOk();
        $this->assertFalse((bool) $this->employee->fresh()->must_change_password);
    }

    public function test_sla_escalate_marks_overdue(): void
    {
        $this->q1->update(['sla_due_at' => now()->subHour()]);
        $this->artisan('app:sla-escalate')->assertSuccessful();
        $this->assertTrue($this->q1->fresh()->urgent);
        $this->assertTrue($this->q1->fresh()->escalated);
    }

    /**
     * Endpoint antrian harus memberi tahu per baris "giliran siapa".
     *
     * Cache ini yang membuat FE menampilkan tombol Setujui pada baris milik
     * approver lain (dulu gate hanya cek role → klik → 403).
     */
    private function queueRowFor(User $as, ApprovalQueue $q): array
    {
        Sanctum::actingAs($as);
        $rows = $this->getJson('/api/approval-queue?status=pending&per_page=50')
            ->assertOk()->json('data');

        foreach ($rows as $r) {
            if ((int) $r['id'] === $q->id) {
                return $r;
            }
        }
        $this->fail('Baris antrean tidak ditemukan di respons.');
    }

    public function test_line_manager_cannot_decide_level2_row_and_gets_block_reason(): void
    {
        $row = $this->queueRowFor($this->lineManager, $this->q2);

        $this->assertFalse($row['can_decide']);
        $this->assertStringContainsString('bukan wewenang role Anda', (string) $row['block_reason']);
    }

    public function test_designated_approver_can_decide_the_row(): void
    {
        $row = $this->queueRowFor($this->divisionHead, $this->q2);

        $this->assertTrue($row['can_decide']);
        $this->assertNull($row['block_reason']);
    }

    public function test_queue_row_reports_decided_by_me_and_no_longer_decidable(): void
    {
        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$this->q1->id}/decide", ['action' => 'approve'])
            ->assertOk();

        // Baris L1 yang sudah ia putuskan: bukan giliran lagi, tapi ditandai.
        Sanctum::actingAs($this->lineManager);
        $rows = $this->getJson('/api/approval-queue?status=approved&per_page=50')
            ->assertOk()->json('data');
        $mine = collect($rows)->firstWhere('id', $this->q1->id);

        $this->assertNotNull($mine);
        $this->assertTrue($mine['decided_by_me']);
        $this->assertSame('approved', $mine['my_decision']);
        $this->assertFalse($mine['can_decide']);
    }

    public function test_waiting_for_names_the_next_approver(): void
    {
        $this->q2->update(['approver_id' => $this->divisionHead->id]);

        $row = $this->queueRowFor($this->lineManager, $this->q2);

        $this->assertFalse($row['can_decide']);
        $this->assertSame($this->divisionHead->name, $row['waiting_for']);
    }

    public function test_delegatee_can_decide_row_flagged_for_plt(): void
    {
        // LM jadi Plt Kepala Divisi → mustahil dia tidak bisa memutus L2.
        $this->q2->update(['approver_id' => $this->divisionHead->id]);
        \App\Models\Delegation::create([
            'delegator_id' => $this->divisionHead->id,
            'delegatee_id' => $this->lineManager->id,
            'scope' => 'Penuh (Full Approval)',
            'status' => 'Aktif',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        $row = $this->queueRowFor($this->lineManager, $this->q2);

        $this->assertTrue($row['can_decide']);
    }

    public function test_travel_detail_exposes_same_decision_metadata(): void
    {
        $this->q2->update(['approver_id' => $this->divisionHead->id]);

        Sanctum::actingAs($this->lineManager);
        $approvals = $this->getJson('/api/travel-requests/no/TR-1')->assertOk()->json('approvals');

        $l2 = collect($approvals)->firstWhere('level', '2 of 3');
        $this->assertNotNull($l2);
        $this->assertFalse($l2['can_decide']);
        $this->assertSame($this->divisionHead->name, $l2['waiting_for']);
    }

    public function test_delegatee_can_decide_as_plt_with_audit_trail(): void
    {
        // DH mendelegasikan ke LM (Plt) dengan scope penuh
        \App\Models\Delegation::create([
            'delegator_id' => $this->divisionHead->id,
            'delegatee_id' => $this->lineManager->id,
            'scope' => 'Penuh (Full Approval)',
            'status' => 'Aktif',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$this->q2->id}/decide", ['action' => 'approve'])
            ->assertOk();

        $this->assertDatabaseHas('approval_queues', [
            'id' => $this->q2->id,
            'status' => 'approved',
            'decided_by' => $this->lineManager->id,
            'on_behalf_of' => $this->divisionHead->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'travel.approve']);
    }

    public function test_delegatee_scope_with_legacy_full_string_is_accepted(): void
    {
        // String scope lama ('Full - ...', mis. dari seed lawas) tetap berarti
        // Full Approval di backend — frontend mencerminkannya via isFullScope().
        \App\Models\Delegation::create([
            'delegator_id' => $this->divisionHead->id,
            'delegatee_id' => $this->lineManager->id,
            'scope' => 'Full - Semua Approval',
            'status' => 'Aktif',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$this->q2->id}/decide", ['action' => 'approve'])
            ->assertOk();

        $this->assertDatabaseHas('approval_queues', [
            'id' => $this->q2->id,
            'status' => 'approved',
            'decided_by' => $this->lineManager->id,
            'on_behalf_of' => $this->divisionHead->id,
        ]);
    }

    public function test_delegation_respects_max_amount(): void
    {
        \App\Models\Delegation::create([
            'delegator_id' => $this->divisionHead->id,
            'delegatee_id' => $this->lineManager->id,
            'scope' => 'Penuh (Full Approval)',
            'max_amount' => 100, // di bawah nominal antrean (1.000.000)
            'status' => 'Aktif',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$this->q2->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
    }

    public function test_self_approval_is_blocked(): void
    {
        // LM mengajukan untuk dirinya sendiri lalu mencoba approve level 1
        $own = TravelRequest::create([
            'request_no' => 'TR-OWN', 'user_id' => $this->lineManager->id,
            'project_id' => $this->tr->project_id, 'route' => 'X → Y',
            'transport' => 'Pesawat', 'start_date' => '2025-03-01', 'end_date' => '2025-03-02',
            'cost' => 500000, 'status' => 'In Review',
        ]);
        $q = ApprovalQueue::create([
            'travel_request_id' => $own->id, 'type' => 'Travel', 'level' => '1 of 3',
            'status' => 'pending', 'amount' => 500000,
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$q->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
    }

    public function test_destroy_with_children_returns_409(): void
    {
        Sanctum::actingAs($this->sa);
        $this->deleteJson("/api/travel-requests/{$this->tr->id}")->assertStatus(409);
        $deptId = $this->employee->department_id;
        $this->deleteJson("/api/departments/{$deptId}")->assertStatus(409);
        $this->deleteJson("/api/users/{$this->employee->id}")->assertStatus(409);
    }

    public function test_store_builds_chain_by_threshold(): void
    {
        // Departemen tanpa leader → Level 2 dilewati (tidak ada approver struktural
        // yang bisa memutus dalam jangkauan). Rantai tetap tidak kosong karena
        // level pool (Finance/CFO) ikut dipakai — lihat test di bawahnya.
        $this->employee->department->update(['leader_id' => $this->divisionHead->id]);

        Sanctum::actingAs($this->employee);

        $post = fn (int $cost) => $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->tr->project_id,
            'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-03', 'cost' => $cost,
        ])->assertCreated()->json('request_no');

        $t1 = TravelRequest::where('request_no', $post(1000000))->first();
        $this->assertNotNull($t1);
        $rows1 = ApprovalQueue::where('travel_request_id', $t1->id)->orderBy('sequence')->get();
        $this->assertCount(1, $rows1);
        $this->assertSame('pending', $rows1[0]->status);
        $this->assertSame('1 of 1', $rows1[0]->level);
        $this->assertSame($this->lineManager->id, (int) $rows1[0]->approver_id);

        $t2 = TravelRequest::where('request_no', $post(9450000))->first();
        $rows2 = ApprovalQueue::where('travel_request_id', $t2->id)->orderBy('sequence')->get();
        $this->assertCount(2, $rows2);
        $this->assertSame('2 of 2', $rows2[1]->level);
        $this->assertSame('queued', $rows2[1]->status);

        $t3 = TravelRequest::where('request_no', $post(20000000))->first();
        $this->assertCount(3, ApprovalQueue::where('travel_request_id', $t3->id)->get());

        $t4 = TravelRequest::where('request_no', $post(60000000))->first();
        $this->assertCount(4, ApprovalQueue::where('travel_request_id', $t4->id)->get());
    }

    public function test_sequential_approve_advances_chain_then_finalizes(): void
    {
        $this->employee->department->update(['leader_id' => $this->divisionHead->id]);

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->tr->project_id,
            'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-03', 'cost' => 9450000,
        ])->assertCreated();
        $tr = TravelRequest::latest('id')->first();
        [$l1, $l2] = ApprovalQueue::where('travel_request_id', $tr->id)->orderBy('sequence')->get()->all();

        // DH tidak bisa mendahului (baris masih queued)
        Sanctum::actingAs($this->divisionHead);
        $this->postJson("/api/approval-queue/{$l2->id}/decide", ['action' => 'approve'])
            ->assertStatus(409);

        // LM setujui L1 → TR tetap In Review, L2 aktif
        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$l1->id}/decide", ['action' => 'approve'])
            ->assertOk();
        $this->assertSame('In Review', $tr->fresh()->status);
        $this->assertSame('pending', $l2->fresh()->status);

        // DH setujui L2 → final + baris Auto-Ticketing
        Sanctum::actingAs($this->divisionHead);
        $this->postJson("/api/approval-queue/{$l2->id}/decide", ['action' => 'approve'])
            ->assertOk();
        $this->assertSame('Approved', $tr->fresh()->status);
        $this->assertDatabaseHas('approval_queues', [
            'travel_request_id' => $tr->id, 'level' => 'Auto', 'status' => 'approved',
        ]);
    }

    public function test_reject_stops_chain_and_cancels_queued(): void
    {
        $this->employee->department->update(['leader_id' => $this->divisionHead->id]);

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->tr->project_id,
            'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-03', 'cost' => 9450000,
        ])->assertCreated();
        $tr = TravelRequest::latest('id')->first();
        $l1 = ApprovalQueue::where('travel_request_id', $tr->id)->orderBy('sequence')->first();

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$l1->id}/decide", ['action' => 'reject', 'note' => 'Biaya kemahalan'])
            ->assertOk();

        $this->assertSame('Rejected', $tr->fresh()->status);
        $this->assertSame(0, ApprovalQueue::where('travel_request_id', $tr->id)->where('status', 'queued')->count());
        $this->assertSame(1, ApprovalQueue::where('travel_request_id', $tr->id)->where('status', 'cancelled')->count());
    }

    public function test_structural_approver_enforced(): void
    {
        // LM lain (bukan atasan pemohon) tidak boleh memutus L1 yang dituju ke LM resmi
        $stranger = User::create([
            'name' => 'lm2', 'nik' => 'NIK-lm2', 'email' => 'lm2@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'P4', 'role' => 'Line Manager', 'status' => 'Aktif',
        ]);

        // Tandai q1 dituju ke LM resmi (baris chain baru selalu punya approver_id)
        $this->q1->update(['approver_id' => $this->lineManager->id]);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/approval-queue/{$this->q1->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
    }

/**
 * Pemohon yang kebetulan adalah approver strukturalnya sendiri tidak boleh
 * menyangkut: level itu dilewati, dan rantai jatuh ke pool cadangan.
 */
public function test_division_head_requester_skips_own_level_and_falls_back(): void
{
    $dhUser = $this->divisionHead;
    $dhUser->update(['manager_id' => null]);
    $dhUser->department->update(['leader_id' => $dhUser->id]); // dia leader dirinya sendiri
    $dhUser->update(['grade' => 'M3']);

    Sanctum::actingAs($dhUser);
    $this->postJson('/api/travel-requests', [
        'user_id' => $dhUser->id, 'project_id' => $this->tr->project_id,
        'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
        'end_date' => '2025-02-03', 'cost' => 9450000,
    ])->assertCreated();

    $tr = TravelRequest::latest('id')->first();
    $rows = ApprovalQueue::where('travel_request_id', $tr->id)->get();

    $this->assertCount(1, $rows, 'L1 & L2 dilewati, sisanya pool cadangan');
    $this->assertSame('3 (Cadangan Finance)', $rows[0]->level);
    $this->assertSame('pending', $rows[0]->status);

    // Finance boleh memutus; tidak boleh pemohon sendiri (dia bukan Finance).
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/approval-queue/{$rows[0]->id}/decide", ['action' => 'approve'])->assertOk();
    $this->assertSame('Approved', $tr->fresh()->status);
}

/**
 * Pemohon yang ikut pool Finance: level Finance dilewati supaya tidak macet
 * (approve sendiri ditolak decide()), lalu cadangan Super Admin dipakai.
 */
public function test_finance_requester_falls_back_to_super_admin_pool(): void
{
    $fin = $this->finance;
    $fin->update(['manager_id' => null, 'grade' => 'M2']);
    $fin->department->update(['leader_id' => null]); // tidak ada HOD

    Sanctum::actingAs($fin);
    $this->postJson('/api/travel-requests', [
        'user_id' => $fin->id, 'project_id' => $this->tr->project_id,
        'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
        'end_date' => '2025-02-03', 'cost' => 20000000,
    ])->assertCreated();

    $tr = TravelRequest::latest('id')->first();
    $rows = ApprovalQueue::where('travel_request_id', $tr->id)->get();

    // 20jt → L1, L2, L3. L1 & L2 tanpa approver, L3 = pool Finance (pemohon ikut pool).
    $this->assertCount(1, $rows);
    $this->assertSame('4 (Cadangan Super Admin)', $rows[0]->level);

    // Pool Finance tidak boleh memutus requestnya sendiri.
    Sanctum::actingAs($fin);
    $this->postJson("/api/approval-queue/{$rows[0]->id}/decide", ['action' => 'approve'])
        ->assertStatus(403);

    Sanctum::actingAs($this->sa);
    $this->postJson("/api/approval-queue/{$rows[0]->id}/decide", ['action' => 'approve'])->assertOk();
    $this->assertSame('Approved', $tr->fresh()->status);
}

/** Orang yang sama tidak boleh occupy dua level berturut-turut. */
public function test_same_person_is_not_assigned_to_two_consecutive_levels(): void
{
    // Line Manager dengan atasan langsung = Kepala Divisi → L1 & L2 orang sama.
    $this->lineManager->update(['manager_id' => $this->divisionHead->id]);
    $this->employee->department->update(['leader_id' => $this->divisionHead->id]);

    Sanctum::actingAs($this->employee);
    $this->postJson('/api/travel-requests', [
        'user_id' => $this->employee->id, 'project_id' => $this->tr->project_id,
        'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
        'end_date' => '2025-02-03', 'cost' => 9450000,
    ])->assertCreated();

    $tr = TravelRequest::latest('id')->first();
    $rows = ApprovalQueue::where('travel_request_id', $tr->id)->orderBy('sequence')->get();

    // L1 = atasan (Line Manager). L2 = HOD. Dua orang berbeda → keduanya kept.
    $this->assertCount(2, $rows);
    $this->assertSame('1 of 2', $rows[0]->level);
    $this->assertSame('2 of 2', $rows[1]->level);

    // Sekarang paksa L1 = L2 (atasan employee = HOD langsung).
    $this->employee->update(['manager_id' => $this->divisionHead->id]);
    Sanctum::actingAs($this->employee);
    $this->postJson('/api/travel-requests', [
        'user_id' => $this->employee->id, 'project_id' => $this->tr->project_id,
        'route' => 'C → D', 'transport' => 'KA', 'start_date' => '2025-03-01',
        'end_date' => '2025-03-03', 'cost' => 9450000,
    ])->assertCreated();

    $tr2 = TravelRequest::latest('id')->first();
    $rows2 = ApprovalQueue::where('travel_request_id', $tr2->id)->get();
    $this->assertCount(1, $rows2, 'Level kedua dilewati karena approvernya sama');
    $this->assertSame('1 of 1', $rows2[0]->level);
    $this->assertSame($this->divisionHead->id, (int) $rows2[0]->approver_id);
}

/**
 * Tanpa approver struktural, rantai TIDAK boleh kosong — jatuh ke pool
     * Finance (Level 3) di semua nominal.
     *
     * Behaviour lama: 0 baris antrean + TR langsung Approved otomatis, tanpa
     * pernah dilihat manusia. Itu lubang_controls, sekarang tertutup.
     */
    public function test_missing_approver_falls_back_to_finance_pool(): void
    {
        $loner = User::create([
            'name' => 'loner', 'nik' => 'NIK-loner', 'email' => 'loner@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'P1', 'role' => 'Employee', 'status' => 'Aktif', 'manager_id' => null,
        ]);

        Sanctum::actingAs($loner);
        $this->postJson('/api/travel-requests', [
            'user_id' => $loner->id, 'project_id' => $this->tr->project_id,
            'route' => 'A → B', 'transport' => 'KA', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-02', 'cost' => 500000,
        ])->assertCreated();

        $tr = TravelRequest::latest('id')->first();
        $this->assertSame('Submitted', $tr->status, 'Tidak boleh auto-approved tanpa manusia');

        $rows = ApprovalQueue::where('travel_request_id', $tr->id)->get();
        $this->assertCount(1, $rows);
        // Angka di depan WAJIB: User::canDecideOwn mem-parse level dari string ini.
        $this->assertSame('3 (Cadangan Finance)', $rows[0]->level);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertNull($rows[0]->approver_id, 'Pool Finance tidak punya approver_id');

        // Finance yang berwenang solace bisa memutus.
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/approval-queue/{$rows[0]->id}/decide", ['action' => 'approve'])
            ->assertOk();
        $this->assertSame('Approved', $tr->fresh()->status);

        // Super Admin diberi tahu ada level yang dilewati otomatis.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->sa->id,
            'reference_id' => $tr->request_no,
            'title' => 'Ada level approval yang dilewati otomatis: '.$tr->request_no,
        ]);
    }

    public function test_simulate_matches_execution_rules(): void
    {
        Sanctum::actingAs($this->sa);
        $res = $this->postJson('/api/approval-simulate', ['amount' => 9450000])->assertOk()->json();
        $this->assertSame(['Level 1 - Line Manager', 'Level 2 - Kepala Divisi (HOD)', 'Level 5 - Auto-Ticketing'], $res['steps']);
        $this->assertSame(3, $res['total_levels']);

        $res = $this->postJson('/api/approval-simulate', ['amount' => 1000000])->assertOk()->json();
        $this->assertSame(['Level 1 - Line Manager', 'Level 5 - Auto-Ticketing'], $res['steps']);
    }

    public function test_stats_are_scoped_per_role(): void
    {
        Sanctum::actingAs($this->employee);
        $empStats = $this->getJson('/api/dashboard/stats')->assertOk()->json();
        $this->assertSame(1, $empStats['total_requests']);

        $other = User::create([
            'name' => 'other', 'nik' => 'NIK-other', 'email' => 'other@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'P1', 'role' => 'Employee', 'status' => 'Aktif',
        ]);
        TravelRequest::create([
            'request_no' => 'TR-OTHER', 'user_id' => $other->id, 'project_id' => $this->tr->project_id,
            'route' => 'C → D', 'transport' => 'KA', 'start_date' => '2025-04-01',
            'end_date' => '2025-04-02', 'cost' => 2000000, 'status' => 'Submitted',
        ]);

        $empStats = $this->getJson('/api/dashboard/stats')->assertOk()->json();
        $this->assertSame(1, $empStats['total_requests']);

        Sanctum::actingAs($this->sa);
        $saStats = $this->getJson('/api/dashboard/stats')->assertOk()->json();
        $this->assertSame(2, $saStats['total_requests']);
    }

    // ---- Cakupan endpoint yang sebelumnya terekspos ke semua user terotentikasi ----

    public function test_notification_update_is_owner_or_super_admin_only(): void
    {
        $notif = Notification::create([
            'user_id' => $this->employee->id, 'title' => 'Rahasia', 'message' => 'Isi',
            'type' => 'Approval', 'category' => 'approval', 'read' => false,
        ]);

        // Pemilik boleh mengubah notifikasi sendiri.
        Sanctum::actingAs($this->employee);
        $this->patchJson("/api/notifications/{$notif->id}", ['read' => true])->assertOk();
        $this->assertTrue($notif->fresh()->read);

        // Orang lain tidak boleh — termasuk user ber-role lain.
        $notif->fresh()->update(['read' => false]);
        $this->assertFalse($notif->fresh()->read);
        Sanctum::actingAs($this->lineManager);
        $this->patchJson("/api/notifications/{$notif->id}", ['read' => true])->assertForbidden();
        $this->assertFalse($notif->fresh()->read);

        // Super Admin tetap boleh.
        Sanctum::actingAs($this->sa);
        $this->patchJson("/api/notifications/{$notif->id}", ['read' => true])->assertOk();
    }

    public function test_approval_queue_write_routes_are_super_admin_only(): void
    {
        foreach ([$this->lineManager, $this->divisionHead, $this->finance, $this->employee] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/approval-queue', [
                'travel_request_id' => $this->tr->id, 'type' => 'Travel', 'level' => '9 of 9',
            ])->assertForbidden();
            $this->patchJson("/api/approval-queue/{$this->q1->id}", ['level' => '9 of 9'])->assertForbidden();
            $this->deleteJson("/api/approval-queue/{$this->q1->id}")->assertForbidden();
        }

        $this->assertSame('1 of 3', $this->q1->fresh()->level);

        // Super Admin tetap punya jalur perbaikan manual.
        Sanctum::actingAs($this->sa);
        $this->patchJson("/api/approval-queue/{$this->q1->id}", ['level' => '9 of 9'])->assertOk();
    }

    public function test_user_directory_is_scoped_per_role(): void
    {
        $other = User::create([
            'name' => 'rekan', 'nik' => 'NIK-rekan', 'email' => 'rekan@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'P1', 'role' => 'Employee', 'status' => 'Aktif', 'manager_id' => $this->lineManager->id,
        ]);

        // Employee hanya melihat dirinya sendiri.
        Sanctum::actingAs($this->employee);
        $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$this->employee->id], $ids->all());

        // Line Manager melihat diri sendiri + bawahannya, bukan user lain.
        Sanctum::actingAs($this->lineManager);
        $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$this->lineManager->id, $this->employee->id, $other->id], $ids->all());
        $this->assertNotContains($this->finance->id, $ids->all());

        // Super Admin & Finance Approver tetap melihat semua.
        foreach ([$this->sa, $this->finance] as $viewer) {
            Sanctum::actingAs($viewer);
            $ids = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('id');
            $this->assertContains($this->finance->id, $ids->all());
        }

        // show() ikut menolak di luar jangkauan.
        Sanctum::actingAs($this->employee);
        $this->getJson("/api/users/{$this->finance->id}")->assertForbidden();
        $this->getJson("/api/users/{$this->employee->id}")->assertOk();
    }

    public function test_last_active_is_recorded_on_login(): void
    {
        $this->assertNull($this->employee->fresh()->last_active);

        $res = $this->postJson('/api/login', [
            'corporateId' => $this->employee->email, 'password' => 'password123',
        ])->assertOk();

        $this->assertNotNull($this->employee->fresh()->last_active);
        $this->assertNotNull($res->json('user.last_active'));
    }

    // ---- Batch approve: all-or-nothing ----

    /**
     * Samakan fixture dengan data nyata: ApprovalChain::build() hanya menyisakan
     * SATU baris 'pending' per travel request, sisanya 'queued'.
     */
    private function normalizeChain(): void
    {
        $this->q2->update(['status' => 'queued']);
    }

    public function test_batch_approve_rejects_out_of_scope_item_without_writing_anything(): void
    {
        $this->normalizeChain();

        // TR milik user di luar tim Line Manager (finance bukan bawahannya).
        $otherTr = TravelRequest::create([
            'request_no' => 'TR-2', 'user_id' => $this->finance->id, 'project_id' => $this->tr->project_id,
            'route' => 'E → F', 'transport' => 'KA', 'start_date' => '2025-05-01',
            'end_date' => '2025-05-02', 'cost' => 500000, 'status' => 'In Review',
        ]);
        ApprovalQueue::create([
            'travel_request_id' => $otherTr->id, 'type' => 'Travel', 'level' => '1 of 1',
            'status' => 'pending', 'amount' => 500000, 'approver_id' => null,
        ]);

        Sanctum::actingAs($this->lineManager);
        $res = $this->postJson('/api/travel-requests/batch-approve', [
            'ids' => [$this->tr->id, $otherTr->id],
        ])->assertStatus(422);

        $this->assertStringContainsString('wilayah kerja', $res->json('failed.0.reason'));

        // q1 sebenarnya wewenang, tapi tidak ada yang tersimpan.
        $this->assertSame('pending', $this->q1->fresh()->status);
        $this->assertSame('In Review', $this->tr->fresh()->status);
    }

    public function test_batch_approve_rolls_back_when_a_later_item_fails(): void
    {
        $this->normalizeChain();

        // Dua pengajuan sama-sama di dalam tim Line Manager (keduanya lolos pre-filter),
        // tapi baris kedua ditunjuk ke approver lain sehingga gagal di tengah pemrosesan.
        $second = User::create([
            'name' => 'rekan2', 'nik' => 'NIK-rekan2', 'email' => 'rekan2@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'P2', 'role' => 'Employee', 'status' => 'Aktif', 'manager_id' => $this->lineManager->id,
        ]);
        $tr2 = TravelRequest::create([
            'request_no' => 'TR-2', 'user_id' => $second->id, 'project_id' => $this->tr->project_id,
            'route' => 'E → F', 'transport' => 'KA', 'start_date' => '2025-05-01',
            'end_date' => '2025-05-02', 'cost' => 500000, 'status' => 'In Review',
        ]);
        ApprovalQueue::create([
            'travel_request_id' => $tr2->id, 'type' => 'Travel', 'level' => '1 of 1',
            'status' => 'pending', 'amount' => 500000, 'approver_id' => $this->divisionHead->id,
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson('/api/travel-requests/batch-approve', [
            'ids' => [$this->tr->id, $tr2->id],
        ])->assertStatus(422);

        // q1 sempat lolos decide() tapi transaksi di-rollback karena q2 gagal.
        $this->assertSame('pending', $this->q1->fresh()->status, 'q1 harus kembali pending karena rollback');
    }

    public function test_batch_approve_succeeds_when_every_item_is_eligible(): void
    {
        $this->normalizeChain();

        Sanctum::actingAs($this->lineManager);
        $res = $this->postJson('/api/travel-requests/batch-approve', [
            'ids' => [$this->tr->id],
        ])->assertOk();

        $this->assertSame(1, $res->json('count'));
        $this->assertNotSame('pending', $this->q1->fresh()->status);
    }

    public function test_approval_queue_batch_approve_ignores_already_decided_rows(): void
    {
        $this->q1->update(['status' => 'approved']);

        Sanctum::actingAs($this->lineManager);
        $this->postJson('/api/approval-queue/batch-approve', [
            'ids' => [$this->q1->id, $this->q2->id],
        ])->assertStatus(422);

        $this->assertSame('approved', $this->q1->fresh()->status);
        $this->assertSame('pending', $this->q2->fresh()->status);
    }

    public function test_batch_approve_blocks_self_approval(): void
    {
        $this->normalizeChain();
        $ownTr = TravelRequest::create([
            'request_no' => 'TR-OWN', 'user_id' => $this->lineManager->id, 'project_id' => $this->tr->project_id,
            'route' => 'G → H', 'transport' => 'KA', 'start_date' => '2025-06-01',
            'end_date' => '2025-06-02', 'cost' => 300000, 'status' => 'In Review',
        ]);
        ApprovalQueue::create([
            'travel_request_id' => $ownTr->id, 'type' => 'Travel', 'level' => '1 of 1',
            'status' => 'pending', 'amount' => 300000,
        ]);

        Sanctum::actingAs($this->lineManager);
        $res = $this->postJson('/api/travel-requests/batch-approve', ['ids' => [$ownTr->id]])->assertStatus(422);

        $this->assertStringContainsString('milik sendiri', $res->json('failed.0.reason'));
    }

    public function test_manager_cannot_decide_another_teams_request(): void
    {
        $this->normalizeChain();
        $this->q1->update(['approver_id' => null]); // pool, jadi lolos isDesignated

        // q1 milik employee bawahan Line Manager → boleh.
        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$this->q1->id}/decide", ['action' => 'approve'])->assertOk();

        // Antrean milik user di luar timnya → ditolak despite level cocok.
        $otherTr = TravelRequest::create([
            'request_no' => 'TR-2', 'user_id' => $this->finance->id, 'project_id' => $this->tr->project_id,
            'route' => 'E → F', 'transport' => 'KA', 'start_date' => '2025-05-01',
            'end_date' => '2025-05-02', 'cost' => 500000, 'status' => 'In Review',
        ]);
        $outOfScope = ApprovalQueue::create([
            'travel_request_id' => $otherTr->id, 'type' => 'Travel', 'level' => '1 of 1',
            'status' => 'pending', 'amount' => 500000, 'approver_id' => null,
        ]);

        $this->postJson("/api/approval-queue/{$outOfScope->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
        $this->assertSame('pending', $outOfScope->fresh()->status);
    }
}
