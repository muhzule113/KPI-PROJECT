# KPI Project

Versi sederhana terbaru berada di [`web-simple/`](./web-simple). Aplikasi ini memakai Next.js, TypeScript, PostgreSQL, Prisma, Tailwind CSS, shadcn/ui, dan Better Auth. Satu web responsif dipakai dari komputer maupun ponsel; tidak ada Flutter atau proses otomatis dari sistem servis/stok.

Mulai dari folder tersebut:

```powershell
cd web-simple
Copy-Item .env.example .env
docker compose up -d
npm ci
npm run db:deploy
npm run db:seed
npm run dev
```

Folder `web/` adalah implementasi Next.js yang lebih lengkap sebelumnya. Folder `backend/` dan `mobile/` adalah referensi Laravel/Flutter legacy. Semuanya tetap dipertahankan dan tidak dipakai oleh runtime `web-simple/`.

Petunjuk lengkap dan seluruh akun demo tersedia di [`web-simple/README.md`](./web-simple/README.md).
