# AI Hazır Site – Proje Kuralları

Bu dosya her oturumda okunur. Kısa ve kesin tutulur; ayrıntılar `docs/` altındadır.

## Proje
WordPress eklentisi. Sitenin "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini
AI agentların okuyup kullanabileceği biçimde yayınlar ve AI Agent Uyum Paketi sunar.
Referans: `docs/PRD.md` (iş planı ve yol haritası). Aktif görev: `docs/gorevler/` altındaki
bana verilen tek dosya.

## Temel kural (değişmez)
Her artım tek başına çalışır, test edilmiştir ve kullanılabilirdir.
Yeni artım, mevcut çalışan sistemi bozmadan eklenir.
- Sadece verilen görev dosyasının kapsamında çalış. Kapsam dışı iş görürsen yapma, not al.
- Kod yazmadan önce plan çıkar ve onayımı bekle.
- Mevcut testleri asla silme veya zayıflatma. Bir test kırılıyorsa kodu düzelt.

## Sabitler (açık kararlar kapanana kadar geçici)
- Eklenti slug ve text domain: `ai-hazir-site`
- PHP ön eki: `aihs_` | Namespace: `AIHazirSite\`
- En düşük sürümler: PHP 8.1, WordPress 6.9
- Lisans: GPL-2.0-or-later

## Mimari
Ayrıntı: `docs/mimari/ADR-001-coklu-platform.md`. Hedef: WordPress dışındaki platformlarda da çalışmak.
- Üç katman: `src/Core` (platformdan bağımsız iş kuralları ve `src/Core/Contracts` arayüzleri),
  platform adaptörleri (`src/WordPress`, ileride `src/<Platform>`), AI kanalları (`src/Adapters/<Ad>`).
- `src/Core` hiçbir platformun fonksiyonunu, sınıfını veya sabitini kullanmaz; platforma yalnızca
  `Contracts` arayüzleriyle erişir. `CoreIsPlatformNeutralTest` bunu denetler.
- Bağımlılık yönü platform → çekirdek. Yeni iş kuralı önce çekirdeğe, platform bağlantısı adaptöre yazılır.
- Adaptörler çekirdeği sadece okur, birbirini çağırmaz.
- Yazma işlemleri tek bir çekirdek arayüzünden geçer (ölçüm: `HitRepository`; WordPress'te tek yazan
  `WpdbHitRepository`).
- Her yeni özellik `src/Core/Features.php` içinde bir anahtara bağlıdır ve varsayılan KAPALI gelir
  (onaylı istisnalar `Features::DEFAULT_ON` ve CHANGELOG'da gerekçeli).

## Veritabanı
- Şema değişiklikleri sadece eklemedir: yeni tablo veya yeni sütun. Silme ve yeniden adlandırma yok.
- Her geçiş `MigrationInterface`'i uygulayan sürümlü bir sınıftır ve `down()` metodu vardır
  (WordPress: `src/WordPress/Migrations/`).
- Kişisel veri (ham IP, e-posta vb.) saklanmaz. Gerekirse tuzlu hash kullan.
- İstisna (0.12.0, onaylı): teklif kutusunda talep sahibinin iletişim bilgisi şifreli saklanır, saklama süresi sonunda ve talep üzerine silinir.

## Kod standartları
- PSR-4 otomatik yükleme (Composer). `declare(strict_types=1);` her dosyada.
- WordPress Kodlama Standartları (PHPCS/WPCS). PHPStan seviye 6.
- Tüm girdiler temizlenir (`sanitize_*`), tüm çıktılar kaçışlanır (`esc_*`), tüm formlar nonce ile korunur.
- Kullanıcıya görünen metinler çevrilebilir: `__( '...', 'ai-hazir-site' )`.
- Kod ve tanımlayıcılar İngilizce, kullanıcı metinleri ve belgeler Türkçe.

## Komutlar
- Ortamı başlat: `npx wp-env start`
- Testler: `composer test`
- Statik analiz: `composer phpstan`
- Kod stili: `composer phpcs` (düzeltme: `composer phpcbf`)
- Hepsi: `composer check`

## Bitti Tanımı
Bir görev ancak şunların hepsi sağlanınca bitmiştir:
1. Görev dosyasındaki kabul testleri geçti.
2. Yeni kodun birim ve entegrasyon testleri yazıldı.
3. `composer check` temiz; önceki tüm testler geçiyor.
4. Veritabanı değişikliği ekleme şeklinde ve geri alınabilir.
5. Yeni özellik bir anahtarla kapatılabiliyor.
6. `CHANGELOG.md` güncellendi, sürüm numarası artırıldı (SemVer).
7. Sonunda bana kısa bir özet ver: ne yapıldı, nasıl test ettim, bilinen sınırlar.
