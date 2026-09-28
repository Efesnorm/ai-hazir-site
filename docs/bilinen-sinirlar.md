# Bilinen sınırlar (1.1.0)

Bu liste MVP sürümünde bilerek bırakılan veya henüz çözülmemiş konuları toplar. Her madde ileride bir görevle ele alınabilir.

## Kullanım

- **Genel ayar ekranı yok.** Yönetim ekranından yalnızca `measurement` (AI Ölçüm sayfası) ve `inquiries`
  (Teklif Kutusu) açılıp kapatılabilir; sihirbaz birkaç anahtarı kendisi açar. Diğer anahtarlar ve sihirbazın
  kendisi WP-CLI ile açılır ([başlangıç](kullanim/baslangic.md)).
- **Silmede veri temizliği** seçeneğinin ekranı yok; WP-CLI ile açılır.
- Arayüz metinleri Türkçe; çeviri dosyası (`.pot`) henüz üretilmiyor.

## Çoklu dil (1.1.0)

- Yalnızca ilan başlığı, açıklaması, kategorisi, bölgesi ve profil sektörü çevrilir.
- Şablon alan etiketleri (ör. "Kalan yer"), müsaitlik cevabının gerekçeleri ve llms.txt / katalog sayfasının
  kalıp metinleri (ör. "Geçerlilik") Türkçe kalır.
- JSON-LD'de eksik çeviri alan bazında işaretlenmez; belgenin dili `inLanguage` ile verilir.
- Polylang ve WPML ile uyum, resmi API'lerini taklit eden testlerle denendi; gerçek eklentilerle elle deneme yapılmadı.
- Ana sayfadaki `Organization` JSON-LD'si dile göre değişmez (çevrilebilir alan içermez).

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

## Bilinen hata

- AI bot erişimi (U2): robots.txt'ye eklenen izin grubunda yalnızca WordPress'in `/wp-admin/` kuralları var, açık
  `Allow: /` satırı yok. Kurallar yine doğru yorumlanır (engellenmeyen yol izinlidir), ancak bazı denetim araçları
  izni açıkça görmek ister.
