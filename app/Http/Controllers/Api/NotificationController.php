<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id);

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }
        if ($request->boolean('unread_only')) {
            $query->where('read', false);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('message', 'like', "%{$q}%"));
        }

        return response()->json($query->latest()->paginate((int) $request->input('per_page', 10)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string|max:50',
            'category' => 'nullable|string|max:50',
            'reference_id' => 'nullable|string|max:100',
        ]);

        return response()->json(Notification::create($data), 201);
    }

    public function show(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        return response()->json($notification);
    }

    public function destroy(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $notification->delete();

        return response()->noContent();
    }

    /**
     * Hapus semua notifikasi milik user ini yang sudah dibaca.
     *
     * Endpoint ini bukan pengganti tombol tandai-semua-dibaca — tujuannya
     * membersihkan badge yang menumpuk setelah notifikasi lama tidak lagi relevan.
     * Notifikasi belum dibaca sengaja tidak ikut terhapus supaya tidak ada
     * yang hilang tanpa pernah dibaca. Untuk pruning otomatis berbasis
     * usia, pakai perintah terpisah `notifications:prune`.
     */
    public function clearRead(Request $request)
    {
        $deleted = Notification::where('user_id', $request->user()->id)
            ->where('read', true)
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function update(Request $request, Notification $notification)
    {
        // Sama seperti show/destroy/markAsRead: hanya pemilik atau Super Admin.
        // Tanpa cek ini user mana pun bisa menandai notifikasi orang lain via ID.
        if ($notification->user_id !== $request->user()->id && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $notification->update($request->validate(['read' => 'sometimes|boolean']));

        return response()->json($notification);
    }

    /** Tandai satu notifikasi sudah dibaca. */
    public function markAsRead(Request $request, string $id)
    {
        $notification = Notification::findOrFail($id);
        if ($notification->user_id !== $request->user()->id && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }
        $notification->update(['read' => true]);

        return response()->json($notification);
    }

    /** Tandai semua notifikasi milik user sudah dibaca. */
    public function markAllRead(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)->where('read', false)->update(['read' => true]);

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.', 'count' => $count]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread' => Notification::where('user_id', $request->user()->id)->where('read', false)->count(),
        ]);
    }
}
