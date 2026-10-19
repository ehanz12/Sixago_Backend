<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delegation;
use App\Support\Positions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DelegationController extends Controller
{
    /**
     * Daftar delegasi.
     *
     * Di-scope: Super Admin melihat semua; selain itu hanya delegasi yang ia
     * buat sendiri ATAU diterima. Tanpa ini, seluruh peta "siapa melimpahkan
     * kewenangan ke siapa, sampai berapa" terbuka ke siapa pun yang login.
     */
    public function index(Request $request)
    {
        $query = Delegation::with(['delegator', 'delegatee']);

        $me = $request->user();
        if (! $me->isSuperAdmin()) {
            $query->where(fn ($w) => $w->where('delegator_id', $me->id)
                ->orWhere('delegatee_id', $me->id));
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->whereHas('delegator', fn ($d) => $d->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('delegatee', fn ($d) => $d->where('name', 'like', "%{$q}%"));
            });
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate((int) $request->input('per_page', 10)));
    }

    /**
     * Aturan delegasi yang berlaku saat membuat/mengubah.
     *
     * Delegatee WAJIB role approver: tanpa ini Super Admin bisa menyerahkan
     * Level 1–4 ke staf biasa, dan staf itu berwenang memutus semua level
     * (User::canDecide diuji dari sisi delegator, bukan penerima).
     *
     * max_amount wajib finite — konvensi lama "0 = tanpa batas" dihapus,
     * karena delegasi tanpa plafon = wewenang penuh tanpa rem.
     */
    private function assertDelegationRules(array $data, ?Delegation $existing = null): void
    {
        $errors = [];

        $delegator = \App\Models\User::find($data['delegator_id'] ?? ($existing?->delegator_id ?? 0));
        if ($delegator && ! Positions::isApproverCapable($delegator->role)) {
            $errors['delegator_id'] = ['Pemberi kewenangan harus ber-role approver (Line Manager, Division Head, Finance Approver, atau Super Admin).'];
        }

        $delegatee = \App\Models\User::find($data['delegatee_id'] ?? ($existing?->delegatee_id ?? 0));
        if ($delegatee) {
            if (! Positions::isApproverCapable($delegatee->role)) {
                $errors['delegatee_id'] = ['Penerima mandat harus ber-role approver — delegasi ke staf biasa membuka eskalasi kewenangan.'];
            } elseif ($delegatee->status !== 'Aktif') {
                $errors['delegatee_id'] = ['Penerima mandat harus berstatus Aktif.'];
            }
        }

        $max = $data['max_amount'] ?? $existing?->max_amount;
        if ($max === null || (float) $max <= 0) {
            $errors['max_amount'] = ['Batas nominal wajib diisi dan lebih dari 0 — delegasi tanpa batas nominal tidak diperbolehkan.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'delegator_id' => 'required|exists:users,id',
            'delegatee_id' => 'required|exists:users,id|different:delegator_id',
            'scope' => 'required|string|max:100',
            'reason' => 'nullable|string',
            'max_amount' => 'required|numeric|min:1',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);
        // Delegasi dibuat oleh delegatornya sendiri (atau Super Admin)
        if (! $request->user()->isSuperAdmin() && (int) $data['delegator_id'] !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $this->assertDelegationRules($data);

        $data['status'] = now()->lt($data['start_date']) ? 'Terjadwal' : 'Aktif';

        $delegation = Delegation::create($data);
        \App\Support\Audits::record('delegation.create', $delegation, [], $delegation->only('delegator_id', 'delegatee_id', 'scope', 'max_amount', 'start_date', 'end_date'), $request);

        return response()->json($delegation->load(['delegator', 'delegatee']), 201);
    }

    public function show(Delegation $delegation)
    {
        return response()->json($delegation->load(['delegator', 'delegatee']));
    }

    private function assertOwner(Request $request, Delegation $delegation): ?\Illuminate\Http\JsonResponse
    {
        if (! $request->user()->isSuperAdmin() && $delegation->delegator_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        return null;
    }

    public function update(Request $request, Delegation $delegation)
    {
        if ($denied = $this->assertOwner($request, $delegation)) {
            return $denied;
        }

        $old = $delegation->only('scope', 'reason', 'max_amount', 'start_date', 'end_date', 'status');

        $data = $request->validate([
            'scope' => 'sometimes|string|max:100',
            'reason' => 'nullable|string',
            'max_amount' => 'sometimes|numeric|min:1',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'status' => 'sometimes|string|max:50',
        ]);

        $this->assertDelegationRules($data, $delegation);

        $delegation->update($data);
        \App\Support\Audits::record('delegation.update', $delegation, $old, $delegation->only('scope', 'reason', 'max_amount', 'start_date', 'end_date', 'status'), $request);

        return response()->json($delegation->load(['delegator', 'delegatee']));
    }

    public function destroy(Request $request, Delegation $delegation)
    {
        if ($denied = $this->assertOwner($request, $delegation)) {
            return $denied;
        }

        $delegation->delete();

        return response()->noContent();
    }

    /** Cabut delegasi (tombol cancel di tabel). */
    public function revoke(Request $request, Delegation $delegation)
    {
        if ($denied = $this->assertOwner($request, $delegation)) {
            return $denied;
        }

        $old = $delegation->only('status');
        $delegation->update(['status' => 'Kedaluwarsa']);
        \App\Support\Audits::record('delegation.revoke', $delegation, $old, $delegation->only('status'), $request);

        return response()->json(['message' => 'Delegasi berhasil dicabut.', 'data' => $delegation]);
    }
}
