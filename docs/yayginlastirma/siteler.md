# Pilot siteler

İlk tarama: 2026-09-29, `php bin/tara` (eklentinin kendi puanlaması, sürüm 3; eklenti kurulmadan). Raporlar:
[raporlar/](raporlar/). Durum sütununu kurulum ilerledikçe güncelleyin.

| Site | PRD rolü | Önerilen kurulum | Platform | AI uyum puanı | Önbellek | SEO | Çoklu dil | Notlar | Durum |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| voltkab.com | **Kablo dağıtıcı** (ilk pilot) | Ürün (`urun`) + eşleştirme | WordPress, PHP 8.3 | 82 | WP Rocket | Rank Math | GTranslate | A2A canlı demosunun alıcı ucu (aranan ilan). GTranslate dil listesi okunamaz; diller elle. | Kurulmadı |
| intekarglobal.com | **Kablo fabrikası** | Ürün (`urun`) | WordPress 7.1.2 | 55 | WP Rocket | – | – | A2A canlı demosunun satıcı ucu. Hiçbir sayfada JSON-LD yok: en çok kazanacak sitelerden. Imunify360. | Kurulmadı |
| balkantrade.com.tr | İhracat firması | İhracatçı (`ihracat`) | WordPress 7.1.2 | 84 | WP Rocket | Rank Math | **TranslatePress** | 1.12.0 TranslatePress desteği bunun için; canlıda denenmeli. Imunify360. | Kurulmadı |
| erenlegal.com.tr | Hukuk firması | Hizmet, yalnızca okuma (`hizmet`) | WordPress | 64 (llms.txt ölçülemedi) | WP Rocket | – | – | PRD: yalnızca okuma, metni firma onaylar. `/llms.txt` isteği tamamlanmadı (Imunify360 olabilir). | Kurulmadı |
| makedonya.tr | Turizm firması / deneme | Tur operatörü (`tur`) | WordPress 7.1.2 | **94** (eklentiyle, 1.8.0) | WP Rocket | Rank Math | – | Deneme sitesi. Barındırma hız sınırı (~20 istek → 1 saat 429), Imunify360. | Kurulu, deneme sürüyor |
| kuzeymakedonya.com.tr | Turizm portalı | Tur / portal (karar) | WordPress 7.1.2 | 85 | WP Rocket | Rank Math | – | Imunify360. | Kurulmadı |
| kosova.org.tr | Turizm portalı | Tur / portal (karar) | WordPress | 55 | LiteSpeed Cache | Rank Math | – | Ana sayfada JSON-LD yok. | Kurulmadı |
| (diğer gezi portalları) | Turizm portalları | Portal (`portal`) | ? | – | – | – | – | Adresleri eklenecek; `bin/tara` ile taranır. | – |

Ortak gözlemler:
- Sitelerin çoğunda başka bir MCP sunucusu (`/wp-json/mcp`) var; eklentisiz puanlar bu yüzden yer yer yüksek. Eksik olanlar
  tutarlı: **llms.txt yok, A2A kartviziti yok, ürün/ilan verisi yok**; intekarglobal.com ve kosova.org.tr'de yapılandırılmış
  veri de yok. Eklentimizin kattığı değer bunlar + AI Katalog (ne satıyor/arıyor/tedarik ediyor) + ölçüm.
- Tüm sitelerde WP Rocket ya da LiteSpeed var: kurulumda "AI botlarına önbellekten sayfa sunma" ölçüm döneminin başında
  açılmalı.
- Çoğu sitede Imunify360 bot koruması var: barındırmadan AI bot ve `/llms.txt`, `/wp-json/aihs/`, `/.well-known/` istisnası
  istenmeli.
- **Faz 4 canlı A2A demosu:** PRD'deki "fabrika ile dağıtıcı agentlarının konuşması" = intekarglobal.com (satılan ilan) ↔
  voltkab.com (aranan ilan + eşleştirme + A2A ile teklif isteği). Yerel provası: `tools/a2a-demo`.
- PRD 10 site hedefi: listede 7 site var; kalan 3 site için diğer gezi portalları eklenecek.
