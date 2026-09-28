# Bilinen sınırlar (1.0.0)

Bu liste MVP sürümünde bilerek bırakılan veya henüz çözülmemiş konuları toplar. Her madde ileride bir görevle ele alınabilir.

## Kullanım

- **Genel ayar ekranı yok.** Yönetim ekranından yalnızca `measurement` (AI Ölçüm sayfası) ve `inquiries`
  (Teklif Kutusu) açılıp kapatılabilir; sihirbaz birkaç anahtarı kendisi açar. Diğer anahtarlar ve sihirbazın
  kendisi WP-CLI ile açılır ([başlangıç](kullanim/baslangic.md)).
- **Silmede veri temizliği** seçeneğinin ekranı yok; WP-CLI ile açılır.
- Arayüz metinleri Türkçe; çeviri dosyası (`.pot`) henüz üretilmiyor. Çok dil desteği Görev 14 (A8) kapsamında.

## Tarama ve rapor

- Tarama sitenin kendi adreslerini HTTP ile çeker. Kendine istek atamayan ortamlarda (bazı yerel Docker
  kurulumları, sıkı güvenlik duvarları) kontroller "ölçülemedi" olur.
- Tarama en fazla 10 sayfaya ve toplam 60 saniyeye bakar; büyük sitelerde örneklemdir.
- Puanlama yöntemi değişirse (puan sürümü) önce/sonra karşılaştırması yapılmaz.
- AI Hazır doğrulaması sitenin kendi taramasına dayanır; bağımsız bir doğrulama değildir.
- Rapor ve PDF Türkçedir; PDF'e yalnızca DejaVu Sans yazı tipi gömülür.

## Ölçüm

- Bot doğrulaması, işletmecinin yayımladığı IP listesi veya ters DNS kaydıyla yapılır; ikisini de sunmayan botlar "doğrulanmamış" sayılır.
- Sayfa önbelleği (ör. tam sayfa önbellek eklentileri, CDN) önünde sunulan istekler PHP'ye ulaşmadığı için ölçülemez.

## AI Katalog, REST ve MCP

- MCP sunucusu oturumsuz çalışır ve yalnızca `POST` kabul eder (MCP 2025-06-18'de oturum isteğe bağlıdır).
  Sunucudan istemciye bildirim (SSE) yoktur.
- MCP Adapter 0.6.1'e sabitlenmiştir; yeni sürümler denendikten sonra güncellenir.
- REST ve MCP hız sınırları istemci IP'sinin tuzlu özetine göre tutulur; paylaşılan IP arkasındaki istemciler
  aynı sınırı paylaşır.
- Schema.org çıktısında ana sayfada yalnızca `Organization`; ilanlar /ai-katalog/ sayfasında `DataFeed` olarak yayınlanır.

## Teklif kutusu

- Talep sahibine otomatik yanıt gönderilmez (bilinçli karar); firma kendisi döner.
- Bildirim e-postası sitenin `wp_mail` ayarına bağlıdır; e-posta gönderemeyen sunucularda bildirim gitmez,
  talepler yine Teklif Kutusu'nda görünür.
- Şifreleme anahtarı sitenin `AUTH_SALT` / `AUTH_KEY` değerinden türetilir. Bu değerler değiştirilirse eski
  taleplerin iletişim bilgileri okunamaz.

## AI bot erişimi (U2): izin grubunda neden `Allow: /` yok?

- robots.txt'ye eklenen izin grubunda yalnızca WordPress'in `/wp-admin/` kuralları var; açık `Allow: /` satırı **bilerek**
  eklenmez. Başka bir eklenti bir botu adıyla engellediğinde (`User-agent: GPTBot` / `Disallow: /`) RFC 9309'a göre
  aynı botun grupları birleşir ve eşit uzunluktaki kurallarda izin kazanır; bizim `Allow: /` satırımız o eklentinin
  engelini sessizce kaldırırdı. Şimdiki hâliyle engel geçerli kalır ve sihirbaz bunu "elle yapılacak" adım olarak gösterir.
- Engellenmeyen yol zaten izinli olduğundan kurallar doğru yorumlanır. İzni açıkça görmek isteyen bazı denetim
  araçları grubu boş sayabilir; bu, başka eklentilerin kararına saygı için kabul edilen bir sınırdır.
- Bu davranış `WizardFlowTest::test_named_block_is_manual` testiyle korunur (2026-09-28'de denendi: `Allow: /`
  eklenince bu test ve `RobotsFilterTest` kırılıyor).
