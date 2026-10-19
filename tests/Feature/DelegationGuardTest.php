<?php

namespace Tests\Feature;

use App\Models\ApprovalQueue;
use App\Models\Department;
use App\Models\Delegation;
use App\Models\Project;
use App\Models\Role;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengamanan delegasi Plt.
 *
 * Dua celah yang ditutup di sini:
 *  1. Delegatee boleh role apa saja — Super Admin bisa menyerahkan L1–L4 ke
 *     staf biasa, dan staf itu berwenang memutus semua level karena
 *     User::canDecide() menguji delegation_for, bukan peran penerima.
 *  2. Delegasi tanpa batas nominal (max_amount kosong/0) = wewenang penuh
 *     tanpa rem.
 *
 * Plus: /delegations tidak di-scope, jadi peta "siapa melimpahkan ke siapa"
 * terbuka ke semua user yang login.
 */
class DelegationGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $sa;
    private User $lm;
    private User $dh;
    private User $finance;
    private User $employee;
    private TravelRequest $travel;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => []]);
        }

        $dept = Department::create(['code' => 'CC-D', 'name' => 'IT', 'budget_total' => 1, 'sla' => 'T']);
        $make = fn (string $e, string $r, string $g) => User::create([
            'name' => $e, 'nik' => 'NIK-'.$e, 'email' => $e, 'password' => Hash::make('password123'),
            'department_id' => $dept->id, 'grade' => $g, 'role' => $r, 'status' => 'Aktif',
        ]);
        $this->sa = $make('sa@x.id', 'Super Admin', 'M2');
        $this->lm = $make('lm@x.id', 'Line Manager', 'M3');
        $this->dh = $make('dh@x.id', 'Division Head', 'M3');
        $this->finance = $make('fin@x.id', 'Finance Approver', 'M2');
        $this->employee = $make('emp@x.id', 'Employee', 'P4');
        // Employee bawahan Line Manager — tanpa ini isOutOfScope() menolak
        // Plt karena pemohon tak terlihat dalam jangkauan LM.
        $this->employee->update(['manager_id' => $this->lm->id]);

        $project = Project::create([
            'code' => 'PRJ-D', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->lm->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1,
        ]);
        $this->travel = TravelRequest::create([
            'request_no' => 'TR-D-1', 'user_id' => $this->employee->id, 'project_id' => $project->id,
            'route' => 'A → B', 'transport' => 'KA', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-03', 'cost' => 9000000, 'status' => 'In Review',
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'delegator_id' => $this->dh->id,
            'delegatee_id' => $this->lm->id,
            'scope' => 'Penuh (Full Approval)',
            'max_amount' => 50000000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ], $over);
    }

    // ============ Batas penerima mandat ============

    public function test_delegatee_must_be_approver_role(): void
    {
        Sanctum::actingAs($this->dh);
        $this->postJson('/api/delegations', $this->payload(['delegatee_id' => $this->employee->id]))
            ->assertStatus(422)->assertJsonValidationErrors('delegatee_id');
        $this->assertSame(0, Delegation::count());
    }

    public function test_super_admin_also_cannot_delegate_to_non_approver(): void
    {
        // Tidak ada escape hatch — berlaku untuk semua role.
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/delegations', $this->payload([
            'delegator_id' => $this->sa->id,
            'delegatee_id' => $this->employee->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('delegatee_id');
    }

    public function test_delegator_must_be_approver_role(): void
    {
        // Employee membuat delegasi untuk dirinya sendiri lewat API (halaman
        // tertutup di FE, tapi endpointnya terbuka untuk semua role login).
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/delegations', $this->payload([
            'delegator_id' => $this->employee->id,
            'delegatee_id' => $this->lm->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('delegator_id');
    }

    public function test_inactive_delegatee_is_rejected(): void
    {
        $this->lm->update(['status' => 'Non-Aktif']);

        Sanctum::actingAs($this->dh);
        $this->postJson('/api/delegations', $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors('delegatee_id');
    }

    // ============ Batas nominal wajib finite ============

    public function test_max_amount_is_required_and_must_be_positive(): void
    {
        Sanctum::actingAs($this->dh);

        $noAmount = $this->payload();
        unset($noAmount['max_amount']);
        $this->postJson('/api/delegations', $noAmount)
            ->assertStatus(422)->assertJsonValidationErrors('max_amount');

        $this->postJson('/api/delegations', $this->payload(['max_amount' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('max_amount');
    }

    public function test_valid_delegation_is_created(): void
    {
        Sanctum::actingAs($this->dh);
        $res = $this->postJson('/api/delegations', $this->payload())->assertCreated()->json();

        $this->assertSame('Aktif', $res['status']);
        $this->assertSame(50000000.0, (float) $res['max_amount']);
    }

    // ============ Runtime: delegasi lama ke Employee mati ============

    public function test_preexisting_delegation_to_employee_no_longer_grants_authority(): void
    {
        // Baris lama dibuat sebelum aturan ini ada — tetap di DB, tapi tidak
        // boleh memberi wewenang karena penerima bukan role approver.
        Delegation::create([
            'delegator_id' => $this->sa->id,
            'delegatee_id' => $this->employee->id,
            'scope' => 'Penuh (Full Approval)',
            'max_amount' => 99999999,
            'status' => 'Aktif',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        $queue = ApprovalQueue::create([
            'travel_request_id' => $this->travel->id, 'type' => 'Travel', 'level' => '1 of 2',
            'status' => 'pending', 'amount' => 1000000,
        ]);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/approval-queue/{$queue->id}/decide", ['action' => 'approve'])
            ->assertForbidden();
        $this->assertSame('pending', $queue->fresh()->status);
    }

    public function test_active_delegation_grants_authority_and_records_on_behalf_of(): void
    {
        $this->travel->update(['cost' => 20000000]);
        Delegation::create([
            'delegator_id' => $this->dh->id,
            'delegatee_id' => $this->lm->id,
            'scope' => 'Penuh (Full Approval)',
            'max_amount' => 50000000,
            'status' => 'Aktif',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        $queue = ApprovalQueue::create([
            'travel_request_id' => $this->travel->id, 'type' => 'Travel', 'level' => '2 of 2',
            'status' => 'pending', 'amount' => 20000000, 'approver_id' => $this->dh->id,
        ]);

        Sanctum::actingAs($this->lm);
        $this->postJson("/api/approval-queue/{$queue->id}/decide", ['action' => 'approve'])
            ->assertOk();

        $fresh = $queue->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->lm->id, (int) $fresh->decided_by);
        $this->assertSame($this->dh->id, (int) $fresh->on_behalf_of, 'Plt a/n harus tercatat');
    }

    // ============ Scoping daftar ============

    public function test_index_only_shows_own_delegations(): void
    {
        // Milik orang lain → tidak terlihat.
        Delegation::create([
            'delegator_id' => $this->dh->id, 'delegatee_id' => $this->finance->id,
            'scope' => 'Penuh', 'max_amount' => 1000, 'status' => 'Aktif',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ]);
        Sanctum::actingAs($this->lm);
        $this->assertCount(0, $this->getJson('/api/delegations')->assertOk()->json('data'));

        // Milik sendiri (sebagai delegator) → terlihat.
        Delegation::create([
            'delegator_id' => $this->lm->id, 'delegatee_id' => $this->dh->id,
            'scope' => 'Penuh', 'max_amount' => 1000, 'status' => 'Aktif',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ]);
        Sanctum::actingAs($this->lm);
        $this->assertCount(1, $this->getJson('/api/delegations')->assertOk()->json('data'));

        // Super Admin melihat semua.
        Sanctum::actingAs($this->sa);
        $this->assertCount(2, $this->getJson('/api/delegations')->assertOk()->json('data'));
    }

    public function test_delegatee_sees_delegation_received(): void
    {
        Delegation::create([
            'delegator_id' => $this->dh->id, 'delegatee_id' => $this->lm->id,
            'scope' => 'Penuh', 'max_amount' => 1000, 'status' => 'Aktif',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ]);

        Sanctum::actingAs($this->lm);
        $rows = $this->getJson('/api/delegations')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->dh->id, (int) $rows[0]['delegator_id']);
    }
}