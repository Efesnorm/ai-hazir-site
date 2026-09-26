# Değişiklik Günlüğü

Bu projedeki önemli değişiklikler bu dosyada tutulur.
Biçim [Keep a Changelog](https://keepachangelog.com/tr-TR/1.1.0/) esaslıdır, sürümler [SemVer](https://semver.org/lang/tr/) izler.

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
