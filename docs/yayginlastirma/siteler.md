# Pilot siteler

İlk tarama: 2026-09-29, `php bin/tara` (eklentinin kendi puanlaması, sürüm 3; eklenti kurulmadan). Raporlar:
[raporlar/](raporlar/). Durum sütununu kurulum ilerledikçe güncelleyin.

| Site | Tür (önerilen kurulum) | Platform | AI uyum puanı | Önbellek | SEO | Çoklu dil | Notlar | Durum |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| makedonya.tr | Tur operatörü (tur) | WordPress 7.1.2 | **94** (eklentiyle, 1.8.0) | WP Rocket | Rank Math | – | Deneme sitesi. Barındırma hız sınırı (~20 istek → 1 saat 429), Imunify360. | Kurulu, deneme sürüyor |
| balkantrade.com.tr | İhracatçı (ihracat) | WordPress 7.1.2 | 84 | WP Rocket | Rank Math | **TranslatePress** | 1.12.0 TranslatePress desteği bunun için; canlıda denenmeli. Imunify360. | Kurulmadı |
| voltkab.com | Ürün (urun) | WordPress, PHP 8.3 | 82 | WP Rocket | Rank Math | GTranslate | GTranslate dil listesi okunamaz; diller elle. | Kurulmadı |
| kuzeymakedonya.com.tr | Tur / portal (karar) | WordPress 7.1.2 | 85 | WP Rocket | Rank Math | – | Imunify360. A2A canlı demosunda ikinci uç adayı. | Kurulmadı |
| kosova.org.tr | Tur / portal (karar) | WordPress | 55 | LiteSpeed Cache | Rank Math | – | Ana sayfada JSON-LD yok; en çok kazanacak site. | Kurulmadı |
| erenlegal.com | Hizmet, yalnızca okuma (hizmet) | **Next.js** | 35 | – | – | – | WordPress değil: eklenti kurulamaz. Seçenek: başka platform adaptörü (ADR-001) ya da statik llms.txt + JSON-LD. | Kapsam dışı (şimdilik) |

Ortak gözlemler:
- WordPress sitelerin hepsinde başka bir MCP sunucusu (`/wp-json/mcp`) ve Rank Math var; eklentisiz puanın yüksekliği
  (82–85) büyük ölçüde bunlardan. Eksik olanlar tutarlı: **llms.txt yok, A2A kartviziti yok, ürün/ilan verisi yok**.
  Eklentimizin kattığı değer tam bu üçü + AI Katalog (ne satıyor/arıyor/tedarik ediyor) + ölçüm.
- Dört sitede WP Rocket var: kurulumda "AI botlarına önbellekten sayfa sunma" ölçüm döneminin başında açılmalı.
- PRD 10 site hedefi: listede 5 WordPress sitesi var; kablo dağıtıcı, kablo fabrikası ve turizm portalları henüz
  listede yok (PRD pilot ağ tablosu).
