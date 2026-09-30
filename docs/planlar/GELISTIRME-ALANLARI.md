# Geliştirme alanları (alternatifler)

Takvime alınmamış, değerlendirilmiş fikirler. Her biri uygulanmadan önce ayrı plan ve onay ister.
Son güncelleme: 2026-10-01.

| Alan | Kısa açıklama | Değerlendirme | Ayrıntı |
| --- | --- | --- | --- |
| Ortak ağ (kardeş siteler) | Yerelde sonuç yoksa agent'a iş ortağı sitedeki uygun ilanı kaynak ve tarihle göstermek; llms.txt'de ortak siteler | Değerli ama ağ içi senaryoya bağlı; voltkab ↔ intekar denemesiyle sınanabilir | [oneri-ortak-ag.md](oneri-ortak-ag.md) |
| OpenAPI 3.1 + llms-full.txt | AI Katalog REST için tek OpenAPI belgesi (fonksiyon çağıran agentlar, ChatGPT Actions); katalogun tam Markdown dökümü | Kanıtlanmış standartlar, düşük maliyet | – |
| Semantik HTML kontrolü | Uyum taramasına: ana içerik `<main>`/`<article>` içinde mi, JavaScript olmadan metin var mı (HTML standardı, WCAG) | Tema sayfaları ağır (makedonya.tr ana sayfa 163 KB, 25 betik, `<main>` yok); temaya dokunmadan öneri verir | – |
| KDV bilgisi | Fiyatın KDV dahil olup olmadığı (`valueAddedTaxIncluded`) | Küçük; B2B fiyat netliği | – |
| Imunify "Strict" uyarısı | Entegrasyonlar ekranı Imunify Bot Protection ayarını gösterir, Strict ise uyarır | Varsayılan (Balanced) yeterli çıktı; düşük öncelik | – |
| Sayfaların Markdown hâli (`Accept: text/markdown`) | Tema sayfalarını agentlara Markdown olarak sunmak | Bekletildi: agentların bunu istediğine kanıt az, temaya bağımlı; katalog için llms.txt ve /ai-katalog/ var | – |

Yapılmaması önerilenler (gerekçesiyle): agentlara özel 303/307 yönlendirme ("cloaking"), `.well-known/ai-plugin.json`
(OpenAI eklenti sistemi 2024'te kapandı), başka firmanın teklifini kendi JSON-LD'mize koymak.
