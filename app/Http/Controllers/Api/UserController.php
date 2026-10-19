<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Positions;
use App\Support\UserRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Validasi silang posisi: role ↔ grade ↔ atasan langsung ↔ departemen.
     *
     * Tanpa ini keempatnya berdiri sendiri: grade ngawol tetap diterima, role
     * ngawol tetap diterima, dan manager bisa menunjuk siapa saja termasuk
     * dirinya sendiri. Aturan & rentangnya di App\Support\Positions.
     *
     * @param  User|null  $subject  user yang sedang diedit (null saat create)
     * @param  bool  $requireManager  false untuk import CSV (format CSV tidak
     *                                punya kolom atasan — diisi manual setelahnya).
     */
    private function assertPositionConsistent(
        string $role,
        string $grade,
        ?int $managerId,
        ?User $subject = null,
        ?int $departmentId = null,
        bool $requireManager = true
    ): void {
        $errors = [];

        // 1. Role & grade harus dikenal (Rule::in sudah catching, ini jaga bila
        //    dipanggil dari jalur non-HTTP seperti import).
        if (! Positions::isKnownRole($role)) {
            $errors['role'] = ['Role tidak dikenal. Pilihan: '.implode(', ', UserRoles::all()).'.'];
        }
        if (! Positions::isKnownGrade($grade)) {
            $errors['grade'] = ['Grade tidak dikenal. Pilihan: '.implode(', ', Positions::grades()).'.'];
        }
        if ($errors !== []) {
            $this->failValidation($errors);
        }

        // 2. Grade harus sah untuk role tersebut.
        if (! Positions::roleAllowsGrade($role, $grade)) {
            $allowed = Positions::gradesForRole($role);
            $errors['grade'] = ['Grade '.$grade.' tidak sesuai role '.$role.'. Rentang yang sah: '.implode(', ', $allowed).'.'];
        }

        // 3. Atasan langsung.
        if ($managerId !== null) {
            if ($subject !== null && (int) $subject->id === $managerId) {
                $errors['manager_id'] = ['Tidak boleh menjadi atasan langsung dirinya sendiri.'];
            } else {
                $manager = User::with('manager')->find($managerId);
                if ($manager !== null) {
                    if ($manager->status !== 'Aktif') {
                        $errors['manager_id'] = ['Atasan langsung harus berstatus Aktif.'];
                    }
                    if (! Positions::isApproverCapable($manager->role)) {
                        $errors['manager_id'] = ['Role atasan langsung ('.$manager->role.') tidak berwenang menjadi approver.'];
                    }
                    // Deteksi siklus hanya mungkin bila user sudah punya id (edit).
                    if ($subject !== null) {
                        $chain = Positions::inspectManagerChain($manager, (int) $subject->id);
                        if ($chain['cycle']) {
                            $errors['manager_id'] = [$chain['reason']];
                        }
                    }
                }
            }
        } elseif ($requireManager && Positions::requiresManager($role)) {
            // 4. Role tertentu wajib punya atasan (Employee).
            $errors['manager_id'] = ['Role '.$role.' wajib memiliki atasan langsung.'];
        }

        if ($errors !== []) {
            $this->failValidation($errors);
        }
    }

    /** Lempar 422 dengan bentuk respons yang sama dengan gagal validasi Laravel. */
    private function failValidation(array $errors): void
    {
        throw ValidationException::withMessages($errors);
    }

    public function index(Request $request)
    {
        // manager.department ikut dimuat: FE menampilkan departemen atasan
        // (users.vue) yang tanpa ini selalu kosong.
        $query = User::with(['department', 'manager.department']);

        // Skop direktori sesuai role (pola yang sama dengan TravelRequestController::scopeVisible
        // dan ApprovalQueueController::index). Tanpa ini setiap userAuthenticated bisa
        // men-enumerate seluruh direktori (nama, NIK, email, whatsapp, grade, atasan).
        // Super Admin & Finance Approver tetap melihat semua (visibleUserIds() -> null).
        $visibleIds = $request->user()->visibleUserIds();
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('nik', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }
        if ($request->filled('department')) {
            $query->whereHas('department', fn ($d) => $d->where('name', 'like', "%{$request->department}%"));
        }
        // Filter persis per id — dipakai dari kartu departemen ("Kelola Anggota").
        // Param `department` di atas berbasis LIKE nama, jadi dua departemen
        // dengan nama mirip akan saling tertangkap.
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('role')) {
            $query->where('role', 'like', "%{$request->role}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate((int) $request->input('per_page', 10)));
    }

    /** Ringkasan KPI halaman Pengguna — global, khusus Super Admin (lihat routes). */
    public function summary()
    {
        $base = User::query();

        return response()->json([
            'total' => (clone $base)->count(),
            'baru_30_hari' => (clone $base)->where('created_at', '>=', now()->subDays(30))->count(),
            'pejabat' => (clone $base)->whereIn('role', ['Line Manager', 'Division Head', 'Finance Approver', 'Super Admin'])->count(),
            'nonaktif' => (clone $base)->where('status', '!=', 'Aktif')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'nik' => 'required|string|max:50|unique:users,nik',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'department_id' => 'nullable|exists:departments,id',
            'role' => ['required', 'string', 'max:100', Rule::in(UserRoles::all())],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'grade' => ['required', 'string', 'max:20', Rule::in(Positions::grades())],
            'whatsapp' => 'nullable|string|max:30',
            'avatar' => 'nullable|string|max:255',
        ]);

        $this->assertPositionConsistent(
            $data['role'],
            $data['grade'],
            $data['manager_id'] ?? null,
            null,
            $data['department_id'] ?? null
        );

        // Akun baru wajib ganti password saat login pertama
        $user = User::create(array_merge($data, ['must_change_password' => true]));

        return response()->json($user->load(['department', 'manager']), 201);
    }

    public function show(Request $request, User $user)
    {
        // Ikuti skop yang sama dengan index — jangan bocorkan profil di luar jangkauan.
        $visibleIds = $request->user()->visibleUserIds();
        if ($visibleIds !== null && ! in_array($user->id, $visibleIds, true)) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        return response()->json($user->load(['department', 'manager', 'subordinates', 'travelRequests']));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'department_id' => 'nullable|exists:departments,id',
            'role' => ['sometimes', 'string', 'max:100', Rule::in(UserRoles::all())],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'grade' => ['sometimes', 'string', 'max:20', Rule::in(Positions::grades())],
            'status' => 'sometimes|string|max:50',
            'whatsapp' => 'nullable|string|max:30',
            'avatar' => 'nullable|string|max:255',
        ]);

        // Nilai efektif = hasil gabungan payload dengan data tersimpan.
        $this->assertPositionConsistent(
            $data['role'] ?? $user->role,
            $data['grade'] ?? $user->grade,
            array_key_exists('manager_id', $data) ? $data['manager_id'] : $user->manager_id,
            $user,
            $data['department_id'] ?? $user->department_id
        );

        $user->update($data);

        return response()->json($user->load(['department', 'manager']));
    }

    public function destroy(User $user)
    {
        if ($user->travelRequests()->exists() || $user->subordinates()->exists()
            || $user->ledDepartment()->exists() || $user->managedProjects()->exists()) {
            return response()->json(['message' => 'Tidak dapat dihapus: user masih memiliki travel request, bawahan, atau peran (leader/PM). Nonaktifkan statusnya sebagai gantinya.'], 409);
        }

        $user->delete();

        return response()->noContent();
    }

    /** Perbarui profil sendiri (dipakai toggle preferensi notifikasi). */
    public function updateProfile(Request $request)
    {
        $me = $request->user();
        $me->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'whatsapp' => 'nullable|string|max:30',
            'avatar' => 'nullable|string|max:255',
            'email_notif_enabled' => 'sometimes|boolean',
        ]));

        return response()->json($me->fresh()->load('department'));
    }

    /**
     * Impor karyawan dari CSV (header: nik,name,email,role,grade,department_code).
     * Baris gagal dilewati dan dilaporkan.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);

        $created = 0;
        $skipped = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            // Baris dengan jumlah kolom berbeda dari header tidak bisa dipetakan —
            // lewati dan laporkan, jangan biarkan array_combine melempar ValueError (500).
            if (count($row) !== count($header)) {
                $skipped[] = $line;
                continue;
            }
            $rec = array_combine($header, $row);
            if (! $rec || empty($rec['nik']) || empty($rec['name']) || empty($rec['email'])) {
                $skipped[] = $line;
                continue;
            }
            $deptId = null;
            if (! empty($rec['department_code'])) {
                $deptId = \App\Models\Department::where('code', trim($rec['department_code']))->value('id');
            }
            try {
                $role = trim($rec['role'] ?? 'Employee');
                $grade = trim($rec['grade'] ?? 'P1');
                // Import ikut menegakkan matriks posisi; baris|langgar dilewati
                // dengan alasannya, bukan diam-diam membuat user rusak.
                // Atasan tidak diperiksa (CSV tidak punya kolomnya).
                $this->assertPositionConsistent($role, $grade, null, null, $deptId, requireManager: false);

                \App\Models\User::create([
                    'nik' => trim($rec['nik']),
                    'name' => trim($rec['name']),
                    'email' => trim($rec['email']),
                    'password' => \Illuminate\Support\Str::random(12),
                    'role' => $role,
                    'grade' => $grade,
                    'department_id' => $deptId,
                    'status' => 'Aktif',
                    'must_change_password' => true,
                ]);
                $created++;
            } catch (\Throwable) {
                $skipped[] = $line;
            }
        }
        fclose($handle);

        return response()->json([
            'message' => "Impor selesai: {$created} dibuat" . ($skipped ? ', baris dilewati: '.implode(', ', $skipped) : '') . '. Password awal acak — user wajib ganti saat login pertama.',
            'created' => $created,
            'skipped_lines' => $skipped,
        ]);
    }

    /** Reset password akun (tombol lock_reset di tabel users). */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => 'required|string|min:8']);
        $user->update(['password' => Hash::make($data['password']), 'must_change_password' => true]);

        return response()->json(['message' => "Password {$user->name} berhasil direset."]);
    }

    /** Aktif / nonaktif akun (tombol block di tabel users). */
    public function toggleStatus(User $user)
    {
        $user->update(['status' => $user->status === 'Aktif' ? 'Non-Aktif' : 'Aktif']);

        return response()->json(['message' => "Status {$user->name}: {$user->status}.", 'data' => $user]);
    }
}
