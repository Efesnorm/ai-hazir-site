# Değişiklik Günlüğü

Bu projedeki önemli değişiklikler bu dosyada tutulur.
Biçim [Keep a Changelog](https://keepachangelog.com/tr-TR/1.1.0/) esaslıdır, sürümler [SemVer](https://semver.org/lang/tr/) izler.

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
