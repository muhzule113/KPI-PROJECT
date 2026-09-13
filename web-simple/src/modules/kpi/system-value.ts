// Resolver untuk indikator yang nilainya berasal dari sistem lain, bukan dari entri Supervisor.
// Belum ada sumber data yang terhubung, sehingga jawabannya jujur: MISSING ("data belum tersedia").
// Saat integrasi ditambahkan, fungsi ini menerima konteks (kode indikator + tanggal) dan mengisi
// nilai dari sistem sumber — satu tempat perubahan.
export type SystemValueResult = { status: "AVAILABLE"; value: number } | { status: "MISSING" };

export function resolveSystemValue(): SystemValueResult {
  return { status: "MISSING" };
}
