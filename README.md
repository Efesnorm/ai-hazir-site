# AI Hazır Site

Sitenin "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini AI agentların okuyup
kullanabileceği biçimde yayınlayan WordPress eklentisi.

> Sürüm 1.6.0: AI bot ve yönlendirme ölçümü (A0), AI uyum taraması (U1), veri modeli ve yönetim formları (A1), AI bot erişim ayarları (U2), Schema.org yapılandırılmış veri (A2), llms.txt ve AI katalog sayfası (A3), sektör şablonları (A4), AI uyum sihirbazı (U3), REST API (A5), Abilities API ve MCP (A6), teklif kutusu ve güvenlik katmanı (A7), AI uyum raporu ve AI Hazır rozeti (U4), çoklu dil (A8), portal modu (A9), merkezi güncelleme ve rapor paneli (A10), eşleştirme motoru (A11), A2A kartviziti ve agent (A12). Ayrıntılar: [CHANGELOG.md](CHANGELOG.md).

- En düşük sürümler: PHP 8.1, WordPress 6.9
- Lisans: GPL-2.0-or-later
- İş planı: [docs/PRD.md](docs/PRD.md) · Görevler: [docs/gorevler/](docs/gorevler/)

## Gereksinimler

- PHP 8.1+ ve [Composer](https://getcomposer.org/)
- Node.js 20+ (wp-env için)
- Docker Desktop (çalışır durumda)

## Kurulum

```bash
composer install
npm install
npx wp-env start
```

Site: http://localhost:8888 (yönetici: `admin` / `password`). Eklenti otomatik etkinleşir.

## Komutlar

| Komut | Açıklama |
| --- | --- |
| `npx wp-env start` / `npx wp-env stop` | Geliştirme ortamını başlatır / durdurur |
| `composer test` | Birim ve entegrasyon testleri (wp-env açık olmalı) |
| `composer test:unit` | Sadece birim testleri (WordPress gerekmez) |
| `composer test:integration` | Entegrasyon testleri, wp-env `cli` kapsayıcısında |
| `composer phpstan` | Statik analiz (seviye 6) |
| `composer phpcs` / `composer phpcbf` | Kod stili denetimi / otomatik düzeltme |
| `composer check` | Hepsi |
| `npx wp-env run cli wp aihs hits report --days=28 --format=table` | Ölçüm raporu (`csv` de olur) |
| `npx wp-env run cli wp aihs scan --format=table` | AI uyum taraması (`compliance_scan` açık olmalı; `json` de olur) |

Entegrasyon testleri, geliştirme sitesiyle aynı veritabanında ama ayrı bir tablo önekinde
(`aihstests_`) çalışır; geliştirme sitesinin verisine dokunmaz.

## AI ölçümü (A0)

Araçlar → **AI Ölçüm** sayfası son 7 / 28 günde hangi AI botlarının geldiğini, en çok okudukları
10 sayfayı ve AI platformlarından gelen insan ziyaretlerini gösterir; CSV indirilebilir ve ölçüm
buradan kapatılabilir.

- Bot listesi: [data/ai-bots.json](data/ai-bots.json). Her kayıtta resmi belge (`docs_url`) ve doğrulama
  kaynağı vardır. `ua_pattern` büyük/küçük harf duyarsız bir düzenli ifade parçasıdır.
- Yönlendirme listesi: [data/ai-referrers.json](data/ai-referrers.json).
- Saklanan: gün, tür (bot/yönlendirme), kaynak kimliği, yol (sorgu dizesi olmadan, en fazla 191 karakter),
  doğrulandı mı, sayı. IP, user-agent ve sorgu dizesi saklanmaz. 400 günden eski satırlar haftalık silinir.

## AI uyum taraması (U1)

`compliance_scan` anahtarı açıkken Araçlar → **AI Uyum** sayfası siteyi yedi kritere göre 100 üzerinden
puanlar ve her eksik için puana etkisini ve nasıl düzeltileceğini gösterir. Tarama sitenin kendi
adreslerini çeker (en fazla 10 sayfa, toplam 60 sn); kendi istekleri A0 ölçümünde sayılmaz.

Yerel wp-env'de WordPress kendine istek atamadığı için tarama "ölçülemedi" sonucu verir; kontrol
mantığı birim ve entegrasyon testleriyle sınanır.

## AI uyum sihirbazı (U3)

`compliance_wizard` anahtarı açıkken Araçlar → **AI Uyum Sihirbazı** taramadaki eksikleri adım adım
tamamlatır: taramayı açar, firma profilini ve ilk ilanı kısa formlarla ekler, Schema.org ve llms.txt
çıktılarını ve AI bot erişim ayarını açar. Her adım onayla uygulanır ve tek tıkla geri alınır; sihirbaz
yalnızca AI Hazır Site'nin kendi ayarlarını ve verisini değiştirir. Eklenti dışındaki eksikler için
açıklama gösterilir. "Bitir ve yeniden tara" önce/sonra puanını gösterir.

## REST API (A5)

`rest_api` anahtarı açıkken katalog verisi `/wp-json/aihs/v1/` altında herkese açık, salt okunur JSON olarak sunulur:
`/profile`, `/listings` (filtreler: `type`, `category`, `region`; sayfalama: `page`, `per_page` en fazla 50),
`/listings/{id}`, `/templates` ve yanıt şemaları `/schema/{profile|listings|listing|templates}`. Yanıtlar
`ETag` / `Last-Modified` taşır; istemci başına dakikada 60 istek sınırı vardır (`aihs_rest_rate_limit` filtresi).

```bash
curl "http://localhost:8888/wp-json/aihs/v1/listings?type=offer&per_page=10"
```

## Abilities API ve MCP (A6)

`abilities` anahtarı açıkken katalog dört salt okuma yeteneği olarak (WordPress Abilities API) kaydedilir;
`mcp` de açıkken bunlar `/wp-json/aihs/mcp` adresinde herkese açık bir MCP sunucusu olarak yayınlanır
(resmi MCP Adapter 0.6.1). Claude'a bağlanma adımları: [docs/kullanim/mcp-baglanti.md](docs/kullanim/mcp-baglanti.md).

## Teklif kutusu (A7)

**AI Katalog → Teklif Kutusu** ekranındaki uyarıyı onaylayıp `inquiries` anahtarını açınca AI agentlar
(MCP `aihs/submit-inquiry`) ve insanlar (REST `POST /wp-json/aihs/v1/inquiries`) firmaya talep bırakabilir.
Hiçbir talep otomatik onaylanmaz, talep sahibine otomatik yanıt gitmez. İletişim bilgileri şifreli saklanır ve
saklama süresi (varsayılan 180 gün) dolunca silinir. Hukuk gibi fiyatı yasak şablonlu sitelerde yalnızca
yönlendirme talebi (`aihs/request-referral`) alınır. Yerelde e-postalar `wp-content/mails/` klasörüne yazılır.

## AI uyum raporu ve rozet (U4)

`compliance_report` anahtarı açıkken Araçlar → **AI Uyum Raporu** son tarama puanını, kontrol bazında durumu,
ilk ↔ son tarama karşılaştırmasını, son 28 günün AI ölçüm özetini ve açık yetenekleri gösterir; **PDF indir**
aynı içeriği A4 PDF olarak verir (Dompdf, DejaVu Sans). Puan eşiği (varsayılan 70, `aihs_badge_threshold`)
aşılınca `[aihs_rozet]` kısa kodu ve "AI Hazır rozeti" bloğu rozeti gösterir; rozet **/ai-hazir-dogrulama/**
sayfasına bağlanır. Kullanım: [docs/kullanim/rapor-ve-rozet.md](docs/kullanim/rapor-ve-rozet.md).

## Çoklu dil (A8)

`multilingual` anahtarı açıkken **AI Katalog → Çeviriler** ekranından diller (Polylang/WPML varsa onlardan) ve
ilan/profil çevirileri girilir. REST (`?lang=` / `Accept-Language`), MCP (`lang`), `/llms.txt?lang=` ve
`/ai-katalog/?lang=` istenen dilde cevap verir; çevirisi olmayan alan varsayılan dilde döner ve işaretlenir.
Otomatik çeviri yoktur. Ayrıntı: [docs/kullanim/coklu-dil.md](docs/kullanim/coklu-dil.md).

## Portal modu (A9)

`portal_mode` anahtarı açıkken **AI Katalog → İşletmeler** ekranından işletmeler, işletme yetkilileri ve ilanların
işletmeleri yönetilir; yetkililer **İşletmem** ekranında yalnızca kendi ilanlarını görür. İşletme sayfaları
`/ai-katalog/isletme/{kısaltma}/`; REST'te `business` alanı, `?business=` süzgeci ve `/businesses`; MCP'de
`aihs/list-businesses`. Ayrıntı: [docs/kullanim/portal.md](docs/kullanim/portal.md).

## Merkezi güncelleme ve rapor paneli (A10)

`remote_updates` açıkken güncellemeler kendi sunucumuzdan (bildirim: `wp-update-server` / PUC alanları + `releases`)
WordPress'in standart ekranına gelir; pilot kanal hemen, genel kanal 48 saat sonra. **Ayarlar → AI Hazır Güncelleme**
ekranında önceki sürüme tek işlemle dönülür (önce şema `down()`, sonra paket). `telemetry` açık ve site sahibi açık
onay verdiyse haftalık özet (yalnızca toplamlar) rapor paneline gider. Adres verilmedikçe hiçbir istek atılmaz.
Ayrıntı: [docs/kullanim/guncelleme.md](docs/kullanim/guncelleme.md).

## Eşleştirme (A11)

`matching` açıkken **AI Katalog → Eşleşmeler**: aranan ilan için satılan/tedarik ilanları (bu site + elle girilen
ortak sitelerin REST çıktısı) kesin şartlardan (kategori, şablon, standart, bölge, teslim süresi) geçirilip
S = w1·özellik + w2·miktar + w3·teslim + w4·fiyat ile puanlanır; her puanın açıklaması gösterilir. Hiçbir şey
otomatik gönderilmez. Ayrıntı: [docs/kullanim/eslestirme.md](docs/kullanim/eslestirme.md).

## A2A kartviziti ve agent (A12)

`a2a` açıkken `/.well-known/agent-card.json` (A2A 1.0.0; yalnızca uç nokta çalışırken ve kart resmi şemaya uyarken)
ve `POST /wp-json/aihs/a2a` (JSON-RPC `SendMessage`; beceriler `musaitlik-sor`, `teklif-iste`). Eşleşmeler ekranından
ortak sitelere teklif isteği yalnızca mesajın önizlemesi ve kullanıcı onayıyla gider. Tüm mesajlar denetim kaydında.
Ayrıntı ve demo adımları: [docs/kullanim/a2a.md](docs/kullanim/a2a.md).

## Kullanıcı belgeleri ve paket

- Site sahibi için başlangıç: [docs/kullanim/baslangic.md](docs/kullanim/baslangic.md)
- Bilinen sınırlar: [docs/bilinen-sinirlar.md](docs/bilinen-sinirlar.md)
- WordPress.org biçiminde tanıtım: [readme.txt](readme.txt)
- Kurulabilir zip: `composer paketle` (ya da `php bin/paketle [git-ref]`) → `dist/ai-hazir-site-<sürüm>.zip`.
  Yalnızca işlenmiş dosyalar ve çalışma bağımlılıkları girer; `.gitattributes` içindeki `export-ignore`
  satırları (testler, belgeler, geliştirme araçları) dışarıda kalır.

## AI Katalog (A1)

`catalog` anahtarı açıkken **AI Katalog** menüsünden satılan, aranan ve tedarik edilebilen ilanlar ile
firma profili girilir. Sonraki AI çıktıları (Schema.org, llms.txt, REST, MCP) bu veriden üretilecek.
Tüm yazmalar `CatalogService` üzerinden geçer ve önce doğrulanır. İlanlar `aihs_listing` içerik türünde
tutulur; sitenin ön yüzünde görünmez.

## AI bot erişimi (U2)

`bot_access` anahtarı açıkken Araçlar → **AI Bot Erişimi** sayfasından her AI botuna izin verilir,
engellenir ya da sitenin genel kurallarına bırakılır. Seçimler robots.txt'ye işaretli bir blok olarak
eklenir; diğer satırlara dokunulmaz. Fiziksel bir robots.txt dosyası varsa dosya değiştirilmez,
eklenecek metin gösterilir.

## Schema.org çıktısı (A2)

`schema_output` anahtarı açıkken ana sayfaya firma profilinden `Organization` JSON-LD'si eklenir ve
**/ai-katalog/** adresinde ilanların sade HTML listesi ile `DataFeed` JSON-LD'si yayınlanır. Her çıktı
yayından önce doğrulanır; hatalıysa son geçerli çıktı sunulur ve yönetim panelinde uyarı çıkar.
Bir SEO eklentisi (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework) zaten `Organization`
üretiyorsa bizimki eklenmez. Başka bir eklenti için:

```php
add_filter( 'aihs_schema_seo_conflict', fn() => 'Eklenti adı' );
```

## llms.txt ve AI katalog sayfası (A3)

`llms_txt` anahtarı açıkken **/llms.txt** adresinde firmayı ve geçerli ilanları özetleyen sade bir metin
([llmstxt.org](https://llmstxt.org/) biçimi) ve **/ai-katalog/** adresinde ilanların sade HTML listesi yayınlanır.
Metin yalnızca veri değişince yeniden üretilir. Sitenin kök dizininde fiziksel bir `llms.txt` varsa ona
dokunulmaz ve yönetim panelinde uyarı çıkar. Botların bu dosyaları okuması AI Ölçüm sayfasında
"AI dosyaları" tablosunda görünür.

## Sektör şablonları (A4)

`templates` anahtarı açıkken Firma Profili'nden bir sektör şablonu seçilir; yeni ilan formları o şablonun
alanlarını gösterir ve Schema.org, llms.txt ve AI katalog çıktıları şablonun eşlemesini kullanır.
Şablonlar [data/templates/](data/templates/) altında JSON dosyalarıdır: yeni bir sektör için yeni bir dosya
eklemek yeterlidir. Başka bir klasör eklemek için:

```php
add_filter( 'aihs_template_dirs', fn( $dirs ) => array_merge( $dirs, array( WP_CONTENT_DIR . '/aihs-templates' ) ) );
```

Alan tanımı: `name`, `label`, `type` (text, integer, decimal, date, enum, list), isteğe bağlı `unit`,
`unit_code` (UN/CEFACT), `required`, `allowed`, `pattern`, `case` (upper/lower), `help`, `llms`,
`fresh_hours` ve `schema` (`node`: item/deal, `property`, `as`: text, number, date, country, quantity,
min_quantity, property). Şablonun `price` değeri `false` ise fiyat girilemez ve yayınlanmaz.

## Klasör yapısı

```
ai-hazir-site.php          Eklenti başlığı, sürüm kontrolü, açılış
uninstall.php              Silmede veri temizliği (yalnızca seçenek açıksa)
src/Core/                  Platformdan bağımsız çekirdek (WordPress fonksiyonu kullanmaz)
  Contracts/               Arayüzler: Settings, Cache, HttpClient, PageFetcher, Clock, Secret, HitRepository,
                           ListingRepository, ProfileRepository, RateLimiter
  RateLimit/               FixedWindowLimiter
  Features.php             Özellik anahtarları
  Migrations/              MigrationInterface, Migrator
  Measurement/             A0 iş kuralları: Classifier, Verifier, IpRanges, Tracker, Report, CsvExport
  Compliance/              U1: Site, Html, Robots, Scanner, ScoreReport, ScanStore, Badge (U4), Checks/ (7 kontrol)
    Wizard/                U3: FixStep, SiteState, StepPlanner, Wizard, WizardJournal
  Catalog/                 A1: Listing, CompanyProfile, doğrulayıcılar, ListingValidity, CatalogService (tek yazma noktası)
    Query/                 A6: ListingSearch, CatalogQuery (tek okuma yolu), Availability
  Inquiry/                 A7: Inquiry, InquiryService (tek yazma noktası), InquiryValidator, InquirySettings
  Security/                A7: TokenBucketLimiter, SpamScorer, AuditLog
  Access/                  U2: BotPolicy, Presets, RobotsRules, PolicyStore
  Templates/               A4: Template, TemplateField, TemplateRegistry, TemplateValidator, Freshness
  I18n/                    A8: LanguageSettings, LanguageNegotiator, Localizer, Localized
  Portal/                  A9: Business, PortalService (tek yazma noktası), PortalReport
  Updates/                 A10: Release, ReleaseManifest, CanaryPolicy, RollbackService
  Telemetry/               A10: TelemetrySummary, TelemetryService (onaysız göndermez)
  Licensing/               A10: LicenseChecker, FreeLicense (altyapı)
  Matching/                A11: HardFilter, Matcher, MatchWeights, Candidate, PartnerListings
src/Adapters/              AI kanalı üreticileri (platformdan bağımsız)
  Schema/                  A2: SchemaMap, SchemaBuilder, SchemaValidator, SchemaCache
  Llms/                    A3: LlmsTxtBuilder, LlmsCache
  A2A/                     A12: AgentCardBuilder, AgentCardValidator, JsonRpcServer, A2ASkills
  Rest/                    A5: RestResponder, RestSchemas, ListingsQuery
  Abilities/               A6: AbilitySchemas; A7: InquirySchemas
  Report/                  U4: ComplianceReportData (rapor verisi, tek kaynak), BadgeSvg
src/WordPress/             WordPress adaptörü
  Plugin, Lifecycle, Uninstaller, Requirements, Module
  Platform/                Arayüz uygulamaları: WpSettings, WpCache, WpHttpClient, WpPageFetcher, WpClock, WpSecret
  Storage/                 WpdbHitRepository: aihs_hits tablosuna yazan tek sınıf
  Migrations/              Migration_0_2_0
  Measurement/             Kancalar (RequestListener, MeasurementModule), yönetim sayfası, WP-CLI
  Compliance/              ComplianceModule, AI Uyum sayfası, wp aihs scan
    Wizard/                WizardModule, WizardPage (AI Uyum Sihirbazı)
  Catalog/                 aihs_listing içerik türü, WpListingRepository, WpProfileRepository, AI Katalog ekranları
  Access/                  robots_txt filtresi, AI Bot Erişimi sayfası
  Schema/                  SchemaModule (ana sayfa JSON-LD), CatalogPage (/ai-katalog/), SeoConflict
  Llms/                    LlmsModule (/llms.txt)
  Rest/                    RestModule (aihs/v1 rotaları, önbellek başlıkları, hız sınırı, keşif)
  Abilities/               AbilitiesModule (aihs/ yetenekleri)
  Mcp/                     McpModule, StatelessHttpTransport, McpObservability (/wp-json/aihs/mcp)
  Inquiry/                 InquiryModule, InquiryChannels, WpInquiryRepository (şifreli), WpAuditRepository,
                           WpInquiryNotifier, Admin/InquiryAdmin (Teklif Kutusu)
  Templates/               TemplatesModule (kayıt defteri, aihs_template_dirs, hatalı dosya uyarısı)
  I18n/                    A8: MultilingualModule, LanguageSource (Polylang/WPML), Multilingual, TranslationsAdmin
  Portal/                  A9: PortalModule, Portal, WpBusinessRepository, PortalAdmin (İşletmeler), BusinessAdmin (İşletmem), BusinessPage
  Updates/                 A10: UpdateModule (güncelleme kancaları, geri alma, haftalık özet), UpdatesAdmin, WpPackageInstaller
  Matching/                A11: MatchingModule (adaylar, ortak site önbelleği), MatchingAdmin (Eşleşmeler)
  A2A/                     A12: A2AModule (uç nokta, kartvizit), A2AOutbox, A2AAdmin (onay), ApprovedRequest
  Report/                  U4: ReportModule (AI Uyum Raporu, PDF), ComplianceReportView, BadgeModule (rozet, doğrulama sayfası)
blocks/rozet/              "AI Hazır rozeti" dinamik bloğu (block.json, derlemesiz editör betiği)
bin/paketle                Kurulabilir zip ve güncelleme bildirimi kaydı üretir
data/                      Düzenlenebilir bot ve yönlendirme listeleri, templates/ (sektör şablonları)
docs/                      PRD, görevler, mimari kararlar (ADR)
tests/Unit/                Birim testleri (WordPress'siz; bellek içi adaptörler)
tests/Integration/         Entegrasyon testleri (WordPress test paketi)
tests/Snapshots/           Anlık görüntü dosyaları (ör. robots.txt, Schema.org, llms.txt ve şablon formları)
tests/Support/             Bellek içi test adaptörleri
```

## Seçenekler

| Seçenek | Anlamı |
| --- | --- |
| `aihs_features` | Özellik anahtarları (`measurement`, `compliance_scan`, `catalog`, `bot_access`, `schema_output`, `llms_txt`, `templates`, `compliance_wizard`, `rest_api`, `abilities`, `mcp`, `inquiries`, `compliance_report`, `multilingual`, `portal_mode`, `remote_updates`, `telemetry`, `matching`, `a2a`); `measurement` dışında hepsi varsayılan kapalı |
| `aihs_db_version` | Uygulanan son geçiş sürümü |
| `aihs_delete_data_on_uninstall` | Açıksa eklenti silinirken tüm verisi (tablo dahil) silinir |
| `aihs_ip_ranges` | Botların yayınlanmış IP listeleri önbelleği (otomatik yüklenmez) |
| `aihs_scans` | Son 20 uyum taraması (otomatik yüklenmez) |
| `aihs_first_scan` | İlk uyum taraması, raporun önce/sonra karşılaştırması için (otomatik yüklenmez) |
| `aihs_profile` | Firma profili (otomatik yüklenmez) |
| `aihs_bot_policy` | AI bot erişim ayarları (otomatik yüklenmez) |
| `aihs_profile_updated` | Firma profilinin son güncellenme zamanı (otomatik yüklenmez) |
| `aihs_schema_cache` | Son geçerli Schema.org çıktıları (otomatik yüklenmez) |
| `aihs_schema_error` | Son Schema.org doğrulama hataları (otomatik yüklenmez) |
| `aihs_inquiry_settings` | Teklif kutusu: saklama süresi, hız sınırları, spam eşiği |
| `aihs_wizard` | Sihirbazın uyguladığı adımlar ve önceki değerleri, başlangıç puanı, öneri kapatıldı mı (otomatik yüklenmez) |
| `aihs_languages` | Çoklu dil: varsayılan dil ve diğer diller (Polylang/WPML yoksa) |
| `aihs_profile_translations` | Profil çevirileri (otomatik yüklenmez); ilan çevirileri ilan kaydında `_aihs_translations` |
| `aihs_businesses` | Portal işletmeleri (otomatik yüklenmez); ilanın işletmesi ilan kaydında `_aihs_business`, yetkilinin işletmesi kullanıcıda `aihs_business` |
| `aihs_update_settings` | Güncelleme sunucusu, kanal, rapor paneli adresi |
| `aihs_telemetry_consent` | Rapor paneli onayı: zaman, bildirim sürümü, rastgele site kimliği (otomatik yüklenmez) |
| `aihs_matching` | Eşleştirme ağırlıkları ve ortak site REST adresleri |
| `aihs_llms_cache` | Son üretilen llms.txt metni ve girdisinin parmak izi (otomatik yüklenmez) |

Veritabanı tabloları: `{prefix}aihs_hits`, `{prefix}aihs_inquiries`, `{prefix}aihs_audit_log`. Cron görevleri: `aihs_refresh_ip_ranges` (günlük),
`aihs_prune_hits` (haftalık).
