# Pilot siteler

İlk tarama: 2026-09-29, `php bin/tara` (eklentinin kendi puanlaması, **puanlama sürümü 4**; eklenti kurulmadan). Raporlar:
[raporlar/](raporlar/). Durum sütununu kurulum ilerledikçe güncelleyin.

| Site | PRD rolü | Önerilen kurulum | Platform | AI uyum puanı | Önbellek | SEO | Çoklu dil | Notlar | Durum |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| voltkab.com | **Kablo dağıtıcı** (ilk pilot) | Ürün (`urun`) + eşleştirme | WordPress, PHP 8.3 | 69 | WP Rocket | Rank Math | GTranslate | A2A canlı demosunun alıcı ucu (aranan ilan). GTranslate dil listesi okunamaz; diller elle. | Kurulmadı |
| intekarglobal.com | **Kablo fabrikası** | Ürün (`urun`) | WordPress 7.1.2 | 42 | WP Rocket | – | – | A2A canlı demosunun satıcı ucu. Hiçbir sayfada JSON-LD yok: en çok kazanacak sitelerden. Imunify360. | Kurulmadı |
| balkantrade.com.tr | İhracat firması | İhracatçı (`ihracat`) | WordPress 7.1.2 | 71 | WP Rocket | Rank Math | **TranslatePress** | 1.12.0 TranslatePress desteği bunun için; canlıda denenmeli. Imunify360. | Kurulmadı |
| erenlegal.com.tr | Hukuk firması | Hizmet, yalnızca okuma (`hizmet`) | WordPress | 55 (llms.txt ölçülemedi) | WP Rocket | – | – | PRD: yalnızca okuma, metni firma onaylar. `/llms.txt` isteği tamamlanmadı (Imunify360 olabilir). | Kurulmadı |
| makedonya.tr | Turizm firması / deneme | Tur operatörü (`tur`) | WordPress 7.1.2 | **94** (eklentiyle, 1.8.0) | WP Rocket | Rank Math | – | Deneme sitesi. Barındırma hız sınırı (~20 istek → 1 saat 429), Imunify360. | Kurulu, deneme sürüyor |
| kuzeymakedonya.com.tr | Turizm portalı | Tur / portal (karar) | WordPress 7.1.2 | 72 | WP Rocket | Rank Math | – | Imunify360. | Kurulmadı |
| kosova.org.tr | Turizm portalı | Tur / portal (karar) | WordPress | 42 | LiteSpeed Cache | Rank Math | – | Ana sayfada JSON-LD yok. | Kurulmadı |
| arnavutluk.org.tr | Turizm portalı | Tur / portal (karar) | WordPress 7.1.2 | 68 | WP Rocket | Rank Math | – | Imunify360. | Kurulmadı |
| makedonya.org.tr | Turizm portalı | Tur / portal (karar) | WordPress 7.1.2 | 74 (llms.txt ölçülemedi) | WP Rocket | Rank Math | – | `/llms.txt` isteği tamamlanmadı (Imunify360 olabilir). | Kurulmadı |
| sirbistan.org.tr | Turizm portalı | Tur / portal (karar) | WordPress 7.1.2 | 38 | LiteSpeed Cache | – | – | Ana sayfada JSON-LD yok, SEO eklentisi yok: en çok kazanacak site. Imunify360. | Kurulmadı |

Ortak gözlemler:
- Sitelerin çoğunda başka bir MCP sunucusu (`/wp-json/mcp`) var ama kimlik doğrulama istiyor (AI agentlara kapalı); puanlama
  sürümü 4 bunu ve yalnızca çekirdek REST API'yi artık tam puanla saymıyor (sürüm 3'te eklentisiz siteler 82–85 alıyordu). Eksik olanlar
  tutarlı: **llms.txt yok, A2A kartviziti yok, ürün/ilan verisi yok**; intekarglobal.com ve kosova.org.tr'de yapılandırılmış
  veri de yok. Eklentimizin kattığı değer bunlar + AI Katalog (ne satıyor/arıyor/tedarik ediyor) + ölçüm.
- Tüm sitelerde WP Rocket ya da LiteSpeed var: kurulumda "AI botlarına önbellekten sayfa sunma" ölçüm döneminin başında
  açılmalı.
- Çoğu sitede Imunify360 bot koruması var: barındırmadan AI bot ve `/llms.txt`, `/wp-json/aihs/`, `/.well-known/` istisnası
  istenmeli.
- **Faz 4 canlı A2A demosu:** PRD'deki "fabrika ile dağıtıcı agentlarının konuşması" = intekarglobal.com (satılan ilan) ↔
  voltkab.com (aranan ilan + eşleştirme + A2A ile teklif isteği). Yerel provası: `tools/a2a-demo`.
- PRD 10 site hedefi: **10 site listede** (dağıtıcı, fabrika, ihracat, hukuk, turizm firması ve 5 turizm portalı).
