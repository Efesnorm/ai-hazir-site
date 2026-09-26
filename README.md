# AI Hazır Site

Sitenin "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini AI agentların okuyup
kullanabileceği biçimde yayınlayan WordPress eklentisi.

> Sürüm 0.1.0 yalnızca iskelettir: kullanıcıya görünen bir özellik yoktur.

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

Entegrasyon testleri, geliştirme sitesiyle aynı veritabanında ama ayrı bir tablo önekinde
(`aihstests_`) çalışır; geliştirme sitesinin verisine dokunmaz.

## Klasör yapısı

```
ai-hazir-site.php     Eklenti başlığı, sürüm kontrolü, açılış
uninstall.php         Silmede veri temizliği (yalnızca seçenek açıksa)
src/Core/             Çekirdek: Plugin, Features, Requirements, Lifecycle, Uninstaller
src/Core/Migrations/  Sürümlü veritabanı geçişleri
tests/Unit/           Birim testleri (Brain Monkey)
tests/Integration/    Entegrasyon testleri (WordPress test paketi)
```

## Seçenekler

| Seçenek | Anlamı |
| --- | --- |
| `aihs_features` | Özellik anahtarları (`dizi<string, bool>`); hepsi varsayılan kapalı |
| `aihs_db_version` | Uygulanan son geçiş sürümü |
| `aihs_delete_data_on_uninstall` | Açıksa eklenti silinirken tüm verisi silinir |
