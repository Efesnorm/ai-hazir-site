# Görev 00 – Proje iskeleti (sürüm 0.1.0)

## Amaç
Hiçbir özelliği olmayan ama kurulan, etkinleştirilen, test edilen ve CI'dan geçen boş bir eklenti.
Sonraki tüm artımlar bu iskeletin üzerine eklenecek.

## Kapsam
1. `composer.json`: PSR-4 (`AIHazirSite\` → `src/`), geliştirme bağımlılıkları (PHPUnit, WPCS,
   PHPStan + WordPress eklentisi), betikler: `test`, `phpstan`, `phpcs`, `phpcbf`, `check`.
2. `ai-hazir-site.php`: eklenti başlığı (ad, sürüm, en düşük PHP/WP, lisans, text domain),
   PHP ve WP sürüm kontrolü (yetersizse yönetici uyarısı gösterip çalışmayı durdurur), `Plugin::boot()`.
3. `src/Core/Plugin.php`: tek giriş noktası; modülleri kaydeder.
4. `src/Core/Features.php`: özellik anahtarları. Seçenekte (`aihs_features`) saklanır,
   bilinmeyen anahtar `false` döner. Şimdilik boş liste.
5. `src/Core/Migrations/`: `MigrationInterface` (`up()`, `down()`, `version()`) ve sürümleri
   sırayla çalıştıran `Migrator`. Uygulanan son sürüm `aihs_db_version` seçeneğinde tutulur.
6. Etkinleştirme / devre dışı bırakma kancaları. `uninstall.php`: veriyi SADECE
   `aihs_delete_data_on_uninstall` seçeneği açıksa siler.
7. `.wp-env.json`, `phpunit.xml.dist`, `phpstan.neon`, `phpcs.xml.dist`.
8. `.github/workflows/ci.yml`: PHP 8.1 ve 8.3 × WordPress 6.9 ve en son sürüm matrisi;
   phpcs, phpstan ve testleri çalıştırır.
9. `README.md` (kurulum ve komutlar), `CHANGELOG.md`, `.gitignore`.

## Kapsam dışı
Hiçbir kullanıcı özelliği, yönetim sayfası, veri tablosu veya AI çıktısı yok.

## Kabul testleri
- [ ] Eklenti wp-env içinde hatasız etkinleşiyor ve devre dışı kalıyor (PHP uyarısı yok).
- [ ] PHP 8.0 gibi desteklenmeyen sürümde yönetici uyarısı gösteriyor ve çalışmıyor (birim testi).
- [ ] `Features::is_enabled('olmayan')` `false` döner (birim testi).
- [ ] `Migrator` iki sahte geçişi sırayla uygular, ikinci çalıştırmada tekrar uygulamaz,
      `down()` ile geri alır (entegrasyon testi).
- [ ] `uninstall.php` seçenek kapalıyken hiçbir şey silmiyor (entegrasyon testi).
- [ ] `composer check` temiz, GitHub Actions yeşil.

## Çalışma şekli
Önce dosya ağacını ve adımları plan olarak yaz, onayımı bekle. Sonra küçük adımlarla ilerle;
her adımda testleri çalıştır.
