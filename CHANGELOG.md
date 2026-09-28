# Değişiklik Günlüğü

Bu projedeki önemli değişiklikler bu dosyada tutulur.
Biçim [Keep a Changelog](https://keepachangelog.com/tr-TR/1.1.0/) esaslıdır, sürümler [SemVer](https://semver.org/lang/tr/) izler.

## [1.7.0] - 2026-09-28

### Eklendi
- **Sayfalardan keşif** (`discovery` anahtarı, varsayılan kapalı). makedonya.tr denemesinde iki ayrı AI agent yalnızca
  sayfaları okudu, `/llms.txt` ve API'ye kendiliğinden bakmadı ve "yok" dedi. Plan: `docs/planlar/1.7.0-kesif.md`.
  - Ön yüzdeki her sayfada `<link>` öğeleri ve yanıtta HTTP `Link` başlığı (RFC 8288): llms.txt için
    `rel="describedby"` (llms.txt önerisi, llmstxt.org), REST API için `rel="service-desc"` (RFC 8631). Yalnızca açık
    olan kaynaklar duyurulur; A2A kartı, A2A yalnızca `/.well-known/` ile keşfedildiği için bağlanmaz.
  - İsteğe bağlı görünür satır (sayfa altı): "AI asistanları için: AI Katalog · llms.txt" (ayrı ayar, varsayılan kapalı).
  - Ayarlar: AI Katalog → Firma Profili → "AI keşif" (yetki ve nonce denetimli).
  - Kaldırmada `aihs_discovery_visible` silinir.
  - Testler: `DiscoveryLinksTest`, `DiscoveryTest`.

### Test güncellemesi (onay bekliyor)
- `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `discovery` (`false`).

## [1.6.1] - 2026-09-28

Gerçek site denemesinde (makedonya.tr: WordPress 7.1.2, Rank Math, WP Rocket) bulunanlar.

### Düzeltildi
- Firma profilinin adı boşken Schema.org çıktısı "Organization: name zorunlu" hatası veriyor ve yayınlanmıyordu;
  artık llms.txt ve A2A kartındaki gibi site adı kullanılır. Yeni test: `SiteNameFallbackTest`.
- Sayfa önbelleği eklentileri (WP Rocket vb.) `/ai-katalog/` sayfasının eski halini sunmaya devam ediyordu. Kendi
  sunduğumuz çıktılar (`/ai-katalog/`, işletme sayfaları, `/llms.txt`, doğrulama sayfası, A2A kartı) artık önbellek
  eklentilerinin ortak kuralı `DONOTCACHEPAGE` ile işaretlenir. Güncellemeden sonra önbellek bir kez temizlenmelidir.
  Yeni testler: `PageCacheTest`, `PublicPagesNotCachedTest`.
- Tur ilanlarında müsaitlik, "Kalan yer" boşken genel miktardan hesaplanıyor ve "stokta" diye anlatılıyordu. Şablonun
  kendi müsaitlik alanı varsa yalnızca o kullanılır (boşsa "bilinmiyor"); gerekçe alanın adıyla yazılır
  ("Kalan yer: 6; istenen 4."). Yeni test: `TourAvailabilityTest`.

### Test güncellemesi (onaylı)
- `SchemaOutputTest::test_invalid_output_serves_last_valid_and_warns`: hatayı boş profil adıyla tetikliyordu; boş
  ad artık site adına döndüğü için site adı da boşaltılıyor. Doğrulamalar aynı.

## [1.6.0] - 2026-09-28

### Eklendi
- **A12 A2A kartviziti ve agent** (`a2a` anahtarı, varsayılan kapalı). Resmi belgeden doğrulama ve onay bekleyen
  kararlar: `docs/planlar/gorev-19.md` (A2A 1.0.0, a2a.proto).
  - `src/Adapters/A2A/`: `AgentCardBuilder`, `AgentCardValidator` (resmi zorunlu alanlar; yarım/eski kart asla
    yayınlanmaz), `JsonRpcServer` (`SendMessage`, `A2A-Version` 1.x, resmi hata kodları), `A2ASkills`.
  - `/.well-known/agent-card.json` yalnızca uç nokta ve en az bir beceri çalışırken; `POST /wp-json/aihs/a2a`.
  - Beceriler: `musaitlik-sor` (A6 `Availability`), `teklif-iste` (A7 `InquiryService`, kanal `a2a`, otomatik onay yok).
  - Giden: Eşleşmeler'den ortak siteye, mesajın tam önizlemesi ve kullanıcı onayıyla (tek kullanımlık belirteç);
    karşı kart ortak sitenin alan adından okunup doğrulanır. Yeni mimari testi `A2ASendsOnlyWithApprovalTest`.
  - Tüm gelen ve giden mesajlar denetim kaydında.
- Belgeler: `docs/kullanim/a2a.md` (uçtan uca demo adımları dahil).

### Test güncellemesi (onaylı)
- `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `a2a => false`; `TelemetrySendsOnlyWithConsentTest`
  izinli giden POST dosyalarına `A2AOutbox` eklendi.

## [1.5.0] - 2026-09-28

### Eklendi
- **A11 Eşleştirme motoru** (`matching` anahtarı, varsayılan kapalı). Plan ve onay bekleyen kararlar: `docs/planlar/gorev-18.md`.
  - Çekirdek `src/Core/Matching/`: `HardFilter` (tür, kategori, şablon, standart, bölge, teslim süresi sınırı),
    `Matcher` (S = Σ wᵢ·sᵢ, 0–100; ölçüt başına değer ve puan, toplam puanla birebir), `MatchWeights` (toplam 1,
    başlangıçta eşit), `PartnerListings` (ortak sitenin A5 REST yanıtı; güvenilmeyen veri).
  - **AI Katalog → Eşleşmeler**: aranan ilan seçimi, açıklamalı sonuçlar, elenenler ve nedenleri, ağırlık ve ortak
    site ayarları (yalnızca https). Ortak yanıtlar 1 saat önbellekte; erişilemeyen ortak atlanır, yerel eşleştirme sürer.
  - Eşleşmeler öneridir; hiçbir teklif veya mesaj gönderilmez.
- Belgeler: `docs/kullanim/eslestirme.md`.

### Test güncellemesi (onaylı)
- `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `matching => false` eklendi.

## [1.4.1] - 2026-09-28

### Düzeltildi
- Aynı saniyede güncellenen ilanların sırası sabit değildi (veritabanı eşitlikte rastgele dönebiliyordu); artık
  eşitlikte yeni kimlik önce gelir. JSON-LD, REST, llms.txt ve yönetim listeleri aynı çıktıyı verir.
  `PortalChannelsTest` CI'da bu yüzden ara sıra kırılıyordu. Yeni test: `ListingOrderTest`.

## [1.4.0] - 2026-09-28

### Eklendi
- **U5 Yeni standartlar araştırması** (`docs/planlar/gorev-17.md`): WebMCP, IETF AIPREF ve Content Signals henüz
  kararlı değil, puanlamaya eklenmedi; A2A v1.0 kararlı ve mevcut `advanced` kontrolüyle zaten denetleniyor;
  llms.txt ağırlığı için öneri (uygulanmadı).
- **AI bot listesi** 12 → 18 (resmi belgelerden): MistralAI-Training, MistralAI-Index, MistralAI-User, DuckAssistBot,
  meta-externalfetcher, meta-webindexer. Mistral ve DuckDuckGo botları resmi IP listeleriyle doğrulanır.

### Değişti
- Puanlama sürümü 3 (bot erişimi oranı yeni bot sayısıyla hesaplanır). Eski taramalar kendi sürümüyle gösterilir.

### Test güncellemesi (onaylı)
- robots.txt hazır ayar anlık görüntüleri (yalnızca yeni bot satırları), bot sayısına bağlı 3 oran, `SCORE_VERSION`
  3, puanlama sürümü metni, indirilen IP listesi sayısı (8 → 11). Ayrıntı: `docs/planlar/gorev-17.md` §3.

## [1.3.0] - 2026-09-28

### Eklendi
- **A10 Merkezi güncelleme ve rapor paneli** (`remote_updates`, `telemetry` anahtarları, varsayılan kapalı). Plan ve onay
  bekleyen kararlar: `docs/planlar/gorev-16.md`.
  - Çekirdek `src/Core/Updates/`: `ReleaseManifest` (wp-update-server / Plugin Update Checker alanları + `releases`,
    yalnızca https), `CanaryPolicy` (pilot hemen, genel 48 saat sonra; geri alma hedefi), `RollbackService` (önce şema
    `down()`, sonra paket).
  - WordPress: `pre_set_site_transient_update_plugins` ve `plugins_api` ile standart güncelleme ekranı; önceki pakete
    `Plugin_Upgrader` (`overwrite_package`) ile dönüş. Sunucu adresi yoksa hiçbir istek yok (`AIHS_UPDATE_SERVER`).
  - Rapor paneli: `TelemetryService` yalnızca anahtar + https adres + güncel bildirime açık onay varken gönderir;
    `TelemetrySummary` yalnızca toplamlar; rastgele site kimliği; onay geri alınabilir. Yeni mimari testi
    `TelemetrySendsOnlyWithConsentTest` (dışarı POST yalnızca bu servisten).
  - Lisans altyapısı: `LicenseChecker`, `FreeLicense` (hiçbir özellik kilitli değil).
  - **Ayarlar → AI Hazır Güncelleme**: sunucu, kanal, panel adresi, önceki sürüme dön, onay (gönderilecek verinin
    tam önizlemesiyle) ve onayı geri alma.
  - `bin/paketle` sunucu bildirimi için sürüm kaydı da üretir (`db_version` paketten okunur).
- Belgeler: `docs/kullanim/guncelleme.md`; bilinen sınırlar güncellendi.

### Test güncellemesi (onaylı)
- `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `remote_updates`, `telemetry` (ikisi de `false`) eklendi.

## [1.2.0] - 2026-09-28

### Eklendi
- **A9 Portal modu** (`portal_mode` anahtarı, varsayılan kapalı). Plan ve onay bekleyen kararlar: `docs/planlar/gorev-15.md`.
  - Çekirdek `src/Core/Portal/`: `Business` (adres kısaltması + `CompanyProfile`), `PortalService` (tek yazma noktası;
    işletme yetkilisi yalnızca kendi ilanına dokunur, ilanı olan işletme silinmez), `PortalReport` (işletme ve portal
    satırları toplamla tutarlı). Yeni mimari testi `PortalWritesOnlyThroughServiceTest`.
  - Saklama: işletmeler `aihs_businesses` seçeneğinde; ilanın işletmesi ilan kaydında `_aihs_business`; yetkilinin
    işletmesi kullanıcı meta verisinde `aihs_business`. Yeni tablo yok, kalıcı rol yok (`user_has_cap`).
  - Yönetim: **AI Katalog → İşletmeler** (işletmeler, yetkililer, ilan ataması, 28 günlük işletme raporu) ve
    **İşletmem** (yetkilinin kendi ilanları).
  - Çıktılar: `/ai-katalog/isletme/{kısaltma}/` işletme sayfası; katalogda işletme dizini; JSON-LD'de satıcı işletme;
    llms.txt'de işletme dizini; REST'te `business`, `?business=`, `/businesses`; MCP'de `business` girdisi ve
    `aihs/list-businesses`.
- Belgeler: `docs/kullanim/portal.md`; bilinen sınırlar güncellendi.
- Kaldırma (veriyi sil seçeneği açıkken): işletme yetkililerinin kullanıcı bağı (`aihs_business`) ve eklentinin tüm
  `aihs_*` geçici verileri (önbellekler, hız sınırı pencereleri, form durumu) de silinir. Başka eklentilerin verisine
  dokunulmaz (`PortalUninstallTest`).

### Değişmeyen
- Portal kapalıyken (işletme kayıtları olsa bile) tüm çıktılar birebir aynı (geriye uyumluluk testi).

### Test güncellemesi (onaylı)
- `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `portal_mode => false` eklendi.

## [1.1.0] - 2026-09-28

### Eklendi
- **A8 Çoklu dil** (`multilingual` anahtarı, varsayılan kapalı). Plan ve onay bekleyen kararlar: `docs/planlar/gorev-14.md`.
  - Çekirdek `src/Core/I18n/`: `LanguageSettings` (ISO 639-1, varsayılan dil ilk), `LanguageNegotiator`
    (açık parametre → `Accept-Language` q değerleri, RFC 9110 §12.5.4 / RFC 4647 lookup → varsayılan dil),
    `Localizer` (yalnızca girilmiş çeviriler; eksik alan varsayılan dilde kalır ve bildirilir).
  - Çevrilen alanlar: ilan `title`, `description`, `category`, `region`; profil `sector`.
  - Yazma tek noktadan: `CatalogService::save_listing_translation()` / `save_profile_translation()`;
    yeni mimari testi `TranslationWritesOnlyThroughServiceTest`.
  - Saklama: ilan çevirileri ilan kaydının meta verisinde (`_aihs_translations`, ilanla silinir), profil çevirisi
    `aihs_profile_translations`, dil ayarı `aihs_languages`. Yeni tablo yok.
  - Polylang (`pll_*` fonksiyonları) veya WPML (`wpml_*` süzgeçleri) varsa dil listesi onlardan okunur.
  - **AI Katalog → Çeviriler**: dil ayarı, profil çevirisi, ilan başına dil formu ve dil bazında durum.
  - Kanallar: REST (`?lang=` / `Accept-Language`, `Content-Language`, `Vary`), Abilities/MCP (`lang` girdisi),
    `/llms.txt?lang=`, `/ai-katalog/?lang=` (`hreflang`, `lang` özniteliği, JSON-LD `inLanguage`). Her yanıtta
    `translation {language, missing, fallback_language}` işareti.
- Belgeler: `docs/kullanim/coklu-dil.md`; bilinen sınırlar güncellendi.

### Değişmeyen
- Anahtar kapalıyken veya tek dil tanımlıyken tüm çıktılar 1.0.0 ile birebir aynı (geriye uyumluluk testi).

### Test güncellemesi (onaylı)
- `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `multilingual => false` eklendi (gevşetme değil).
## [1.0.1] - 2026-09-28

### Düzeltildi
- REST `GET /templates`: şablon sırası artık ilanların sırasına bağlı değil (genel, profilin şablonu, sonra ilanların
  şablonları kimliğe göre). Aynı saniyede kaydedilen ilanlarda sıra değişiyor ve `RestContractTest::test_full_catalog`
  ara sıra kırılıyordu (Görev 10'dan beri). Yeni birim testi `TemplateOrderTest`.

### Test güncellemesi (onaylı)
- `ReportTest`'in CSV okuma yardımcısı `str_getcsv`'ye `escape` parametresini açıkça veriyor (`CsvExport` ile aynı
  kural). PHP 8.4 bu parametrenin verilmemesini hata sayıyordu; eklenti kodu zaten uyumluydu.

## [1.0.0] - 2026-09-28

İlk kararlı sürüm (MVP).

### Eklendi
- **U4 AI uyum raporu ve AI Hazır rozeti** (`compliance_report` anahtarı, varsayılan kapalı).
  - Rapor verisi tek kaynaktan: `ComplianceReportData` (`src/Adapters/Report/`) kayıtlı taramaları ve A0 ölçümünü
    bir kez okur; ekran ve PDF aynı veriyi ve aynı işaretlemeyi kullanır. İçerik: son puan, kontrol bazında durum,
    ilk ↔ son tarama karşılaştırması (puan sürümü aynıysa), son 28 günün AI ölçüm özeti, açık yetenekler.
  - İlk tarama artık ayrıca saklanır (`aihs_first_scan`); eski sitelerde saklanan geçmişin en eskisi kullanılır.
  - Araçlar → **AI Uyum Raporu** ve **PDF indir** (nonce, `manage_options`). PDF: Dompdf 3.1.6, DejaVu Sans
    gömülü (tam Türkçe), uzak kaynak ve PHP çalıştırma kapalı.
  - Rozet kuralı `Badge` (çekirdek): ölçülmüş puan eşiğe (varsayılan 70, `aihs_badge_threshold` süzgeci) ulaşırsa.
    Erişilebilir SVG (`BadgeSvg`), `[aihs_rozet]` kısa kodu ve dinamik `ai-hazir-site/rozet` bloğu (block.json
    apiVersion 3, derleme adımı olmayan editör betiği). Eşik altında, tarama yokken veya özellik kapalıyken boş çıktı.
  - Doğrulama sayfası **/ai-hazir-dogrulama/**: site, puan, tarama tarihi, kontrol bazında durum ya da
    "şu anda kriter karşılanmıyor".
- **MVP sürüm işleri**
  - WordPress.org biçiminde `readme.txt`.
  - Kullanıcı belgeleri: `docs/kullanim/baslangic.md` (kurulum, anahtarlar, önerilen sıra),
    `docs/kullanim/rapor-ve-rozet.md`; bilinen sınırlar: `docs/bilinen-sinirlar.md`.
  - `bin/paketle` (`composer paketle`): işlenmiş dosyalardan, yalnızca çalışma bağımlılıklarıyla kurulabilir zip.
    Paket dışı dosyalar `.gitattributes` içinde `export-ignore`.
  - Özellik anahtarlarının son denetimi: 13 anahtar, yalnızca `measurement` açık (yeni birim testi).
  - 0.2.0 → 1.0.0 güncelleme entegrasyon testi (şema 200'den 1200'e, 0.2.0 verisi ve seçimleri korunuyor, yeni
    özellikler kapalı geliyor; ikinci çalıştırma bir şey değiştirmiyor).

### Bağımlılıklar
- `dompdf/dompdf` ^3.1.6 (LGPL-2.1; alt bağımlılığı php-svg-lib LGPL-3.0-or-later; GPL ile birlikte dağıtılabilir).
- Geliştirme: `smalot/pdfparser` (MIT, PDF içerik testleri).

### Kararlar
- PDF kütüphanesi olarak Dompdf: WordPress ekosisteminde en yaygın, HTML'den PDF, saf PHP, GPL uyumlu lisans.
- Onaylı test güncellemesi: `WizardFlowTest` tarama geçmişi gibi `aihs_first_scan`'i de karşılaştırma dışında tutuyor.
- Pilot site kopyası yerine güncelleme geliştirme ortamında 0.2.0 kurulumu üzerinden elle denendi; gerçek sitelerde
  güncellemeden önce yedek alınması belgelere yazıldı.

## [0.12.0] - 2026-09-27

### Eklendi
- **A7 Teklif kutusu ve güvenlik katmanı** (`inquiries` anahtarı, varsayılan kapalı; açarken uyarı ve onay).
  - Çekirdek `src/Core/Inquiry/`: `Inquiry` (kaynak ai/human/unknown; tür teklif isteği/teklif/yönlendirme
    talebi; durum new/approved/rejected/quarantine; spam skoru), `InquiryService` tek yazma noktası
    (hız sınırı → doğrulama → spam skoru → kayıt → denetim → site sahibine bildirim), `InquiryRepository`.
  - `src/Core/Security/`: `TokenBucketLimiter` (A5'in `RateLimiter` arayüzü; istemci başına dakikada 3,
    günde 20, ayarlanabilir), `SpamScorer` (sık gönderim, boş/anlamsız içerik, bağlantı yoğunluğu, ilanla
    alakasızlık; eşik 50 → karantina), `AuditLog` (her yazma denemesi; içerik, iletişim ve ham IP yok).
  - Kanallar: MCP yeteneği `aihs/submit-inquiry` ve REST `POST /wp-json/aihs/v1/inquiries`, aynı servis.
    Yanıt yalnızca referans numarası; durum açıklanmaz.
  - Hukuk gibi fiyatı yasak şablonlu sitelerde `aihs/submit-inquiry` hiç kaydedilmez; yerine
    `aihs/request-referral`: yalnızca yönlendirme talebi, ücret dışında her konu (uzmanlık, dava türleri,
    referans işler); ücret soran talep açıklamalı hatayla reddedilir.
  - Veritabanı: `Migration_0_12_0` (sürüm 1200) `{prefix}aihs_inquiries` ve `{prefix}aihs_audit_log`
    tablolarını ekler; `down()` ikisini siler.
  - Yönetim: **AI Katalog → Teklif Kutusu**: filtreler, onayla/reddet/karantina/yeni, sil, ayarlar
    (saklama süresi, hız sınırları, eşik), KVKK aydınlatma metni yer tutucusu, son denetim kayıtları.
    Yeni ve karantinaya düşmeyen talepler yönetici e-postasına bildirilir (iletişim bilgisi olmadan).
  - Günlük görev `aihs_purge_inquiries`: saklama süresi (varsayılan 180 gün) dolan talepleri ve denetim
    kayıtlarını siler.
  - Geliştirme ortamı: `tools/dev/aihs-dev-mail.php` yalnızca wp-env geliştirme ortamında e-postaları
    `wp-content/mails/` klasörüne yazar (eklenti paketine girmez).

### Kararlar
- **Kişisel veri istisnası (onaylı):** Talep sahibinin iletişim bilgileri (ad, firma, e-posta, telefon), firmanın
  dönebilmesi için saklanır. Şifreli (libsodium secretbox, anahtar sitenin `AUTH` tuzundan türetilir), süre
  sonunda ve talep üzerine silinir, bildirim e-postasına konmaz. IP adresi saklanmaz (tuzlu HMAC).
- Hiçbir yol talebi otomatik onaylamaz: onay yalnızca yönetim ekranından (mimari testiyle denetlenir);
  talep sahibine hiçbir otomatik yanıt gönderilmez.
- MCP sunucusunun tek yazma aracı talep aracıdır (`readOnlyHint: false`); sunucu açıklaması buna göre değişir.
- Onaylı test güncellemesi: `HitsTableTest` ve `UpgradeFrom020Test`'teki sabit veritabanı sürümü (200) en son
  geçişe göre güncellendi; geçiş listesi tam olarak `[200, 1200]` olarak denetleniyor.

### Uçtan uca deneme (geliştirme sitesi, `curl`)
```
tools/list → … aihs-submit-inquiry (readOnlyHint false)
tools/call aihs-submit-inquiry {quote_request, ilan 6, iletişim e-posta} → {"received":true,"reference":1,…}
POST /wp-json/aihs/v1/inquiries {referral, telefon, source human} → 201, reference 2
wp-content/mails/ → "[ai-hazir-site] Yeni AI Katalog talebi #1" ve "#2" (iletişim bilgisi yok, panel bağlantısı var)
Panel verisi → #1 quote_request ai/mcp new (alici@ornek.example), #2 referral human/rest new (+90 212 555 11 22)
Denetim → mcp submit accepted, mcp notify notified, rest submit accepted, rest notify notified
```
Claude ile elle deneme (28.09.2026, Claude Desktop, yerel site): kullanıcı Claude'dan NYY 3x2,5 kablo ilanı
için 500 metrelik teklif isteği bırakmasını istedi. Claude `aihs-submit-inquiry` aracını kullandı; talep #5 olarak
`quote_request`, kaynak `ai` / kanal `mcp`, durum `new`, spam skoru 0, ilan #6 ile kaydedildi. Bildirim e-postası
"[ai-hazir-site] Yeni AI Katalog talebi #5" yazıldı (iletişim bilgisi yok, panel bağlantısı var); talep panelde
iletişim bilgileriyle (Deneme Alıcı) göründü. Sonuç doğru.

`CLAUDE.md`'ye (onaylı) kişisel veri istisnası satırı eklendi.

### Bilinen sınırlar
- KVKK aydınlatma metni yalnızca yer tutucudur; hukuki metin site sahibinin sorumluluğundadır.
- Site tuzları (`AUTH_KEY` / `AUTH_SALT`) değişirse eski iletişim bilgileri çözülemez ("çözülemedi" görünür).
- Spam kuralları basittir; eşik ve sınırlar ayarlardan değiştirilebilir. Hız sayacı atomik değildir.
- Yerel sitede alan adı `localhost` olduğu için WordPress'in varsayılan gönderen adresi geçersizdir; gerçek
  gönderim yayındaki sitede denenmelidir (geliştirmede e-postalar dosyaya yazılır).

## [0.11.0] - 2026-09-27

### Eklendi
- **A6 Abilities API ve MCP, salt okuma** (`abilities` ve `mcp` anahtarları, ayrı ayrı, varsayılan kapalı).
  - Çekirdek sorgu servisi `src/Core/Catalog/Query/`: `ListingSearch` (tür, kategori, bölge, anahtar kelime,
    şablon alanları), `CatalogQuery` (geçerli ilanlar için tek okuma yolu), `Availability` (miktar ve teslim
    süresi sorusuna yes / no / unknown ve gerekçe). REST (A5) de bu servisi kullanıyor; A5'in dışarıdan
    görünen davranışı değişmedi.
  - WordPress Abilities API ile `aihs-catalog` kategorisinde dört yetenek: `aihs/get-profile`,
    `aihs/search-listings`, `aihs/get-listing`, `aihs/check-availability`. Girdi/çıktı şemaları; çıktılar
    REST sözleşmesiyle aynı.
  - MCP sunucusu `aihs-catalog`, adres `/wp-json/aihs/mcp`, resmi MCP Adapter paketiyle
    (`wordpress/mcp-adapter` **0.6.1**, sabit; Jetpack Autoloader ile). Herkese açık okuma, istemci başına
    dakikada 60 araç çağrısı (`aihs_abilities_rate_limit`).
  - Her MCP araç çağrısı A0 ölçümüne `kind = mcp` olarak işlenir; AI Ölçüm'de "MCP çağrıları" tablosu,
    CSV'de "MCP" etiketi. Tablo değişikliği yok (`kind` zaten `varchar(16)`).
  - Kullanım rehberi: [docs/kullanim/mcp-baglanti.md](docs/kullanim/mcp-baglanti.md).

### Kararlar
- Erişim modeli (onaylı): herkese açık okuma, anahtarla kapatılabilir, hız sınırlı.
- MCP Adapter 0.6.1'in `HttpTransport`'u her oturumu giriş yapmış bir WordPress kullanıcısına bağlar;
  girişsiz istemciye `401 User authentication required for session creation` döner. Bu yüzden sunucu,
  paketin `McpRestTransportInterface` arayüzüyle yazılmış **oturumsuz** `StatelessHttpTransport` kullanır.
  MCP 2025-06-18'e göre oturum isteğe bağlıdır ("A server … MAY assign a session ID"). POST ile JSON-RPC,
  bildirimlere 202, GET/DELETE'e 405; tarayıcıdan gelen yabancı `Origin` reddedilir (spesifikasyonun
  DNS rebinding uyarısı), desteklenmeyen `MCP-Protocol-Version` 400.
- Paketin varsayılan sunucusu (`/wp-json/mcp/mcp-adapter-default-server`, giriş gerekir, yalnızca açıkça
  herkese açık işaretli yetenekler) olduğu gibi bırakıldı; bizim yeteneklerimiz orada görünmez.
- Hız sınırı yürütme geri çağrısında: WordPress, yetki geri çağrısından dönen `WP_Error`'u genel bir
  "izin yok" hatasına çevirip gizliyor.

### Uçtan uca deneme (geliştirme sitesi, `curl`, MCP 2025-06-18)
```
POST /wp-json/aihs/mcp  initialize            → 200, serverInfo "AI Hazır Site – AI Katalog", oturum başlığı yok
POST notifications/initialized                → 202
POST tools/list                               → aihs-get-profile, aihs-search-listings, aihs-get-listing, aihs-check-availability
POST tools/call aihs-search-listings {"keyword":"3x2,5"}
     → 6 "NYY 3x2,5 enerji kablosu", stok 1500 m, teslim 7 gün
POST tools/call aihs-check-availability {"id":6,"quantity":500,"within_days":10}
     → {"answer":"yes","reasons":["Stokta 1500 m var; istenen 500.","Teslim süresi 7 gün; istenen 10 gün içinde."]}
POST tools/list (Origin: https://kotu.example) → 401;  GET → 405
wp aihs hits report → mcp,aihs-check-availability,1 / mcp,aihs-search-listings,1
```
WP-CLI ile STDIO (`wp mcp-adapter serve --server=aihs-catalog`) aynı cevabı verdi.
Claude ile elle deneme (27.09.2026, Claude Desktop, yerel site, rehberdeki STDIO bağlantısı): soru
*"Stokta 3x2,5 kablo var mı, 500 metre kaç günde gelir?"* — Claude 3 araç kullandı ve şöyle cevapladı:
"Evet, stokta var. 500 metre için ilandaki teslim süresi 7 gün. NYY 3x2,5 enerji kablosu (ilan #6):
stok 1.500 m, istenen 500 m karşılanıyor; teslim süresi 7 gün; metresi 42,50–48,75 TL; geçerlilik
31.12.2026. Bu cevap yalnızca ilandaki bilgilere dayanıyor; kesin fiyat ve teslim tarihi için firmadan
teklif almanız gerekir." (Kayıt: kullanıcının ilettiği sohbet metni.) Sonuç doğru.

### Bilinen sınırlar
- Claude'un custom connector özelliği sunucuya Anthropic'in sunucularından bağlanır; yerel veya güvenlik
  duvarı arkasındaki siteler için rehberdeki Claude Desktop (STDIO) yolu kullanılmalı.
- Oturumsuz taşıyıcı sunucudan istemciye bildirim (SSE) göndermez; salt okuma araçları için gerekmiyor.
- `StatelessHttpTransport`, MCP Adapter 0.6.1'in yönlendiricisine dayanır; paket güncellenirken yeniden sınanmalı.
- Müsaitlik cevabı yalnızca ilandaki bilgiye dayanır (stok veya teslim süresi yoksa `unknown`).

## [0.10.0] - 2026-09-27

### Eklendi
- **A5 REST API, yalnızca okuma** (`rest_api` anahtarı, varsayılan kapalı). Ad alanı `aihs/v1`:
  - `GET /profile` firma profili; `GET /listings?type=&category=&region=&page=&per_page=` geçerli ilanlar
    (en fazla 50/sayfa, varsayılan 20; `X-WP-Total`, `X-WP-TotalPages`); `GET /listings/{id}` tek ilan;
    `GET /templates` profilin ve geçerli ilanların şablon alan tanımları; `GET /schema/{profile|listings|listing|templates}`
    yanıtların JSON şeması.
  - Her ilanda `updated_at` ve `valid_until` (90 günlük varsayılan dahil); süresi dolan ilan hiçbir yanıtta yok.
    Fiyatı yasak şablonda `price` anahtarı hiç yok. Şablon alanları etiket ve birimle; tazelik süresi geçen
    değer `verified: false`.
  - Önbellek: `ETag`, `Last-Modified`, `Cache-Control: public, max-age=300`; eşleşen `If-None-Match` gövdesiz 304.
  - Hız sınırı: istemci başına dakikada 60 istek (`aihs_rest_rate_limit` filtresi); aşılınca 429 ve `Retry-After`.
    İstemci yalnızca tuzlu HMAC olarak, süreli önbellekte tutulur.
  - Keşif: ana sayfa `<head>` içinde `rel="alternate" type="application/json"` bağlantısı; llms.txt'te API ve
    şablon bağlantıları.
  - Platformdan bağımsız üretim `src/Adapters/Rest/` (`RestResponder`, `RestSchemas`, `ListingsQuery`);
    çekirdek `Contracts\RateLimiter` ve `RateLimit\FixedWindowLimiter` (A7 aynı arayüzü kullanabilir).

### Kararlar
- Tutarlar ve miktarlar yuvarlama olmasın diye ondalık metin (`"42.50"`).
- `category` ve `region` filtreleri tam eşleşme, büyük/küçük harf ve Türkçe i/ı duyarsız.
- Profil ve şablon yanıtlarında `valid_until` alanı `null` (yalnızca ilanlar için anlamlı).

### Bilinen sınırlar
- U1 `machine_interface` bu sürümde kısmi puan (0,5): REST keşfi WordPress'in kendi bağlantısından gelir,
  MCP A6'da gelecek. Başka bir eklenti WordPress'in REST bağlantısını kaldırmışsa geri eklenmez.
- Liste, ilan türü başına en fazla 200 ilanı bellekte süzer; daha büyük kataloglar için sorgu düzeyinde süzme gerekecek.
- Hız sınırı sayacı atomik değildir; yoğun eşzamanlı yükte birkaç fazla istek geçebilir.
- REST istekleri A0 raporunda ayrıca gösterilmiyor.

## [0.9.0] - 2026-09-27

### Eklendi
- **U3 AI uyum sihirbazı** (`compliance_wizard` anahtarı, varsayılan kapalı). Araçlar → **AI Uyum Sihirbazı**.
  - Çekirdek `src/Core/Compliance/Wizard/`: `StepPlanner` taramadaki her eksik kontrolü bir `FixStep`'e
    eşler. Uygulanabilir adımlar: uyum taramasını açmak, firma profilini doldurmak, ilk ilanı eklemek,
    Schema.org çıktısını açmak, llms.txt'yi açmak, AI bot erişim hazır ayarını seçmek. Tahmini kazanç,
    son taramada o kontrolün eksik puanıdır (ölçülemeyen kontrolde "en fazla" ağırlığın tamamı).
    Adımlar kazanca göre sıralanır; ön koşullar bağımlılarından önce gelir.
  - Eklenti dışında kalan eksikler (tema ve içerik, sunucu yönlendirmesi, fiziksel robots.txt/llms.txt,
    WordPress'in "arama motorlarını engelle" ayarı, güvenlik eklentisi/CDN, henüz yayınlanmayan REST/MCP
    ve A2A modülleri) için yalnızca "nasıl yapılır" açıklaması gösterilir.
  - Her adım yöneticinin onayıyla (nonce, `manage_options`) uygulanır. `Wizard` önce değiştireceği değerleri
    `aihs_wizard` günlüğüne yazar; "Geri al" tam olarak onları geri koyar (anahtar bazında özellik
    kayıtları, profil ve kayıt zamanı, bot ayarı, eklenen ilan). Bağımlı adım uygulanmışken ön koşulu
    geri alınamaz.
  - "Bitir ve yeniden tara" yeniden tarar ve başlangıç puanıyla yeni puanı kontrol bazında gösterir.
  - Anahtar açıksa ve sihirbaz hiç başlatılmamışsa yönetim panelinde kapatılabilir bir öneri çıkar.
- `Features::stored()` ve `Features::restore()`, `ProfileRepository::restore_profile()`,
  `CatalogService::revert_profile()` ve `profile_state()` (geri alma için; yazma yine yalnızca
  `CatalogService` üzerinden).

### Kararlar
- Sihirbaz yalnızca `aihs_` seçeneklerine ve AI Hazır Site ilanlarına yazar. Mimari test çekirdek
  sihirbazın yazdığı her anahtarın `aihs_` ile başladığını ve sihirbaz kodunda doğrudan seçenek, tema,
  gönderi, eklenti veya dosya yazıcısı çağrılmadığını denetler. Entegrasyon testi tam bir turdan sonra
  eklenti dışındaki bütün seçeneklerin, tema ayarlarının, diğer gönderilerin ve kök dizin dosyalarının
  aynı kaldığını denetler. Onaylı istisnalar: `rewrite_rules` (WordPress'in hesapladığı önbellek; /ai-katalog/
  kuralını katalog sayfası modülü ekler) ve geçici önbellek kayıtları.
- Anahtar kurala uygun olarak varsayılan kapalıdır; ilk kurulum önerisi anahtar açıldıktan sonra görünür.
- Bot erişim adımı yalnızca AI Bot Erişimi ayarı engeli gerçekten kaldıracaksa önerilir.

### Bilinen sınırlar
- Tahmini kazanç bir üst sınırdır: örneğin yapılandırılmış veri kontrolü, taranan diğer sayfalarda şema
  yoksa tam puana ulaşmaz.
- Başka bir eklenti veya tema robots.txt'de bir AI botunu adıyla `Disallow: /` ile engelliyorsa AI Bot
  Erişimi ayarının "izin ver" bloğu bu engeli kaldırmaz (blokta `Allow: /` satırı yok; U2 davranışı,
  kapsam dışı not). Sihirbaz bu durumu elle yapılacaklarda açıklar.
- Yerel wp-env'de WordPress kendine istek atamadığı için tarama "ölçülemedi" sonucu verir; sihirbazın akışı
  entegrasyon testlerinde sitenin gerçek çıktılarıyla sınanır.

## [0.8.0] - 2026-09-27

### Eklendi
- **A4 sektör şablonları** (`templates` anahtarı, varsayılan kapalı).
  - Şablonlar kodda değil, `data/templates/*.json` dosyalarında: kimlik, sürüm, ad, öğe türü (Product,
    Service, TouristTrip), fiyat kuralı ve alanlar (ad, etiket, tür, birim, UN/CEFACT birim kodu, zorunlu,
    izin verilen değerler, desen, llms.txt'te görünür mü, tazelik süresi, Schema.org eşlemesi).
  - Başlangıç şablonları (alanlar taslak, pilot firmalarla doğrulanacak): `product` (kablo),
    `export_product` (GTİP, menşe, Incoterms 2020, hedef pazarlar, sertifikalar, MOQ), `service` (hukuk;
    **fiyat yok**), `tour` (başlangıç, süre, kontenjan, kalan yer, dahil olanlar, iptal koşulu, buluşma
    noktası) ve alansız `general`.
  - Yeni sektör = yeni JSON dosyası; kod değişikliği gerekmez. Başka klasörler `aihs_template_dirs`
    filtresiyle eklenebilir. Okunamayan dosyalar atlanır ve yöneticiye nedeniyle gösterilir.
  - Firma Profili'nde sektör şablonu seçimi; yeni ilan formu şablonun alanlarını türüne uygun girdilerle
    gösterir. Şablonda olmayan ek özellikler serbest satırlar olarak kalır.
  - Çekirdek `TemplateRegistry` ve `TemplateValidator`; `ListingValidator` şablon doğrulamasını çağırır
    (zorunlu, tür, izin verilen değer, desen, fiyat yasağı). Mevcut kurallar değişmedi.
  - JSON-LD: öğe türü şablondan; alanlar eşlendikleri özelliğe (ör. `material`, `color`,
    `countryOfOrigin`, `eligibleRegion`, `eligibleQuantity`, `serviceType`, `departureTime`,
    `inventoryLevel`), diğerleri birim koduyla `PropertyValue` olarak. llms.txt ve AI katalog sayfası
    şablon alanlarını etiket ve birimleriyle gösterir.
  - `service` şablonunda fiyat girilemez ve kayıtta olsa bile hiçbir çıktıda yayınlanmaz.
  - `tour` şablonunda kalan yer, ilan son kaydedildikten 24 saat sonra AI çıktılarında
    "doğrulanmadı" diye işaretlenir (JSON-LD'de `inventoryLevel` yerine açıklamalı `PropertyValue`).
- Veri: ilanın şablonu `_aihs_template` post meta alanında, profilinki `aihs_profile` içinde. Tablo
  değişikliği yok. Bu alan olmayan eski ilanlar "genel" sayılır ve olduğu gibi çalışır.

### Değişti
- `Listing` ve `CompanyProfile` veri modellerine `template` alanı eklendi; alan listesini sabitleyen
  test onayla güncellendi (kişisel veri denetimi aynen).

### Kararlar
- `Service`, `TouristTrip` ve `Demand` türlerinde `additionalProperty` yok (schema.org); bu yüzden
  hizmet ve turların ek özellikleri teklifin (`Offer`) üzerine yazılır.
- İlanın şablonu, türü gibi sonradan değiştirilemez.
- Tazelik ilanın son kaydıyla ölçülür; formu yeniden kaydetmek müsaitliği onaylar.
- Şablonlar kapalıyken her şey 0.7.0'daki gibi çalışır; kayıtlı şablon seçimleri korunur.

### Bilinen sınırlar
- Aranan (Demand) bir hizmet veya turun ek özellikleri JSON-LD'de yer almaz; llms.txt ve katalog
  sayfasında görünür.
- Şablon alan etiketleri JSON dosyasındaki metindir; çevirisi A8 çoklu dil görevinde.
- Başlangıç şablonlarının alanları taslaktır; pilot sonrası değişebilir (sürüm numarası artırılarak).

## [0.7.0] - 2026-09-27

### Eklendi
- **A3 llms.txt ve AI katalog sayfası** (`llms_txt` anahtarı, varsayılan kapalı).
  - `/llms.txt`, [llmstxt.org](https://llmstxt.org/) önerisine göre: firma adıyla H1, özet alıntısı, ülke / dil /
    sertifika / son güncelleme, satılanlar / arananlar / tedarik edilebilenler bölümleri (her ilan AI katalog
    sayfasındaki çapasına bağlanır; kategori, miktar, fiyat veya bütçe, bölge, teslim süresi, geçerlilik,
    özellikler), İletişim ve "Optional" altında AI katalog bağlantısı.
  - `text/plain; charset=utf-8`, UTF-8, LF, BOM yok. Üretim platformdan bağımsız
    `src/Adapters/Llms/LlmsTxtBuilder`.
  - İstek yolu `parse_request` üzerinde doğrudan eşlenir; kalıcı bağlantı ayarı ne olursa olsun çalışır ve
    yönlendirme kuralı gerektirmez.
  - Metin yalnızca veri değişince (profil, ilanlar, gün, adresler) yeniden üretilir; aksi halde
    `aihs_llms_cache` seçeneğinden sunulur.
  - Sitenin kök dizininde fiziksel bir `llms.txt` varsa ona dokunulmaz, sanal adres devreye girmez;
    yöneticiye uyarı ve eklenecek metin gösterilir.
- **AI katalog sayfası** (`/ai-katalog/`): artık `schema_output` **veya** `llms_txt` açıkken sunulur.
  Firma özeti, her ilan için `ilan-{id}` çapası ve ayrıntı satırı eklendi. JSON-LD yalnızca `schema_output`
  açıkken eklenir.
- **A0 raporu**: "AI dosyaları" bölümü (AI Ölçüm sayfası, CSV'de "AI dosyası", WP-CLI'da `ai_files`).
  Botların `/llms.txt` ve `/ai-katalog/` okumaları ilk 10 sayfada olmasa da gösterilir. Bu istekler zaten
  sayılıyordu; yeni bir kayıt eklenmedi.
- Çekirdek `ListingValidity`: Schema.org, llms.txt ve katalog sayfası aynı geçerlilik kuralını kullanır
  (kendi tarihi, yoksa son güncelleme + 90 gün). JSON-LD çıktısı değişmedi.

### Bilinen sınırlar
- Sunucu, var olmayan `/llms.txt` isteğini WordPress'e iletmiyorsa (ör. `.htaccess` ya da nginx
  `try_files` yoksa) sanal adres çalışamaz.
- Site bir alt dizindeyse dosya `/{alt-dizin}/llms.txt` adresinde sunulur; kök alan adındaki `/llms.txt`
  için sunucu ayarı gerekir.
- Önbellek eklentileri `/llms.txt`'yi saklıyorsa değişiklik önbellek temizlenene kadar görünmeyebilir.
- Metinler Türkçedir ve çevrilebilir; çok dilli llms.txt A8'de.

## [0.6.0] - 2026-09-27

### Eklendi
- **A2 Schema.org yapılandırılmış veri** (`schema_output` anahtarı, varsayılan kapalı).
  - Ana sayfa: firma profilinden `Organization` (ad, adres, iletişim, uzmanlık, dil) ve `WebPage`
    JSON-LD'si, `wp_head` içinde. `dateModified` profil ve ilanlardaki en son değişikliktir.
  - Sanal **/ai-katalog/** sayfası: JavaScript'siz, sade HTML liste (satılan / aranan / tedarik edilebilen)
    ve `DataFeed` JSON-LD'si. Satılan → `Offer`, aranan → `Demand` (`Organization.seeks` karşılığı),
    tedarik edilebilen → `Offer` + `availability: MadeToOrder` ve `deliveryLeadTime`.
  - Tek eşleme tablosu `SchemaMap`; üretim platformdan bağımsız `src/Adapters/Schema` altında
    (`SchemaBuilder`, `SchemaValidator`, `SchemaCache`).
  - Her çıktı yayından önce doğrulanır. Hatalı çıktı yayınlanmaz; son geçerli çıktı sunulur ve yöneticiye
    hata (sayfa, zaman, hatalar) bildirilir.
  - Yoast SEO, Rank Math, All in One SEO, SEOPress veya The SEO Framework etkinse kendi `Organization`
    düğümümüz eklenmez, ilanlar firmaya adla bağlanır ve yöneticiye uyarı gösterilir. Diğer eklentiler
    `aihs_schema_seo_conflict` filtresiyle bildirilebilir.
  - U1 taramasında bu çıktılarla "yapılandırılmış veri" ve "tazelik" kontrolleri tam puan alır.
- `ProfileRepository::updated_at()`; profil kaydedilince `aihs_profile_updated` seçeneği güncellenir.

### Kararlar
- `dateModified` yalnızca `CreativeWork` ve `DataFeedItem` üzerinde geçerli olduğu için her ilan bir
  `DataFeedItem` içine konur; tarih ilanın son güncellemesidir.
- Geçerlilik tarihi girilmemiş ilana 90 günlük varsayılan `validThrough` verilir; süresi dolan ilan
  hiçbir çıktıda yer almaz.
- Fiyatı olmayan satılan ilan da `Offer` olarak yayınlanır; doğrulayıcı uyarı verir (Google zengin
  sonuçları fiyat ister).
- Fiyat aralığında `price` en düşük değerdir, aralık `priceSpecification` (`minPrice`/`maxPrice`) ile verilir.

### Bilinen sınırlar
- Elle doğrulama (validator.schema.org) henüz yapılmadı; otomatik doğrulama `SchemaValidator` ve anlık
  görüntü testleriyle yapılır.
- Ana sayfa ve /ai-katalog/ dışındaki sayfalara şema eklenmez; U1 bu sayfalarda yapılandırılmış veri
  bulamazsa puan düşebilir.
- SEO eklentisi tespiti sabitlere dayanır; tanınmayan eklentiler filtreyle bildirilmelidir.
- /ai-katalog/ yeniden yazma kuralı anahtar açıldıktan sonraki ilk istekte eklenir.

## [0.5.0] - 2026-09-27

### Eklendi
- **U2 AI bot erişim ayarları** (`bot_access` anahtarı, varsayılan kapalı).
  - Her AI botu için "site kuralları / izin ver / engelle"; hazır ayarlar: "Hepsine izin ver",
    "Sadece arama ve kullanıcı agentları", "Eğitim botlarını engelle" (bot listesi ve kategoriler
    `data/ai-bots.json`).
  - WordPress'in sanal robots.txt'sine `robots_txt` filtresiyle, işaretli bir blok olarak eklenir
    (`# BEGIN AI Hazir Site ... # END AI Hazir Site`). Mevcut kurallar ve diğer eklentilerin satırları
    değişmez; anahtar kapatılınca robots.txt eski haline döner, ayar saklanır.
  - "İzin ver" grubu, RFC 9309 gereği `*` grubunu yok saydığı için `/wp-admin/` kısıtlamasını tekrarlar.
  - Fiziksel robots.txt dosyası varsa dosyaya dokunulmaz; uyarı ve eklenecek metin gösterilir.
  - Araçlar → AI Bot Erişimi: bot, işletmeci, kategori, ayar, robots.txt'nin tamamından hesaplanan
    gerçek durum, önizleme; "arama motorlarını engelle" ve önbellek uyarıları.

### Değişti
- U1 `bot_access` kontrolü, AI Bot Erişimi ayarıyla bilerek engellenen botları "bilerek engellendi"
  olarak gösterir ve puandan düşmez. Puanlama sürümü (`score_version`) 1 → 2; eski taramalar sürüm 1
  olarak kalır.

### Bilinen sınırlar
- robots.txt bir ricadır; kurallara uymayan botları durdurmaz (sunucu/CDN engeli kapsam dışı).
- Önbellek eklentileri robots.txt'yi saklıyorsa değişiklik önbellek temizlenene kadar görünmeyebilir.
- Ekrandaki "şu anki durum", robots.txt önizlemesi için `do_robots()` çıktısını yeniden kurar;
  `do_robotstxt` eylemine doğrudan yazan eklentilerin satırları önizlemede görünmez.

## [0.4.0] - 2026-09-27

### Eklendi
- **A1 veri modeli ve yönetim formları** (`catalog` anahtarı, varsayılan kapalı).
  - Çekirdek: `Listing` (satılan / aranan / tedarik edilebilen; 14 alan, fiyat ve miktar ondalık metin),
    `CompanyProfile` (kişisel veri alanı yok), `ListingValidator`, `ProfileValidator`,
    `ListingRepository` ve `ProfileRepository` arayüzleri.
  - `CatalogService` tek yazma noktası: önce doğrular (zorunlu başlık, fiyat aralığı, ISO 4217 para birimi,
    geçmiş geçerlilik tarihi yasak), sonra yazar. Mimari testi başka yerden yazılmasını engeller.
  - WordPress: `aihs_listing` içerik türü (herkese açık adres, arşiv, arama, REST ve çekirdek düzenleme
    ekranı yok), alanlar `_aihs_` önekli korumalı meta; profil `aihs_profile` seçeneğinde.
  - AI Katalog menüsü: Satılanlar, Arananlar, Tedarik Edilebilenler (tür, geçerlilik, son güncelleme
    sütunları; süresi dolanlar işaretlenir, silinmez) ve Firma Profili. Nonce, `manage_options`,
    girdi temizleme ve çıktı kaçışlama. Kişisel görünen e-posta için uyarı.
- Kaldırmada, silme seçeneği açıksa ilanlar ve profil de `CatalogService` üzerinden silinir.

### Bilinen sınırlar
- Liste sayfaları sayfalamasız en fazla 200 kayıt gösterir.
- Özellikler serbest anahtar–değer; şablonla doğrulama A4'te.
- Silme kalıcıdır (çöp kutusu yok); onay penceresi ve nonce ile korunur.
- Özel yetki yerine `manage_options` kullanılır.

## [0.3.0] - 2026-09-27

### Eklendi
- **U1 AI uyum taraması ve puan** (`compliance_scan` anahtarı, varsayılan kapalı).
  - Yedi kontrol, PRD ağırlıklarıyla (toplam 100): yapılandırılmış veri 20, içerik okunabilirliği 20,
    makine arayüzü 20, AI bot erişimi 15, llms.txt 10, tazelik 10, ileri standartlar 5.
  - Dayanılan standartlar: Google yapılandırılmış veri kuralları (Product, Offer, LocalBusiness,
    Organization), RFC 9309 (robots.txt), WordPress REST keşfi, WordPress MCP Adapter varsayılan uç noktası,
    llmstxt.org (zorunlu H1), A2A 1.0.0 Agent Card (`/.well-known/agent-card.json`, §4.4.1).
  - Puan = 100 × Σ(ağırlık × oran) / Σ(ölçülen ağırlık); erişilemeyen kontroller "ölçülemedi" olur ve
    puandan düşülmez. `score_version` (1) her rapora yazılır.
  - En fazla 10 örnek sayfa (ana sayfa, menü bağlantıları, site haritası), istek başına 5 sn, toplam 60 sn.
  - Araçlar → AI Uyum: "Şimdi tara", puan, en çok puan kazandıracak eksik en üstte, önceki taramayla
    karşılaştırma, geçmiş. WP-CLI: `wp aihs scan [--format=table|json]`.
  - Son 20 tarama `aihs_scans` seçeneğinde (otomatik yüklenmez; silme seçeneği açıksa kaldırmada silinir).
- Çekirdek: `PageFetcher` arayüzü (durum kodu ve başlıklarla HTTP), `Site`, `Html`, `Robots`, `Scanner`.

### Değişti
- A0 ölçümü, taramanın kendi isteklerini saymaz: tarayıcı site sırrıyla imzalı `X-AIHS-Scan` başlığı gönderir.

### Bilinen sınırlar
- Tarama sitenin kendine istek atmasına (loopback) dayanır; bunu engelleyen sunucularda kontroller
  "ölçülemedi" görünür ve sayfada uyarı çıkar. Yerel wp-env'de de loopback çalışmaz.
- JavaScript çalıştırılmaz; yalnızca JavaScript ile oluşan içerik ve WebMCP görülmez.
- MCP Server Card (`/.well-known/mcp/server-cards.json`) taslak olduğu için yalnızca bilgi olarak gösterilir.
- Tarama yönetim sayfasında eşzamanlı çalışır (en fazla ~60 sn).

## [0.2.1] - 2026-09-27

Kullanıcı açısından değişiklik yok; iç yapı çoklu platforma hazırlandı
([ADR-001](docs/mimari/ADR-001-coklu-platform.md)).

### Değişti
- Çekirdek (`src/Core`) platformdan bağımsız: WordPress'e yalnızca `src/Core/Contracts` arayüzleri
  (`Settings`, `Cache`, `HttpClient`, `Clock`, `Secret`, `HitRepository`) üzerinden erişir.
- WordPress'e özgü kod `src/WordPress` altına taşındı (`Plugin`, `Lifecycle`, `Uninstaller`,
  `Requirements`, geçişler, yönetim sayfası, WP-CLI). `HitStore` → `WpdbHitRepository`.
- Ölçüm sayacı artık `$_SERVER` okumaz; WordPress adaptörü bir `Request` nesnesi üretir ve yönetici
  paneli / cron / AJAX filtresini uygular.

### Eklendi
- `CoreIsPlatformNeutralTest`: çekirdeğe platform kodu girerse test kırılır.
- WordPress'siz uçtan uca ölçüm testi (bellek içi adaptörlerle) ve 0.2.0'dan güncelleme testi.

### Geriye uyumluluk
Seçenek adları, `{prefix}aihs_hits` tablosu, cron kancaları, yönetim sayfası adresi, CSV biçimi ve
WP-CLI komutu aynen korunur; veritabanı geçişi yoktur.

## [0.2.0] - 2026-09-27

### Eklendi
- **A0 ölçümü** (`measurement` anahtarı): AI botlarının ziyaretleri ve AI platformlarından gelen insan ziyaretleri sayılır.
  - Bot listesi `data/ai-bots.json` (12 bot; UA ve doğrulama kaynağı sağlayıcıların resmi belgelerinden, 2026-09-27),
    yönlendirme listesi `data/ai-referrers.json` (5 platform). Referer yoksa `utm_source` da eşleşir.
  - Sayaç ön yüz, REST, `robots.txt` ve WordPress'e düşen `llms.txt` isteklerinde çalışır; yönetici paneli,
    AJAX ve cron istekleri sayılmaz. İstek başına en fazla bir veritabanı yazması.
  - Doğrulama: yayınlanmış IP listeleri günlük cron ile indirilir (`aihs_ip_ranges`); rDNS sonucu IP'nin tuzlu
    hash'i anahtarıyla 24 saat önbellekte. Doğrulanamayan ziyaret `verified = 0` ile yine sayılır.
  - Araçlar → AI Ölçüm: son 7 / 28 gün, bota göre ziyaret, en çok okunan 10 sayfa, yönlendirmeler, CSV dışa
    aktarma ve ölçümü aç/kapat.
  - WP-CLI: `wp aihs hits report --days=28 --format=table|csv`.
  - 400 günden eski kayıtlar haftalık cron ile silinir.
- Geçiş `Migration_0_2_0`: `{prefix}aihs_hits` tablosu.
- Eklenti güncellendiğinde bekleyen geçişler otomatik çalışır (etkinleştirme kancası güncellemede çalışmaz).
- `Features::set()` ve `Module::deactivate()` (devre dışı bırakınca cron görevleri silinir).

### Neden `measurement` varsayılan AÇIK?
Tüm özellikler varsayılan kapalı gelir; bu tek istisnadır. A0'ın amacı eklenti kurulduğu andan itibaren
"önceki" durumu ölçmektir; kapalı gelseydi temel (baseline) veri kaybolurdu. Ölçüm yalnızca toplu sayaç
tutar: ham IP, tam user-agent, sorgu dizesi veya başka kişisel veri saklanmaz. Araçlar → AI Ölçüm
sayfasından tek tıkla kapatılabilir; kapatılınca sayaç tamamen durur.

### Değişti
- `aihs_db_version` artık her istekte okunduğu için otomatik yüklenir.
- Varsayılan-kapalı testi, onaylı istisnaları (`Features::DEFAULT_ON`) birebir denetleyecek şekilde sıkılaştırıldı.

### Bilinen sınırlar
- Sayfa önbelleğinden sunulan istekler ve sunucuda gerçek dosya olarak duran `llms.txt` sayılamaz; sonuçlar alt sınırdır.
- Doğrulama `REMOTE_ADDR` kullanır; CDN veya ters vekil arkasındaki sitelerde botlar `verified = 0` görünür.
- Bytespider ve meta-externalagent için resmi doğrulama yöntemi yok (`verify: none`); Bytespider'ın UA
  bilgisi yalnızca üçüncü taraf kaynaklardan doğrulanabildi.
- Amazonbot IP listesi bir HTML sayfasının içinden ayıklanır; sayfa yapısı değişirse doğrulama 0'a düşer.
- `aihs_hits` benzersiz anahtarı InnoDB DYNAMIC satır biçimi gerektirir (MySQL 5.7+ / MariaDB 10.2+).
- rDNS önbelleği ıskalandığında o istekte hit yazmasına ek olarak bir önbellek yazması olur (başlangıç listesinde rDNS kullanan bot yok).

## [0.1.0] - 2026-09-27

### Eklendi
- Eklenti iskeleti: `ai-hazir-site.php`, PSR-4 otomatik yükleme (`AIHazirSite\` → `src/`).
- PHP 8.1 / WordPress 6.9 alt sınırı; yetersiz ortamda yönetici uyarısı gösterilir ve eklenti çalışmaz.
- `Plugin` giriş noktası ve `Module` arayüzü (henüz modül yok).
- `Features` özellik anahtarları (`aihs_features`); bilinmeyen anahtar her zaman kapalı.
- Sürümlü geçiş altyapısı: `MigrationInterface` ve `Migrator` (`aihs_db_version`).
- Etkinleştirmede bekleyen geçişler çalışır; devre dışı bırakma veri silmez.
- `uninstall.php`: veri yalnızca `aihs_delete_data_on_uninstall` açıksa silinir.
- Geliştirme araçları: wp-env, PHPUnit (birim + entegrasyon), PHPStan seviye 6, WPCS, GitHub Actions matrisi.
