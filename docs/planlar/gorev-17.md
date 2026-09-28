# Görev 17 – U5 Yeni standartlara uyum: araştırma ve öneri listesi (onay bekliyor)

> Görev dosyası: "Başlamadan önce güncel durum araştırması … liste kullanıcı onayına sunulur." Bu belge o listedir.
> `gorev-17` dalında **puanlamaya dokunulmadı** (`score_version` 2 kaldı). Bot listesi güncellemesi mevcut testlerin
> beklentilerini değiştirdiği için ayrı dalda: `gorev-17-bot-listesi` (aşağıda §3).
> Araştırma tarihi: 2026-09-28.

## 1. Standartların güncel durumu

| Standart | Durum (Eylül 2026) | Eklentide | Öneri |
| --- | --- | --- | --- |
| robots.txt (RFC 9309) | Kararlı (RFC) | U1 `bot_access`, U2 | Değişiklik yok |
| Schema.org / JSON-LD | Kararlı, yaygın | A2, U1 `structured_data` | Değişiklik yok |
| MCP | Yaygın; oturumsuz HTTP taşıma kullanılıyor | A6, U1 `machine_interface` | Değişiklik yok |
| A2A kartviziti | **v1.0 kararlı** (Linux Foundation); adres `/.well-known/agent-card.json` | U1 `advanced` zaten v1.0 alanlarını denetliyor | Değişiklik yok; A12'de yayın |
| WebMCP (`document.modelContext`) | W3C **Topluluk Grubu taslağı** (Şubat 2026); yalnızca Chrome'da deneme; API `navigator`'dan `document`'a taşınıyor | Yok | **Eklenmesin** (taslak, API değişiyor). Yeniden bakış: tarayıcılar arası kararlı sürüm. |
| IETF AIPREF (`Content-Usage` başlığı ve robots.txt kuralı) | Çalışma grubu taslakları (vocab-08, attach-05); IESG'ye gönderim aşamasında; henüz RFC değil | Yok | **Puanlamaya eklenmesin**; RFC olunca U2'ye "AI kullanım tercihi" ayarı olarak eklenebilir. |
| Cloudflare Content Signals (`Content-Signal:` robots.txt) | Yaygın (Cloudflare yönetimli robots.txt), standart değil; büyük AI firmalarının belgelerinde geçmiyor | Yok | **Eklenmesin** (etkisi kanıtlanmadı). |
| llms.txt | Topluluk önerisi; ~%10 site kullanıyor; ölçümlerde AI tarayıcıların isteklerinin ~%0,1'i | A3, U1 `llms_txt` (ağırlık 10) | **Ağırlık tartışılmalı** (aşağıda §2). |

## 2. Karar bekleyen puanlama önerisi (uygulanmadı)

- `llms_txt` ağırlığı 10 → 5, farkın `structured_data` (20 → 25) ağırlığına aktarılması önerilir. Gerekçe: büyük AI
  tarayıcıları llms.txt'i neredeyse hiç istemiyor; yapılandırılmış veri ve HTML her ziyarette okunuyor.
- Uygulanırsa `score_version` 3 olur; eski taramalar kendi sürümüyle gösterilmeye devam eder (rapor zaten farklı
  sürümleri karşılaştırmıyor). Görev dosyasındaki "eski ve yeni puan yan yana" gösterimi o zaman eklenir.
- Bu değişiklik mevcut puanlama testlerinin beklentilerini değiştirir; bu yüzden onayınız olmadan yapılmadı.

## 3. Bot listesi güncellemesi (`gorev-17-bot-listesi` dalı)

Resmi belgelerden doğrulanan 6 yeni kayıt:

| Bot | Firma | Tür | Doğrulama | Kaynak |
| --- | --- | --- | --- | --- |
| MistralAI-Training | Mistral AI | training | yok | docs.mistral.ai/robots |
| MistralAI-Index | Mistral AI | search | IP listesi (mistral.ai/mistralai-index-ips.json) | docs.mistral.ai/robots |
| MistralAI-User | Mistral AI | user_agent | IP listesi (mistral.ai/mistralai-user-ips.json) | docs.mistral.ai/robots |
| DuckAssistBot | DuckDuckGo | search | IP listesi (duckduckgo.com/duckassistbot.json) | duckduckgo.com/…/duckassistbot |
| meta-externalfetcher | Meta | user_agent | yok | developers.facebook.com/…/web-crawlers |
| meta-webindexer | Meta | search | yok | developers.facebook.com/…/web-crawlers |

IP listelerinin biçimi mevcut ayrıştırıcıyla aynı (`prefixes[].ipv4Prefix`).

**Etkisi:** bot erişimi puanı bot sayısına göre hesaplandığından aynı site için oran biraz değişir; U2 hazır ayar
çıktılarına yeni botlar eklenir. Bu yüzden o dalda `score_version` 3'e çıkarıldı ve şu test beklentileri güncellendi
(hiçbiri gevşetilmedi; yalnızca 12 → 18 bot):
- `tests/Snapshots/robots/*.txt` (3 dosya; yalnızca satır eklendi),
- `BotAccessPolicyTest` (2 oran), `ChecksTest::test_partial_results` (1 oran),
- `ScannerTest` (`SCORE_VERSION` 3), `CompliancePageTest` ("Puanlama sürümü: 3"),
- `VerificationTest` (indirilen IP listesi sayısı 8 → 11).

**Mevcut test değişikliği olduğu için onayınız gerekir.** Onaylanırsa ilk taraması sürüm 2 olan sitelerde rapor,
farklı puanlama sürümü nedeniyle önce/sonra karşılaştırmasını göstermez (tasarım gereği).

## 4. Kaynaklar
- WebMCP: W3C Draft Community Group Report (Şubat 2026); patrickbrosset.com/articles/2026-02-23-webmcp-updates-clarifications-and-next-steps/
- A2A: a2a-protocol.org/latest/specification/ (v1.0, `/.well-known/agent-card.json`)
- IETF AIPREF: datatracker.ietf.org/doc/draft-ietf-aipref-vocab/ , datatracker.ietf.org/doc/draft-ietf-aipref-attach/
- Content Signals: Cloudflare duyurusu (24 Eylül 2025); Search Engine Roundtable (Temmuz 2026, Google yorumu)
- llms.txt kullanımı: SE Ranking 300.000 alan adı çalışması; AI bot ziyaret ölçümleri (2026)
- Botlar: docs.mistral.ai/robots; duckduckgo.com/duckduckgo-help-pages/results/duckassistbot; developers.facebook.com/docs/sharing/webmasters/web-crawlers/
