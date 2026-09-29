# AI uyum taraması: makedonya.org.tr

- Tarih: 2026-09-29T15:37:14Z · Puanlama sürümü: 4 · Ölçülen ağırlık: 85/100
- **AI uyum puanı: 74/100** (yalnızca ölçülebilen kontrollerin üzerinden)

| Kontrol | Puan | Bulgular |
| --- | --- | --- |
| Yapılandırılmış veri | 20/20 | 7/7 sayfada eksiksiz yapılandırılmış veri var. |
| İçerik okunabilirliği | 20/20 |  |
| Makine arayüzü (REST / MCP) | 5/20 | REST API keşfedilebilir: https://www.makedonya.org.tr/wp-json/ Yalnızca WordPress'in çekirdek API'si var (yazılar, sayfalar); ürün ya da ilan verisi sunan bir API yok. |
| AI bot erişimi | 7.5/15 | robots.txt şu AI botlarını engelliyor: GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, CCBot, Amazonbot, Bytespider, meta-externalagent, meta-externalfetcher, meta-webindexer, MistralAI-Training, MistralAI-Index, MistralAI-User, DuckAssistBot. |
| llms.txt | ölçülemedi | /llms.txt adresine erişilemedi. |
| Tazelik | 10/10 | Yapılandırılmış verisi olan 7 sayfanın 7 tanesinde güncelleme veya geçerlilik tarihi var. |
| İleri standartlar (A2A kartviziti) | ölçülemedi | /.well-known/agent-card.json adresine erişilemedi. |

## Kurulum ön kontrolü

| Konu | Durum |
| --- | --- |
| Ana sayfa | 200 |
| WordPress | 7.1.2 |
| PHP / sunucu | – / gws |
| REST API | açık |
| Önbellek eklentisi | WP Rocket, LiteSpeed sunucusu (eklenti belirsiz) |
| SEO eklentisi | Rank Math |
| Çoklu dil eklentisi | – |
| Başka MCP sunucusu | var (/wp-json/mcp) |
| AI Hazır Site | yok |
| Sunucu bot koruması | Imunify360 izi |
