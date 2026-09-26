# AI Hazır Site

Sitenin "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini AI agentların okuyup
kullanabileceği biçimde yayınlayan WordPress eklentisi.

> Sürüm 0.2.1: AI bot ve AI yönlendirme ölçümü (A0); çekirdek platformdan bağımsız. Ayrıntılar: [CHANGELOG.md](CHANGELOG.md).

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

## Klasör yapısı

```
ai-hazir-site.php          Eklenti başlığı, sürüm kontrolü, açılış
uninstall.php              Silmede veri temizliği (yalnızca seçenek açıksa)
src/Core/                  Platformdan bağımsız çekirdek (WordPress fonksiyonu kullanmaz)
  Contracts/               Arayüzler: Settings, Cache, HttpClient, Clock, Secret, HitRepository
  Features.php             Özellik anahtarları
  Migrations/              MigrationInterface, Migrator
  Measurement/             A0 iş kuralları: Classifier, Verifier, IpRanges, Tracker, Report, CsvExport
src/WordPress/             WordPress adaptörü
  Plugin, Lifecycle, Uninstaller, Requirements, Module
  Platform/                Arayüz uygulamaları: WpSettings, WpCache, WpHttpClient, WpClock, WpSecret
  Storage/                 WpdbHitRepository: aihs_hits tablosuna yazan tek sınıf
  Migrations/              Migration_0_2_0
  Measurement/             Kancalar (RequestListener, MeasurementModule), yönetim sayfası, WP-CLI
data/                      Düzenlenebilir bot ve yönlendirme listeleri
docs/                      PRD, görevler, mimari kararlar (ADR)
tests/Unit/                Birim testleri (WordPress'siz; bellek içi adaptörler)
tests/Integration/         Entegrasyon testleri (WordPress test paketi)
tests/Support/             Bellek içi test adaptörleri
```

## Seçenekler

| Seçenek | Anlamı |
| --- | --- |
| `aihs_features` | Özellik anahtarları (`dizi<string, bool>`); `measurement` dışında hepsi varsayılan kapalı |
| `aihs_db_version` | Uygulanan son geçiş sürümü |
| `aihs_delete_data_on_uninstall` | Açıksa eklenti silinirken tüm verisi (tablo dahil) silinir |
| `aihs_ip_ranges` | Botların yayınlanmış IP listeleri önbelleği (otomatik yüklenmez) |

Veritabanı tablosu: `{prefix}aihs_hits`. Cron görevleri: `aihs_refresh_ip_ranges` (günlük),
`aihs_prune_hits` (haftalık).
