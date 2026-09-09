import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "KPI OPS",
    short_name: "KPI OPS",
    description: "Sistem KPI dan operasional toko serta servis HP.",
    start_url: "/app",
    display: "standalone",
    background_color: "#f8faf9",
    theme_color: "#047857",
    lang: "id",
    icons: [{ src: "/favicon.ico", sizes: "any", type: "image/x-icon" }],
  };
}
