# Geliştirme alanları (alternatifler)

Takvime alınmamış, değerlendirilmiş fikirler. Her biri uygulanmadan önce ayrı plan ve onay ister.
Son güncelleme: 2026-10-01.

| Alan | Kısa açıklama | Değerlendirme | Ayrıntı |
| --- | --- | --- | --- |
| Ortak ağ (kardeş siteler) | Yerelde sonuç yoksa agent'a iş ortağı sitedeki uygun ilanı kaynak ve tarihle göstermek; llms.txt'de ortak siteler | Değerli ama ağ içi senaryoya bağlı; voltkab ↔ intekar denemesiyle sınanabilir | [oneri-ortak-ag.md](oneri-ortak-ag.md) |
| Balkan portal ağı | 5 turizm portalı (kosova, yunanistan, makedonya, arnavutluk, sirbistan .org.tr) ortak çalışsın: karşılıklı ağ, Schema.org çatı kuruluş, llms.txt kardeşler, AI cevaplarında kardeş önerisi, çok ülkeli turlar, UTM'li tıklama bloğu, ağ raporu | Kullanıcı isteği (2026-10-04); 3 fazlı öneri, karar bekliyor | [oneri-portal-agi.md](oneri-portal-agi.md) |
| ACP ve UCP (agent ticaret protokolleri) | OpenAI/Stripe'ın ACP'si ve Google/Shopify'ın UCP'si: agentların sepet ve ödemeyi tamamlaması; UCP MCP ve A2A üzerinden de çalışır | Araştırıldı (resmi belgeler, 2026-10-01): ikisinde de B2B/teklif yok. ACP beslemesi onaylı satıcıların OpenAI'ye gönderdiği dosya → bize uygun değil. UCP açık keşif (`/.well-known/ucp`), MCP/A2A bağları, ödemesiz katalog mümkün → tetikleyici gelirse "yalnızca katalog" denemesi. Ödeme/sepet önerilmez | [oneri-acp-ucp.md](oneri-acp-ucp.md) |
| OpenAPI 3.1 | AI Katalog REST için tek OpenAPI belgesi (fonksiyon çağıran agentlar, ChatGPT Actions); service-desc bunu gösterir | Yapıldı: 1.18.0 | [1.18.0 planı](1.18.0-openapi-ve-llms-full.md) |
| Semantik HTML kontrolü | Uyum taramasına: ana içerik `<main>`/`<article>` içinde mi, JavaScript olmadan metin var mı (HTML standardı, WCAG) | Planlandı: 1.19.0 (puana dahil olmayan öneri; JavaScript'siz okunabilirlik zaten `ReadabilityCheck`'te) | [1.19.0 planı](1.19.0-semantik-yapi.md) |
| llms-full.txt (büyük kataloglar) | Katalog büyüyünce (ör. 50+ ilan) llms.txt'yi özet yapıp tam listeyi /llms-full.txt'ye taşımak | llms.txt önerisinin parçası değil (teamül); bugün llms.txt zaten tüm ilanları içeriyor. Pilotlar küçük | [1.18.0 planı](1.18.0-openapi-ve-llms-full.md) |
| KDV bilgisi | Fiyatın KDV dahil olup olmadığı (`valueAddedTaxIncluded`) | Küçük; B2B fiyat netliği | – |
| Imunify "Strict" uyarısı | Entegrasyonlar ekranı Imunify Bot Protection ayarını gösterir, Strict ise uyarır | Kullanıcı isteğiyle genişletildi: Güvenlik yazılımları sekmesi | [1.21.0 planı](1.21.0-guvenlik-sekmesi.md) |
| robots.txt'de bilgi yorumu ve Link başlığı | robots.txt'ye tarafsız bir yorum satırı (`# AI agentlar için makine okunur katalog: …/llms.txt`) ve yanıtına RFC 8288 `Link` başlıkları (describedby, service-desc) | Zararsız ama faydası kanıtsız: tarayıcılar robots.txt'yi kural için ayrıştırır, dil modeline vermez. Emir kipinde metin ("ATTENTION AI AGENTS…") istem enjeksiyonuna benzer, güveni düşürebilir; yapılırsa yalnızca tarafsız bilgi. Düşük öncelik | – |
| Sayfaların Markdown hâli (`Accept: text/markdown`) | Tema sayfalarını agentlara Markdown olarak sunmak | Bekletildi: agentların bunu istediğine kanıt az, temaya bağımlı; katalog için llms.txt ve /ai-katalog/ var | – |

Yapılmaması önerilenler (gerekçesiyle): agentlara özel 303/307 yönlendirme ("cloaking"), `.well-known/ai-plugin.json`
(OpenAI eklenti sistemi 2024'te kapandı), başka firmanın teklifini kendi JSON-LD'mize koymak, robots.txt'de
`Sitemap: …/llms.txt` (Sitemap yönergesi sitemaps.org biçimindeki site haritası içindir; llms.txt Markdown'dır, arama
motorları hata sayar), uydurma başlıklar (`X-AI-Context`, `X-AI-Agent-Interface`), kayıtlı olmayan
`/.well-known/openapi.json` (standart yol: `rel="service-desc"`, 1.18.0).
