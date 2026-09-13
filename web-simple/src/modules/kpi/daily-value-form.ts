// Satu entri per indikator: `na:` berarti tidak berlaku, `category:` memilih kategori penilaian,
// `value:` memuat angka mentah. Field kosong tetap dikirim sebagai PENDING agar sistem dapat
// membedakan "belum diisi" dari nilai nol.
export function dailyValuesFromForm(formData: FormData, subject = "nilai indikator") {
  return formData.getAll("itemId").map(String).map((itemId) => {
    if (formData.get(`na:${itemId}`) !== null) return { itemId, status: "NOT_APPLICABLE" as const };
    const category = formData.get(`category:${itemId}`);
    if (category !== null) {
      const categoryOptionId = String(category).trim();
      return categoryOptionId ? { itemId, status: "AVAILABLE" as const, categoryOptionId } : { itemId, status: "PENDING" as const };
    }
    const raw = formData.get(`value:${itemId}`);
    const text = raw === null ? "" : String(raw).trim();
    if (!text) return { itemId, status: "PENDING" as const };
    const value = Number(text);
    if (!Number.isFinite(value)) throw new Error(`Seluruh ${subject} wajib berupa angka.`);
    return { itemId, status: "AVAILABLE" as const, value };
  });
}
