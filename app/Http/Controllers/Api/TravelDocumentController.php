<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TravelDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TravelDocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = TravelDocument::with('travelRequest');

        $ids = $request->user()->visibleUserIds();
        if ($ids !== null) {
            $query->whereHas('travelRequest', fn ($t) => $t->whereIn('user_id', $ids));
        }

        if ($request->filled('travel_request_id')) {
            $query->where('travel_request_id', $request->travel_request_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json($query->latest()->paginate((int) $request->input('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'travel_request_id' => 'required|exists:travel_requests,id',
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'file_url' => 'nullable|string|max:500',
            'mime' => 'nullable|string|max:100',
            'size_kb' => 'nullable|integer|min:0',
            'ocr_status' => 'nullable|string|max:50',
            'amount' => 'nullable|numeric|min:0',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx|max:10240',
        ]);

        // Unggah hanya oleh pemilik travel, Finance, atau Super Admin
        $travel = \App\Models\TravelRequest::findOrFail($data['travel_request_id']);
        $me = $request->user();
        if (! $me->isSuperAdmin() && ! $me->hasRole('Finance Approver') && $travel->user_id !== $me->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $data['file_path'] = $file->store('travel-documents', 'public');
            $data['file_url'] ??= '/storage/' . $data['file_path'];
            $data['mime'] ??= $file->getMimeType();
            $data['size_kb'] ??= (int) ceil($file->getSize() / 1024);
        }

        return response()->json(TravelDocument::create($data), 201);
    }

    public function show(Request $request, TravelDocument $travelDocument)
    {
        $ids = $request->user()->visibleUserIds();
        if ($ids !== null && ! in_array($travelDocument->travelRequest?->user_id, $ids, true)) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        return response()->json($travelDocument->load('travelRequest'));
    }

    /**
     * Otorisasi tulis dokumen.
     *
     * SEBELUMNYA destroy() tidak menerima Request sehingga tidak punya cek
     * akses sama sekali — siapa pun yang login bisa menghapus dokumen travel
     * milik siapa saja, termasuk dokumen yang sudah jadi dasar reimbursement.
     */
    private function assertCanWrite(Request $request, TravelDocument $document): void
    {
        $me = $request->user();
        $ownerId = $document->travelRequest?->user_id;

        if ($me->isSuperAdmin() || $me->hasRole('Finance Approver') || $ownerId === $me->id) {
            return;
        }

        abort(403, 'Akses ditolak untuk role Anda.');
    }

    public function update(Request $request, TravelDocument $travelDocument)
    {
        $this->assertCanWrite($request, $travelDocument);

        $data = $request->validate([
            'type' => 'sometimes|string|max:50',
            'title' => 'sometimes|string|max:255',
            'file_url' => 'nullable|string|max:500',
            'mime' => 'nullable|string|max:100',
            'size_kb' => 'nullable|integer|min:0',
            'ocr_status' => 'nullable|string|max:50',
            'amount' => 'nullable|numeric|min:0',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx|max:10240',
        ]);

        if ($request->hasFile('file')) {
            // Hapus file lama supaya tidak menumpuk di storage — kalau tidak,
            // setiap penggantian dokumen menyisakan file yatim yang tidak
            // pernah direferensikan lagi tapi tetap memakan kuota disk.
            $this->deleteStoredFile($travelDocument->file_path);

            $file = $request->file('file');
            $data['file_path'] = $file->store('travel-documents', 'public');
            $data['file_url'] = '/storage/'.$data['file_path'];
            $data['mime'] = $file->getMimeType();
            $data['size_kb'] = (int) ceil($file->getSize() / 1024);
        }

        $travelDocument->update($data);

        return response()->json($travelDocument->load('travelRequest'));
    }

    public function destroy(Request $request, TravelDocument $travelDocument)
    {
        $this->assertCanWrite($request, $travelDocument);

        $this->deleteStoredFile($travelDocument->file_path);

        $travelDocument->delete();

        return response()->noContent();
    }

    /** Hapus berkas fisik dari storage public, abaikan bila hilang/gagal. */
    private function deleteStoredFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
