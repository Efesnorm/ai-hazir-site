# AI Hazır Site

Sitenin "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini AI agentların okuyup
kullanabileceği biçimde yayınlayan WordPress eklentisi.

> Sürüm 0.2.0: AI bot ve AI yönlendirme ölçümü (A0). Ayrıntılar: [CHANGELOG.md](CHANGELOG.md).

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
ai-hazir-site.php     Eklenti başlığı, sürüm kontrolü, açılış
uninstall.php         Silmede veri temizliği (yalnızca seçenek açıksa)
src/Core/             Çekirdek: Plugin, Features, Requirements, Lifecycle, Uninstaller
src/Core/Migrations/  Sürümlü veritabanı geçişleri
src/Core/Storage/     HitStore: aihs_hits tablosuna yazan tek sınıf
src/Modules/Measurement/  A0 ölçümü: Tracker, Classifier, Verifier, IpRanges, Report, Admin, Cli
data/                 Düzenlenebilir bot ve yönlendirme listeleri
tests/Unit/           Birim testleri (Brain Monkey)
tests/Integration/    Entegrasyon testleri (WordPress test paketi)
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
