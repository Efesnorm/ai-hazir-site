# Görev 16 – A10 Merkezi güncelleme ve rapor paneli: plan (onay bekliyor)

> `gorev-16` dalı, `gorev-15` üzerine kuruldu. Kullanıcı uyurken, "onayları sabaha ertele" talimatıyla hazırlandı.
> `main`'e birleştirilmedi, etiket atılmadı. **Hiçbir dış sunucuya istek atılmadı;** sunucu adresi boş gelir.

## 1. Bu görevde güncellenmesi gerekenler (taslağa göre)

- **Güncelleme sunucusunun yeri açık karar (PRD).** Eklenti tarafı sunucudan bağımsız yazıldı: adres
  `AIHS_UPDATE_SERVER` sabitiyle ya da ayar ekranından verilir; boşken hiçbir istek atılmaz.
- **Bildirim biçimi**: yaygın ve denenmiş `wp-update-server` / Plugin Update Checker (YahnisElsts) JSON alanları
  (`version`, `download_url`, `requires`, `tested`, `requires_php`, `sections`) + kanarya ve geri alma için bir
  `releases` listesi (`released_at`, `db_version`). GitHub Releases de aynı JSON'u bir dosya olarak sunabilir.
- **"Rapor paneli"nin sunucu tarafı** (özetleri toplayıp gösteren uygulama) eklentinin dışında ayrı bir projedir;
  bu görevde yalnızca eklentinin gönderen tarafı yapıldı.
- **Lisans** yalnızca altyapı: `LicenseChecker` arayüzü ve "her şey ücretsiz" uygulaması; hiçbir özellik kilitlenmedi.

## 2. Plan

Anahtarlar: `remote_updates`, `telemetry` (ikisi de varsayılan kapalı; `telemetry` ayrıca açık onay ister).

**Çekirdek** `src/Core/Updates/`:
- `Release`, `ReleaseManifest` (bildirim ayrıştırma; hatalı kayıt atlanır).
- `CanaryPolicy`: "pilot" kanalı yeni sürümü hemen görür; "genel" kanal `released_at + 48 saat` sonra.
  Aynı sınıf geri alma hedefini (mevcut sürümden küçük en yeni sürüm) verir.
- `RollbackService`: önce veritabanı geçişlerini hedef sürümün `db_version` değerine `down()` ile indirir, sonra
  önceki paketi kurar (`PackageInstaller` sözleşmesi). Sıra önemli: eski kod yeni geçişlerin `down()`unu bilmez.

**Çekirdek** `src/Core/Telemetry/`:
- `TelemetryConsent` (onay zamanı, bildirim metni sürümü, rastgele site kimliği; geri alınabilir).
- `TelemetrySummary`: yalnızca toplamlar (uyum puanı, puan sürümü, 7 günlük AI bot okuma toplamı, doğrulanmış
  okuma, 7 günlük talep sayısı, eklenti ve WordPress sürümü). Ham kayıt, adres, e-posta, IP gönderilmez.
- `TelemetryService`: onay yoksa, anahtar kapalıysa ya da adres yoksa **hiçbir şey göndermez**. Yeni mimari testi:
  gönderme (`HttpPoster::post`) yalnızca bu servisten.

**WordPress**:
- `pre_set_site_transient_update_plugins` ve `plugins_api` süzgeçleri (WordPress'in standart güncelleme ekranı).
- Ayarlar → **AI Hazır Güncelleme**: sunucu adresi, kanal (pilot/genel), "önceki sürüme dön", rapor paneli onayı
  (gönderilecek verinin tam örneğiyle), onayı geri alma.
- Haftalık `aihs_send_telemetry` görevi yalnızca onay + anahtar + adres varken zamanlanır.
- Paket kurulumu `Plugin_Upgrader` (`overwrite_package`) ile.

## 3. Kaynaklar
- WordPress: `pre_set_site_transient_update_plugins`, `plugins_api`, `Plugin_Upgrader::install( $package, array( 'overwrite_package' => true ) )`
  (WordPress 5.5+), `wp_remote_post`, WP-Cron.
- YahnisElsts/wp-update-server ve plugin-update-checker metadata biçimi (GitHub, MIT).
- KVKK: açık rıza, amaçla sınırlılık, veri en aza indirme.

## 4. Onay bekleyenler (temkinli seçimle uygulandı)
1. Güncelleme sunucusu adresi ve barındırma (PRD açık kararı). Şimdilik boş.
2. Kendi küçük güncelleme istemcimiz (WordPress kancaları) ile hazır **Plugin Update Checker** kütüphanesi arasında
   seçim. Bildirim biçimi PUC ile uyumlu tutuldu; kütüphaneye geçmek istenirse sunucu değişmez.
3. Kanarya kanalının sitede seçilmesi (ayar ekranı); pilot siteler listesinin sunucuda tutulması alternatifi.
4. Geri almada paket kurulumu gerçek ortamda elle denenmedi (geliştirme ortamında eklenti klasörü depoya bağlı;
   üzerine kurmak kaynak kodu silerdi). Veritabanı geri alma ve orkestrasyon testlerle doğrulandı.
5. Özet gönderim sıklığı haftalık; gönderilen alanlar yukarıdaki liste.
6. Mevcut bir testte değişiklik: `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `remote_updates`,
   `telemetry` eklendi.
7. Bildirim indirilirken mevcut `WpHttpClient` kullanıldı; kullanıcı aracısında site adresi gider (WordPress'in kendi
   güncelleme denetimi gibi). İstenirse site adresi göndermeyen ayrı bir istemci yazılabilir.

## 5. Sonuç (uygulandı)
- 3 işleme: çekirdek → WordPress (kancalar, geri alma, özet, ekran) → belgeler/sürüm 1.3.0 (+ `bin/paketle` sürüm kaydı).
- `composer check` temiz: 330 birim, 170 entegrasyon testi.
- Kabul (sahte saat, tüm HTTP istekleri yakalanarak): pilot kanal güncellemeyi hemen, genel kanal 48 saat sonra
  alıyor; geri alma sonrası şema önceki sürümün (200), önceki veriler aynı, önceki paket kuruldu; onay verilmeden hiçbir
  istek atılmıyor, onay geri alınınca gönderim duruyor.
- Elle deneme (geliştirme sitesi): ayar ekranı oluşuyor, adres yokken 0 istek.
