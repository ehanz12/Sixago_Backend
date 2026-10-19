<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'corporateId' => 'required',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->corporateId)->orWhere('nik', $request->corporateId)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->status !== 'Aktif') {
            return response()->json(['message' => 'Akun Anda tidak aktif. Hubungi administrator.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // Catat aktivitas agar kolom users.last_active tidak selalu NULL
        // (FE menampilkannya sebagai kolom "Terakhir aktif").
        $user->forceFill(['last_active' => now()])->save();

        $user->loadMissing('department');

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'must_change_password' => (bool) $user->must_change_password,
            'user' => array_merge($user->toArray(), ['permissions' => $user->permissions()]),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /** Cabut semua token (logout dari semua perangkat). */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Semua sesi berhasil diakhiri.']);
    }

    /** Ganti password sendiri (min. 8 karakter). */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Password saat ini salah.'], 422);
        }

        $user->update(['password' => $data['password'], 'must_change_password' => false]);

        return response()->json(['message' => 'Password berhasil diperbarui.']);
    }

    public function user(Request $request)
    {
        $user = $request->user()->loadMissing('department');

        return array_merge($user->toArray(), ['permissions' => $user->permissions()]);
    }
}
