# Değişiklik Günlüğü

Bu projedeki önemli değişiklikler bu dosyada tutulur.
Biçim [Keep a Changelog](https://keepachangelog.com/tr-TR/1.1.0/) esaslıdır, sürümler [SemVer](https://semver.org/lang/tr/) izler.

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
