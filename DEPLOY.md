# SixAgo Backend — Runbook & Deploy Checklist

## 1. Menjalankan di Local (pengembangan)

```powershell
composer install
Copy-Item .env.example .env   # lalu isi DB_*, MAIL_*, ADMIN_*
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force                    # roles, skema, 1 Super Admin
php artisan db:seed --class=DemoSeeder --force # tambah HANYA untuk demo lokal
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
# terminal lain (agar email notifikasi terkirim):
php artisan queue:work
# (opsional, agar eskalasi SLA jalan lokal):
php artisan schedule:work
```

Frontend (`../JS/SixAgov2`): `npm install`, `npm run dev`.
Login demo (hanya bila `DemoSeeder` dijalankan, password `password123`):
`budi@` (Employee), `hendra@` (Line Manager), `michael@` (Division Head),
`ratna@` (Finance), `admin@sixago.local` (Super Admin).

## 2. Checklist Wajib Sebelum Deploy Production

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` terisi (hasil `key:generate`).
- [ ] Database & user khusus production (bukan root); backup terjadwal di level server/DB.
- [ ] Kredensial admin awal: isi `ADMIN_EMAIL/ADMIN_NIK/ADMIN_PASSWORD` (min. 8 karakter),
      login, lalu ganti password. **JANGAN** jalankan `DemoSeeder` di production
      (otomatis diblokir bila `APP_ENV=production`).
- [ ] Ganti semua password akun bawaan; nonaktifkan akun yang tidak dipakai.
- [ ] `MAIL_MAILER=smtp` + `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION`
      + `MAIL_FROM_ADDRESS` resmi. Uji: lakukan 1x approve → email harus masuk.
- [ ] Queue worker selalu jalan (Supervisor/systemd): `php artisan queue:work --tries=3`.
- [ ] Scheduler cron tiap menit:
      `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- [ ] `php artisan storage:link` di server; pastikan direktori `storage/` writable.
- [ ] HTTPS aktif; `APP_URL` & `FRONTEND_URL` pakai domain production;
      `SANCTUM_STATEFUL_DOMAINS` mencakup domain frontend bila dipakai.
- [ ] `CACHE_STORE`/`SESSION_DRIVER` disesuaikan kebutuhan (default database OK).
- [ ] Hapus file dev: `reset-demo-tmp.php` (tidak ada di repo), pastikan `.env`
      tidak ter-commit (`git status` bersih dari `.env`).
- [ ] Jalankan test: `php artisan test` (harus hijau).

## 3. Operasional Rutin

| Tugas | Perintah / Lokasi |
|---|---|
| Eskalasi SLA antrean | Otomatis via scheduler (`app:sla-escalate` tiap jam); toggle di halaman Approval Level → `PUT /api/settings` |
| Melihat audit trail | `GET /api/exports/audit-logs` (SA) atau stream di halaman Delegasi |
| Logout semua perangkat | `POST /api/logout-all` |
| Token API kedaluwarsa | `SANCTUM_EXPIRATION` (menit, default 720) |
| Rate limit login | 10x/menit (`throttle:10,1` di `routes/api.php`) |
| Ganti password | `POST /api/user/password`; flag `must_change_password` memaksa di FE |

## 4. Batasan yang Disadari (by design)

- Tanpa SSO (tombol dihapus sesuai keputusan).
- Tanpa integrasi bank H2H — pencairan dicatat via nomor referensi + file bukti.
- Notifikasi eksternal hanya email (tanpa WhatsApp/push browser).
- Impor users via CSV; ekspor via CSV (tanpa XLSX/PDF).
