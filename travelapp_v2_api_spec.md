## TravelApp v2 — API Request & Response Specification

(berdasarkan drawSQL-mysql-export-2026-09-01.sql)

Catatan skema: relasi pada dokumen ini mengacu ke kolom FK yang sebenarnya (user_id, travel_request_id, approver_id, verified_by, manager_id, dst), bukan ke blok ALTER TABLE ... ADD CONSTRAINT di bagian bawah file SQL yang salah pasangan (mis. reimbursements.id → travel_advances.id). Nilai status ENUM('') yang kosong diasumsikan menggunakan nilai standar per modul (lihat catatan di tiap endpoint).

## Daftar Isi

- Format umum

- 10.1 Auth, User, Role & Department

- 10.2 Project

- 10.3 Travel Policy

- 10.4 Travel Request

- 10.5 Travel Document

- 10.6 Approval Level & Travel Approval

- 10.7 Approval Delegation

- 10.8 Travel Advance

- 10.9 Travel Expense & Receipt

- 10.10 Reimbursement

- 10.11 Notification

- 10.12 Error Response

- Struktur API final

## Format umum

Content-Type: application/json Authorization: Bearer <access_token>

Upload file menggunakan:

Content-Type: multipart/form-data

## Success

{

"success": true,

"message": "Request berhasil.",

"data": {}

}

Error


```
{
"success": false,
"message": "Request gagal.",
"errors": {}
}
```

## 10.1 Auth, User, Role & Department

## GET /api/users/me

Response 200

```
{
"success": true,
"message": "Profil berhasil diambil.",
"data": {
"id": 12,
"name": "Andi Pratama",
"email": "andi.pratama@company.com",
"role": { "id": 2, "name": "Staff" },
"department": { "id": 3, "name": "Information Technology" },
"manager": { "id": 8, "name": "Budi Santoso" },
"created_at": "2026-01-10T02:00:00Z"
}
}
```

?page=1&limit=20&department_id=3&role_id=2

```
{
"success": true,
"message": "Daftar user berhasil diambil.",
"data": {
"items": [
{
"id": 12,
"name": "Andi Pratama",
"email": "andi.pratama@company.com",
"role": { "id": 2, "name": "Staff" },
```

## GET /api/users

Query

## Response 200

```
"department": { "id": 3, "name": "Information Technology" },
"manager_id": 8
}
],
"pagination": { "page": 1, "limit": 20, "total": 1, "total_pages": 1 }
}
}
```


## GET /api/roles

## Response 200

```
{
"success": true,
"message": "Daftar role berhasil diambil.",
"data": {
"items": [
{ "id": 1, "name": "Manager", "description": "Menyetujui travel request level 1" },
{ "id": 2, "name": "Staff", "description": "Karyawan pengaju travel request" }
]
}
}
```

## GET /api/departments

Response 200

```
{
"success": true,
"message": "Daftar departemen berhasil diambil.",
"data": {
"items": [
{ "id": 3, "name": "Information Technology", "description": "Divisi teknologi" }
]
}
}
```

## 10.2 Project

GET /api/projects

Query

Response 200

?page=1&limit=20&status=ACTIVE&search=API


```
{
"success": true,
"message": "Daftar project berhasil diambil.",
"data": {
"items": [
{
"id": 7,
"name": "API Integration Client X",
"code": "PRJ-007",
"status": "ACTIVE"
}
],
"pagination": { "page": 1, "limit": 20, "total": 1, "total_pages": 1 }
}
}
```

## GET /api/projects/

## Response 200

```
{
"success": true,
"message": "Detail project berhasil diambil.",
"data": {
"id": 7,
"name": "API Integration Client X",
"code": "PRJ-007",
"description": "Proyek integrasi API dengan klien di Singapore.",
"status": "ACTIVE"
}
}
```

## POST /api/projects

## Request

```
{
"name": "API Integration Client X",
"code": "PRJ-007",
"description": "Proyek integrasi API dengan klien di Singapore."
}
```

## Response 201

```
{
"success": true,
"message": "Project berhasil dibuat.",
"data": {
"id": 7,
"code": "PRJ-007",
"status": "ACTIVE"
}
}
```


Catatan enum: status project diasumsikan ACTIVE | ON_HOLD | COMPLETED | CANCELLED.

```
{
"success": true,
```

## 10.3 Travel Policy

## GET /api/travel-policies

Response 200

```
"message": "Daftar kebijakan perjalanan berhasil diambil.",
"data": {
"items": [
{
"id": 2,
"name": "Kebijakan Staff - Domestik",
"max_transport_cost": 1500000.00,
"max_hotel_cost": 800000.00,
"max_meal_cost": 150000.00,
"max_daily_allowance": 200000.00,
"status": "ACTIVE"
}
]
}
}
{
"success": true,
```

## GET /api/travel-policies/

Response 200

```
"message": "Detail kebijakan berhasil diambil.",
"data": {
"id": 2,
"name": "Kebijakan Staff - Domestik",
"description": "Berlaku untuk perjalanan dinas dalam negeri level staff.",
"max_transport_cost": 1500000.00,
"max_hotel_cost": 800000.00,
"max_meal_cost": 150000.00,
"max_daily_allowance": 200000.00,
"status": "ACTIVE",
"created_at": "2026-01-05T00:00:00Z"
}
}
```

Catatan enum: status policy diasumsikan ACTIVE | INACTIVE.


## 10.4 Travel Request

## GET /api/travel-requests

Query

?page=1&limit=10&status=SUBMITTED&user_id=12&project_id=7

## Response 200

```
{
"success": true,
"message": "Daftar travel request berhasil diambil.",
"data": {
"items": [
{
"id": 101,
"destination": "Singapore",
"start_date": "2026-09-15",
"end_date": "2026-09-18",
"estimated_cost": 4500000.00,
"status": "SUBMITTED",
"user": { "id": 12, "name": "Andi Pratama" },
"project": { "id": 7, "code": "PRJ-007" }
}
],
"pagination": { "page": 1, "limit": 10, "total": 1, "total_pages": 1 }
}
}
```

## GET /api/travel-requests/

Response 200


```
{
"success": true,
"message": "Detail travel request berhasil diambil.",
"data": {
"id": 101,
"purpose": "Menghadiri konferensi teknologi regional",
"destination": "Singapore",
"start_date": "2026-09-15",
"end_date": "2026-09-18",
"estimated_cost": 4500000.00,
"status": "APPROVED",
"user": {
"id": 12,
"name": "Andi Pratama",
"department": { "id": 3, "name": "Information Technology" }
},
"project": { "id": 7, "name": "API Integration Client X", "code": "PRJ-007" },
"policy": { "id": 2, "name": "Kebijakan Staff - Domestik" },
"approvals": [
{
"id": 501,
"approval_level": { "id": 1, "name": "Manager", "level": 1 },
"approver": { "id": 8, "name": "Budi Santoso" },
"status": "APPROVED",
"approval_at": "2026-08-21T02:00:00Z"
}
],
"documents": [
{ "id": 701, "document_type": "TICKET", "file_name": "boarding_pass.pdf" }
],
"advance": { "id": 30, "amount": 1000000.00, "status": "PAID" },
"reimbursement": { "id": 55, "status": "SUBMITTED" },
"created_at": "2026-08-20T03:12:00Z",
"updated_at": "2026-08-25T07:45:00Z"
}
}
```

## POST /api/travel-requests

```
{
"project_id": 7,
"policy_id": 2,
```

## Request

```
"purpose": "Menghadiri konferensi teknologi regional",
"destination": "Singapore",
"start_date": "2026-09-15",
"end_date": "2026-09-18",
"estimated_cost": 4500000.00
}
```


```
{
"success": true,
"message": "Travel request berhasil dibuat.",
"data": {
"id": 101,
"status": "DRAFT"
}
}
{
"destination": "Singapore",
```

## PUT /api/travel-requests/

## Request

```
"purpose": "Konferensi teknologi & site visit klien",
"start_date": "2026-09-15",
"end_date": "2026-09-19",
"estimated_cost": 5200000.00
}
{
"success": true,
```

## Response 200

```
"message": "Travel request berhasil diperbarui.",
"data": {
"id": 101,
"estimated_cost": 5200000.00,
"updated_at": "2026-09-01T04:00:00Z"
}
}
{
"success": true,
```

## PATCH /api/travel-requests/

Response 200

```
"message": "Travel request berhasil diajukan.",
"data": { "id": 101, "status": "SUBMITTED" }
}
{ "reason": "Jadwal konferensi berubah." }
```

## PATCH /api/travel-requests/

## Request

Response 200


```
{
"success": true,
"message": "Travel request berhasil dibatalkan.",
"data": { "id": 101, "status": "CANCELLED" }
}
Catatan enum: status travel request diasumsikan DRAFT | SUBMITTED | IN_REVIEW | APPROVED | REJECTED | CANCELLED |
COMPLETED.
```

## 10.5 Travel Document

## POST /api/travel-requests/

Request

```
multipart/form-data
file: <file>
document_type: TICKET
```

## Response 201

```
{
"success": true,
"message": "Dokumen berhasil diunggah.",
"data": {
"id": 701,
"travel_request_id": 101,
"document_type": "TICKET",
"file_name": "boarding_pass.pdf",
"file_path": "/uploads/travel/101/boarding_pass.pdf",
"uploaded_by": 12,
"uploaded_at": "2026-08-24T01:10:00Z"
}
}
```

## GET /api/travel-requests/

## Response 200

```
{
"success": true,
"message": "Daftar dokumen berhasil diambil.",
"data": {
"items": [
{ "id": 701, "document_type": "TICKET", "file_name": "boarding_pass.pdf" }
]
}
}
```


## DELETE /api/documents/

Response 200

```
{
"success": true,
"message": "Dokumen berhasil dihapus.",
"data": null
}
```

## 10.6 Approval Level & Travel Approval

## GET /api/approval-levels

Response 200

```
{
"success": true,
"message": "Daftar approval level berhasil diambil.",
"data": {
"items": [
{ "id": 1, "name": "Manager", "level": 1 },
{ "id": 2, "name": "Department Head", "level": 2 }
]
}
}
```

## GET /api/travel-approvals

Query

Response 200

?status=PENDING&approver_id=8


```
{
"success": true,
"message": "Daftar approval berhasil diambil.",
"data": {
"items": [
{
"id": 501,
"travel_request": {
"id": 101,
"destination": "Singapore",
"user": { "id": 12, "name": "Andi Pratama" }
},
"approval_level": { "id": 1, "name": "Manager", "level": 1 },
"status": "PENDING"
}
]
}
}
```

## PATCH /api/travel-approvals/

## Request

```
{ "notes": "Disetujui, sesuai anggaran divisi." }
```

## Response 200

```
{
"success": true,
"message": "Travel request berhasil disetujui.",
"data": {
"id": 501,
"status": "APPROVED",
"approval_at": "2026-08-21T02:00:00Z"
}
}
```

## PATCH /api/travel-approvals/

## Request

```
{ "notes": "Anggaran divisi tidak mencukupi." }
```

## Response 200

```
{
"success": true,
"message": "Travel request berhasil ditolak.",
"data": { "id": 501, "status": "REJECTED" }
}
```


## 10.7 Approval Delegation

## POST /api/approval-delegations

Request

```
{
"to_user_id": 9,
"start_date": "2026-09-10",
"end_date": "2026-09-14",
"reason": "Cuti tahunan."
}
{
"success": true,
```

## Response 201

```
"message": "Delegasi approval berhasil dibuat.",
"data": {
"id": 21,
"from_user_id": 8,
"to_user_id": 9,
"status": "ACTIVE"
}
}
```

## GET /api/approval-delegations

```
{
"success": true,
```

## Response 200

```
"message": "Daftar delegasi berhasil diambil.",
"data": {
"items": [
{
"id": 21,
"from_user": { "id": 8, "name": "Budi Santoso" },
"to_user": { "id": 9, "name": "Siti Aminah" },
"start_date": "2026-09-10",
"end_date": "2026-09-14",
"status": "ACTIVE"
}
]
}
}
```


## 10.8 Travel Advance

```
{
"amount": 1000000.00,
"request_date": "2026-08-22"
}
{
"success": true,
```

## POST /api/travel-requests/

Request

## Response 201

```
"message": "Pengajuan uang muka berhasil dibuat.",
"data": {
"id": 30,
"travel_request_id": 101,
"amount": 1000000.00,
"status": "PENDING"
}
}
{ "approved_amount": 1000000.00 }
{
"success": true,
```

## PATCH /api/travel-advances/

## Request

## Response 200

```
"message": "Uang muka berhasil disetujui.",
"data": {
"id": 30,
"approved_amount": 1000000.00,
"status": "APPROVED"
}
}
{}
```

## PATCH /api/travel-advances/

Request


```
{
"success": true,
"message": "Uang muka berhasil dibayarkan.",
"data": {
"id": 30,
"status": "PAID",
"paid_at": "2026-08-23T03:00:00Z"
}
}
```

## Response 200

Catatan enum: status advance diasumsikan PENDING | APPROVED | REJECTED | PAID.

## 10.9 Travel Expense & Receipt

GET /api/travel-requests/

Response 200

```
{
"success": true,
"message": "Daftar pengeluaran berhasil diambil.",
"data": {
"items": [
{
"id": 901,
"expense_type": "TRANSPORT",
"description": "Tiket pesawat CGK-SIN",
"amount": 3500000.00,
"expense_date": "2026-09-15",
"status": "SUBMITTED",
"receipts": [
{ "id": 1001, "file_name": "receipt_flight.jpg" }
]
}
]
}
}
```

## POST /api/travel-requests/

Request

```
{
"expense_type": "TRANSPORT",
"description": "Tiket pesawat CGK-SIN",
"amount": 3500000.00,
"expense_date": "2026-09-15"
}
```


## Response 201

```
{
"success": true,
"message": "Pengeluaran berhasil ditambahkan.",
"data": {
"id": 901,
"travel_request_id": 101,
"status": "SUBMITTED"
}
}
```

## POST /api/expenses/

## Request

```
multipart/form-data
file: <file>
```

## Response 201

```
{
"success": true,
"message": "Bukti pengeluaran berhasil diunggah.",
"data": {
"id": 1001,
"expense_id": 901,
"file_name": "receipt_flight.jpg",
"file_path": "/uploads/receipts/1001.jpg",
"uploaded_at": "2026-09-16T01:00:00Z"
}
}
```

Catatan enum: status expense diasumsikan SUBMITTED | VERIFIED | REJECTED.

10.10 Reimbursement

GET /api/travel-requests/

Response 200


```
{
"success": true,
"message": "Reimbursement berhasil diambil.",
"data": {
"id": 55,
"travel_request_id": 101,
"total_expense": 4200000.00,
"advance_amount": 1000000.00,
"reimbursement_amount": 3200000.00,
"status": "SUBMITTED",
"submitted_at": "2026-09-20T02:00:00Z",
"verified_by": null,
"verified_at": null,
"notes": null
}
}
```

{}

```
{
"success": true,
```

## POST /api/travel-requests/

## Request

## Response 201

```
"message": "Reimbursement berhasil dibuat dari total pengeluaran.",
"data": {
"id": 55,
"total_expense": 4200000.00,
"advance_amount": 1000000.00,
"reimbursement_amount": 3200000.00,
"status": "DRAFT"
}
}
```

## PATCH /api/reimbursements/

```
{
"success": true,
```

Response 200

```
"message": "Reimbursement berhasil diajukan.",
"data": {
"id": 55,
"status": "SUBMITTED",
"submitted_at": "2026-09-20T02:00:00Z"
}
}
```

PATCH /api/reimbursements/


## Request

```
{
"notes": "Sesuai dengan bukti pengeluaran."
}
```

## Response 200

```
{
"success": true,
"message": "Reimbursement berhasil diverifikasi.",
"data": {
"id": 55,
"status": "VERIFIED",
"verified_by": 4,
"verified_at": "2026-09-21T05:00:00Z"
}
}
```

Catatan enum: status reimbursement diasumsikan DRAFT | SUBMITTED | VERIFIED | REJECTED | PAID.

## 10.11 Notification

## GET /api/notifications

Query

?page=1&limit=20&type=APPROVAL_REQUIRED&is_read=false

## Response 200

```
{
"success": true,
"message": "Notifikasi berhasil diambil.",
"data": {
"items": [
{
"id": 801,
"title": "Persetujuan Diperlukan",
"message": "Travel request #101 menunggu persetujuan Anda.",
"type": "APPROVAL_REQUIRED",
"is_read": false,
"created_at": "2026-08-20T03:15:00Z"
}
],
"unread_count": 1
}
}
```


## Response 200

```
{
"success": true,
"message": "Notifikasi ditandai sudah dibaca.",
"data": { "id": 801, "is_read": true }
}
```

## 10.12 Error Response

## 400 — Bad Request

```
{
"success": false,
"message": "Data request tidak valid.",
"errors": { "estimated_cost": "estimated_cost harus lebih besar dari 0." }
}
```

## 401 — Unauthorized

```
{ "success": false, "message": "Token tidak valid atau telah kedaluwarsa.", "errors": {} }
```

## 403 — Forbidden

```
{ "success": false, "message": "Anda tidak memiliki akses untuk memverifikasi reimbursement ini.", "errors": {} }
```

## 404 — Not Found

```
{ "success": false, "message": "Travel request tidak ditemukan.", "errors": {} }
```

## 409 — Conflict

```
{ "success": false, "message": "Reimbursement untuk travel request ini sudah dibuat.", "errors": {} }
```

## 422 — Validation Error

```
{
"success": false,
"message": "Validasi gagal.",
"errors": {
"end_date": "end_date harus setelah start_date.",
"policy_id": "policy_id wajib diisi."
}
}
```

## 500 — Internal Server Error

```
{ "success": false, "message": "Terjadi kesalahan pada server.", "errors": {} }
```


## Struktur API final

/api

├── users

│ └── me

├── roles

├── departments

├── projects

├── travel-policies

├── travel-requests

│ ├── CRUD

│ ├── submit

│ ├── cancel

│ ├── documents

│ ├── advance

│ ├── expenses

│ └── reimbursement

├── documents

├── approval-levels

├── travel-approvals

│ ├── approve

│ └── reject

├── approval-delegations

├── travel-advances

│ ├── approve

│ └── pay

├── expenses

│ └── receipts

├── reimbursements

│ ├── submit

│ └── verify

└── notifications

*Catatan: dokumen ini disusun dari drawSQL-mysql-export-2026-09-01.sql, dengan relasi yang diperbaiki mengikuti nama kolom FK aslinya (bukan blok ADD CONSTRAINT yang salah target di file sumber). Nilai enum status yang kosong pada skema diisi dengan asumsi nilai umum untuk sistem travel-*

*approval — sesuaikan dengan kebutuhan bisnis sebelum implementasi.*
