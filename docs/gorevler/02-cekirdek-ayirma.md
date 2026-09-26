# Görev 02 – Çekirdeği platformdan ayırma (sürüm 0.2.1)

## Amaç
[ADR-001](../mimari/ADR-001-coklu-platform.md) gereği `src/Core` altındaki kodu WordPress'ten bağımsız
hale getirmek. Kullanıcı açısından hiçbir şey değişmez; yeni platform eklemek ince bir adaptör yazmaya
indirgenir.

## Kapsam
1. **Arayüzler** `src/Core/Contracts/`: `Settings` (anahtar–değer, otomatik yükleme bayrağı),
   `Cache` (süreli), `HttpClient` (`get(url): ?string`), `Clock` (`today()`, `now()`),
   `Secret` (HMAC anahtarı), `HitRepository` (artır, budama, rapor toplamları).
2. **Çekirdeğe taşınanlar** (platformdan bağımsız): `Features`, `MigrationInterface`, `Migrator`,
   ölçüm iş kuralları → `src/Core/Measurement/` (`Bot`, `Referrer`, `Registry`, `Classifier`,
   `IpRanges`, `Verifier`, `Tracker`, `Report`, `CsvExport`, yeni `Request` değer nesnesi).
   `Tracker` artık `$_SERVER` okumaz; platform adaptörü bir `Request` üretir ve isteğin sayılabilir olup
   olmadığına (yönetici paneli, cron, AJAX) adaptör karar verir.
3. **WordPress adaptörü** `src/WordPress/`: `Plugin`, `Module`, `Lifecycle`, `Uninstaller`,
   `Requirements`, `Migrations/Migration_0_2_0`, arayüz uygulamaları (`WpSettings`, `WpCache`,
   `WpHttpClient`, `WpClock`, `WpSecret`, `WpdbHitRepository`), `Measurement/MeasurementModule`,
   `Measurement/Admin/ReportPage`, `Measurement/Cli/HitsCommand`.
4. **Koruma testi**: `src/Core` altındaki hiçbir dosya PHP'nin kendi fonksiyonları dışında global bir
   fonksiyon çağırmaz ve `WP_*` / `wpdb` sınıflarına başvurmaz.
5. **Test adaptörleri** `tests/Support/`: bellek içi `Settings`, `Cache`, `HttpClient`, `Clock`,
   `Secret`, `HitRepository`.
6. `CLAUDE.md` mimari bölümü ADR-001'e göre güncellenir; `README.md` klasör yapısı güncellenir.

## Değişmeyenler (geriye uyumluluk)
Seçenek adları (`aihs_*`), tablo (`{prefix}aihs_hits`) ve şeması, cron kanca adları, yönetim sayfası
adresi, CSV biçimi, WP-CLI komutu ve `data/` dosyaları aynen kalır. Veritabanı geçişi yoktur.

## Kapsam dışı
Yeni platform adaptörü, yeni kullanıcı özelliği, merkezi servis, performans iyileştirmesi.

## Kabul testleri
- [ ] Görev 00 ve 01'in tüm testleri davranış değişikliği olmadan geçiyor (yalnızca sınıf adları/yolları
      güncellenebilir; hiçbir doğrulama gevşetilmez).
- [ ] Koruma testi: `src/Core` içinde WordPress fonksiyonu veya sınıfı kullanımı yok; bilerek eklenen bir
      `get_option()` çağrısı testi kırmızıya düşürüyor (birim).
- [ ] Ölçüm akışı (sınıflandır → doğrula → say → rapor → CSV) WordPress yüklenmeden, bellek içi
      adaptörlerle uçtan uca çalışıyor (birim).
- [ ] 0.2.0 kurulu bir site 0.2.1'e güncellenince veriler, ayarlar ve cron görevleri aynen korunuyor
      (entegrasyon).
- [ ] Sayaç ek süresi hâlâ ortalama 5 ms'nin altında (entegrasyon).
- [ ] `composer check` temiz, GitHub Actions yeşil.

## Çalışma şekli
Önce plan ve dosya ağacı, onayımı bekle. Sonra küçük adımlarla ilerle; her adım sonunda tüm testler
yeşil olmalı ve ayrı commit olmalı.
