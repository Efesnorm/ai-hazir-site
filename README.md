# AI Hazır Site

Sitenin "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini AI agentların okuyup
kullanabileceği biçimde yayınlayan WordPress eklentisi.

> Sürüm 0.8.0: AI bot ve yönlendirme ölçümü (A0), AI uyum taraması (U1), veri modeli ve yönetim formları (A1), AI bot erişim ayarları (U2), Schema.org yapılandırılmış veri (A2), llms.txt ve AI katalog sayfası (A3), sektör şablonları (A4). Ayrıntılar: [CHANGELOG.md](CHANGELOG.md).

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
                           ListingRepository, ProfileRepository
  Features.php             Özellik anahtarları
  Migrations/              MigrationInterface, Migrator
  Measurement/             A0 iş kuralları: Classifier, Verifier, IpRanges, Tracker, Report, CsvExport
  Compliance/              U1: Site, Html, Robots, Scanner, ScoreReport, ScanStore, Checks/ (7 kontrol)
  Catalog/                 A1: Listing, CompanyProfile, doğrulayıcılar, ListingValidity, CatalogService (tek yazma noktası)
  Access/                  U2: BotPolicy, Presets, RobotsRules, PolicyStore
  Templates/               A4: Template, TemplateField, TemplateRegistry, TemplateValidator, Freshness
src/Adapters/              AI kanalı üreticileri (platformdan bağımsız)
  Schema/                  A2: SchemaMap, SchemaBuilder, SchemaValidator, SchemaCache
  Llms/                    A3: LlmsTxtBuilder, LlmsCache
src/WordPress/             WordPress adaptörü
  Plugin, Lifecycle, Uninstaller, Requirements, Module
  Platform/                Arayüz uygulamaları: WpSettings, WpCache, WpHttpClient, WpPageFetcher, WpClock, WpSecret
  Storage/                 WpdbHitRepository: aihs_hits tablosuna yazan tek sınıf
  Migrations/              Migration_0_2_0
  Measurement/             Kancalar (RequestListener, MeasurementModule), yönetim sayfası, WP-CLI
  Compliance/              ComplianceModule, AI Uyum sayfası, wp aihs scan
  Catalog/                 aihs_listing içerik türü, WpListingRepository, WpProfileRepository, AI Katalog ekranları
  Access/                  robots_txt filtresi, AI Bot Erişimi sayfası
  Schema/                  SchemaModule (ana sayfa JSON-LD), CatalogPage (/ai-katalog/), SeoConflict
  Llms/                    LlmsModule (/llms.txt)
  Templates/               TemplatesModule (kayıt defteri, aihs_template_dirs, hatalı dosya uyarısı)
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
| `aihs_features` | Özellik anahtarları (`measurement`, `compliance_scan`, `catalog`, `bot_access`, `schema_output`, `llms_txt`, `templates`); `measurement` dışında hepsi varsayılan kapalı |
| `aihs_db_version` | Uygulanan son geçiş sürümü |
| `aihs_delete_data_on_uninstall` | Açıksa eklenti silinirken tüm verisi (tablo dahil) silinir |
| `aihs_ip_ranges` | Botların yayınlanmış IP listeleri önbelleği (otomatik yüklenmez) |
| `aihs_scans` | Son 20 uyum taraması (otomatik yüklenmez) |
| `aihs_profile` | Firma profili (otomatik yüklenmez) |
| `aihs_bot_policy` | AI bot erişim ayarları (otomatik yüklenmez) |
| `aihs_profile_updated` | Firma profilinin son güncellenme zamanı (otomatik yüklenmez) |
| `aihs_schema_cache` | Son geçerli Schema.org çıktıları (otomatik yüklenmez) |
| `aihs_schema_error` | Son Schema.org doğrulama hataları (otomatik yüklenmez) |
| `aihs_llms_cache` | Son üretilen llms.txt metni ve girdisinin parmak izi (otomatik yüklenmez) |

Veritabanı tablosu: `{prefix}aihs_hits`. Cron görevleri: `aihs_refresh_ip_ranges` (günlük),
`aihs_prune_hits` (haftalık).
