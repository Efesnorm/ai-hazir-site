# Değişiklik Günlüğü

Bu projedeki önemli değişiklikler bu dosyada tutulur.
Biçim [Keep a Changelog](https://keepachangelog.com/tr-TR/1.1.0/) esaslıdır, sürümler [SemVer](https://semver.org/lang/tr/) izler.

## [0.7.0] - 2026-09-27

### Eklendi
- **A3 llms.txt ve AI katalog sayfası** (`llms_txt` anahtarı, varsayılan kapalı).
  - `/llms.txt`, [llmstxt.org](https://llmstxt.org/) önerisine göre: firma adıyla H1, özet alıntısı, ülke / dil /
    sertifika / son güncelleme, satılanlar / arananlar / tedarik edilebilenler bölümleri (her ilan AI katalog
    sayfasındaki çapasına bağlanır; kategori, miktar, fiyat veya bütçe, bölge, teslim süresi, geçerlilik,
    özellikler), İletişim ve "Optional" altında AI katalog bağlantısı.
  - `text/plain; charset=utf-8`, UTF-8, LF, BOM yok. Üretim platformdan bağımsız
    `src/Adapters/Llms/LlmsTxtBuilder`.
  - İstek yolu `parse_request` üzerinde doğrudan eşlenir; kalıcı bağlantı ayarı ne olursa olsun çalışır ve
    yönlendirme kuralı gerektirmez.
  - Metin yalnızca veri değişince (profil, ilanlar, gün, adresler) yeniden üretilir; aksi halde
    `aihs_llms_cache` seçeneğinden sunulur.
  - Sitenin kök dizininde fiziksel bir `llms.txt` varsa ona dokunulmaz, sanal adres devreye girmez;
    yöneticiye uyarı ve eklenecek metin gösterilir.
- **AI katalog sayfası** (`/ai-katalog/`): artık `schema_output` **veya** `llms_txt` açıkken sunulur.
  Firma özeti, her ilan için `ilan-{id}` çapası ve ayrıntı satırı eklendi. JSON-LD yalnızca `schema_output`
  açıkken eklenir.
- **A0 raporu**: "AI dosyaları" bölümü (AI Ölçüm sayfası, CSV'de "AI dosyası", WP-CLI'da `ai_files`).
  Botların `/llms.txt` ve `/ai-katalog/` okumaları ilk 10 sayfada olmasa da gösterilir. Bu istekler zaten
  sayılıyordu; yeni bir kayıt eklenmedi.
- Çekirdek `ListingValidity`: Schema.org, llms.txt ve katalog sayfası aynı geçerlilik kuralını kullanır
  (kendi tarihi, yoksa son güncelleme + 90 gün). JSON-LD çıktısı değişmedi.

### Bilinen sınırlar
- Sunucu, var olmayan `/llms.txt` isteğini WordPress'e iletmiyorsa (ör. `.htaccess` ya da nginx
  `try_files` yoksa) sanal adres çalışamaz.
- Site bir alt dizindeyse dosya `/{alt-dizin}/llms.txt` adresinde sunulur; kök alan adındaki `/llms.txt`
  için sunucu ayarı gerekir.
- Önbellek eklentileri `/llms.txt`'yi saklıyorsa değişiklik önbellek temizlenene kadar görünmeyebilir.
- Metinler Türkçedir ve çevrilebilir; çok dilli llms.txt A8'de.

## [0.6.0] - 2026-09-27

### Eklendi
- **A2 Schema.org yapılandırılmış veri** (`schema_output` anahtarı, varsayılan kapalı).
  - Ana sayfa: firma profilinden `Organization` (ad, adres, iletişim, uzmanlık, dil) ve `WebPage`
    JSON-LD'si, `wp_head` içinde. `dateModified` profil ve ilanlardaki en son değişikliktir.
  - Sanal **/ai-katalog/** sayfası: JavaScript'siz, sade HTML liste (satılan / aranan / tedarik edilebilen)
    ve `DataFeed` JSON-LD'si. Satılan → `Offer`, aranan → `Demand` (`Organization.seeks` karşılığı),
    tedarik edilebilen → `Offer` + `availability: MadeToOrder` ve `deliveryLeadTime`.
  - Tek eşleme tablosu `SchemaMap`; üretim platformdan bağımsız `src/Adapters/Schema` altında
    (`SchemaBuilder`, `SchemaValidator`, `SchemaCache`).
  - Her çıktı yayından önce doğrulanır. Hatalı çıktı yayınlanmaz; son geçerli çıktı sunulur ve yöneticiye
    hata (sayfa, zaman, hatalar) bildirilir.
  - Yoast SEO, Rank Math, All in One SEO, SEOPress veya The SEO Framework etkinse kendi `Organization`
    düğümümüz eklenmez, ilanlar firmaya adla bağlanır ve yöneticiye uyarı gösterilir. Diğer eklentiler
    `aihs_schema_seo_conflict` filtresiyle bildirilebilir.
  - U1 taramasında bu çıktılarla "yapılandırılmış veri" ve "tazelik" kontrolleri tam puan alır.
- `ProfileRepository::updated_at()`; profil kaydedilince `aihs_profile_updated` seçeneği güncellenir.

### Kararlar
- `dateModified` yalnızca `CreativeWork` ve `DataFeedItem` üzerinde geçerli olduğu için her ilan bir
  `DataFeedItem` içine konur; tarih ilanın son güncellemesidir.
- Geçerlilik tarihi girilmemiş ilana 90 günlük varsayılan `validThrough` verilir; süresi dolan ilan
  hiçbir çıktıda yer almaz.
- Fiyatı olmayan satılan ilan da `Offer` olarak yayınlanır; doğrulayıcı uyarı verir (Google zengin
  sonuçları fiyat ister).
- Fiyat aralığında `price` en düşük değerdir, aralık `priceSpecification` (`minPrice`/`maxPrice`) ile verilir.

### Bilinen sınırlar
- Elle doğrulama (validator.schema.org) henüz yapılmadı; otomatik doğrulama `SchemaValidator` ve anlık
  görüntü testleriyle yapılır.
- Ana sayfa ve /ai-katalog/ dışındaki sayfalara şema eklenmez; U1 bu sayfalarda yapılandırılmış veri
  bulamazsa puan düşebilir.
- SEO eklentisi tespiti sabitlere dayanır; tanınmayan eklentiler filtreyle bildirilmelidir.
- /ai-katalog/ yeniden yazma kuralı anahtar açıldıktan sonraki ilk istekte eklenir.

## [0.5.0] - 2026-09-27

### Eklendi
- **U2 AI bot erişim ayarları** (`bot_access` anahtarı, varsayılan kapalı).
  - Her AI botu için "site kuralları / izin ver / engelle"; hazır ayarlar: "Hepsine izin ver",
    "Sadece arama ve kullanıcı agentları", "Eğitim botlarını engelle" (bot listesi ve kategoriler
    `data/ai-bots.json`).
  - WordPress'in sanal robots.txt'sine `robots_txt` filtresiyle, işaretli bir blok olarak eklenir
    (`# BEGIN AI Hazir Site ... # END AI Hazir Site`). Mevcut kurallar ve diğer eklentilerin satırları
    değişmez; anahtar kapatılınca robots.txt eski haline döner, ayar saklanır.
  - "İzin ver" grubu, RFC 9309 gereği `*` grubunu yok saydığı için `/wp-admin/` kısıtlamasını tekrarlar.
  - Fiziksel robots.txt dosyası varsa dosyaya dokunulmaz; uyarı ve eklenecek metin gösterilir.
  - Araçlar → AI Bot Erişimi: bot, işletmeci, kategori, ayar, robots.txt'nin tamamından hesaplanan
    gerçek durum, önizleme; "arama motorlarını engelle" ve önbellek uyarıları.

### Değişti
- U1 `bot_access` kontrolü, AI Bot Erişimi ayarıyla bilerek engellenen botları "bilerek engellendi"
  olarak gösterir ve puandan düşmez. Puanlama sürümü (`score_version`) 1 → 2; eski taramalar sürüm 1
  olarak kalır.

### Bilinen sınırlar
- robots.txt bir ricadır; kurallara uymayan botları durdurmaz (sunucu/CDN engeli kapsam dışı).
- Önbellek eklentileri robots.txt'yi saklıyorsa değişiklik önbellek temizlenene kadar görünmeyebilir.
- Ekrandaki "şu anki durum", robots.txt önizlemesi için `do_robots()` çıktısını yeniden kurar;
  `do_robotstxt` eylemine doğrudan yazan eklentilerin satırları önizlemede görünmez.

## [0.4.0] - 2026-09-27

### Eklendi
- **A1 veri modeli ve yönetim formları** (`catalog` anahtarı, varsayılan kapalı).
  - Çekirdek: `Listing` (satılan / aranan / tedarik edilebilen; 14 alan, fiyat ve miktar ondalık metin),
    `CompanyProfile` (kişisel veri alanı yok), `ListingValidator`, `ProfileValidator`,
    `ListingRepository` ve `ProfileRepository` arayüzleri.
  - `CatalogService` tek yazma noktası: önce doğrular (zorunlu başlık, fiyat aralığı, ISO 4217 para birimi,
    geçmiş geçerlilik tarihi yasak), sonra yazar. Mimari testi başka yerden yazılmasını engeller.
  - WordPress: `aihs_listing` içerik türü (herkese açık adres, arşiv, arama, REST ve çekirdek düzenleme
    ekranı yok), alanlar `_aihs_` önekli korumalı meta; profil `aihs_profile` seçeneğinde.
  - AI Katalog menüsü: Satılanlar, Arananlar, Tedarik Edilebilenler (tür, geçerlilik, son güncelleme
    sütunları; süresi dolanlar işaretlenir, silinmez) ve Firma Profili. Nonce, `manage_options`,
    girdi temizleme ve çıktı kaçışlama. Kişisel görünen e-posta için uyarı.
- Kaldırmada, silme seçeneği açıksa ilanlar ve profil de `CatalogService` üzerinden silinir.

### Bilinen sınırlar
- Liste sayfaları sayfalamasız en fazla 200 kayıt gösterir.
- Özellikler serbest anahtar–değer; şablonla doğrulama A4'te.
- Silme kalıcıdır (çöp kutusu yok); onay penceresi ve nonce ile korunur.
- Özel yetki yerine `manage_options` kullanılır.

## [0.3.0] - 2026-09-27

### Eklendi
- **U1 AI uyum taraması ve puan** (`compliance_scan` anahtarı, varsayılan kapalı).
  - Yedi kontrol, PRD ağırlıklarıyla (toplam 100): yapılandırılmış veri 20, içerik okunabilirliği 20,
    makine arayüzü 20, AI bot erişimi 15, llms.txt 10, tazelik 10, ileri standartlar 5.
  - Dayanılan standartlar: Google yapılandırılmış veri kuralları (Product, Offer, LocalBusiness,
    Organization), RFC 9309 (robots.txt), WordPress REST keşfi, WordPress MCP Adapter varsayılan uç noktası,
    llmstxt.org (zorunlu H1), A2A 1.0.0 Agent Card (`/.well-known/agent-card.json`, §4.4.1).
  - Puan = 100 × Σ(ağırlık × oran) / Σ(ölçülen ağırlık); erişilemeyen kontroller "ölçülemedi" olur ve
    puandan düşülmez. `score_version` (1) her rapora yazılır.
  - En fazla 10 örnek sayfa (ana sayfa, menü bağlantıları, site haritası), istek başına 5 sn, toplam 60 sn.
  - Araçlar → AI Uyum: "Şimdi tara", puan, en çok puan kazandıracak eksik en üstte, önceki taramayla
    karşılaştırma, geçmiş. WP-CLI: `wp aihs scan [--format=table|json]`.
  - Son 20 tarama `aihs_scans` seçeneğinde (otomatik yüklenmez; silme seçeneği açıksa kaldırmada silinir).
- Çekirdek: `PageFetcher` arayüzü (durum kodu ve başlıklarla HTTP), `Site`, `Html`, `Robots`, `Scanner`.

### Değişti
- A0 ölçümü, taramanın kendi isteklerini saymaz: tarayıcı site sırrıyla imzalı `X-AIHS-Scan` başlığı gönderir.

### Bilinen sınırlar
- Tarama sitenin kendine istek atmasına (loopback) dayanır; bunu engelleyen sunucularda kontroller
  "ölçülemedi" görünür ve sayfada uyarı çıkar. Yerel wp-env'de de loopback çalışmaz.
- JavaScript çalıştırılmaz; yalnızca JavaScript ile oluşan içerik ve WebMCP görülmez.
- MCP Server Card (`/.well-known/mcp/server-cards.json`) taslak olduğu için yalnızca bilgi olarak gösterilir.
- Tarama yönetim sayfasında eşzamanlı çalışır (en fazla ~60 sn).

## [0.2.1] - 2026-09-27

Kullanıcı açısından değişiklik yok; iç yapı çoklu platforma hazırlandı
([ADR-001](docs/mimari/ADR-001-coklu-platform.md)).

### Değişti
- Çekirdek (`src/Core`) platformdan bağımsız: WordPress'e yalnızca `src/Core/Contracts` arayüzleri
  (`Settings`, `Cache`, `HttpClient`, `Clock`, `Secret`, `HitRepository`) üzerinden erişir.
- WordPress'e özgü kod `src/WordPress` altına taşındı (`Plugin`, `Lifecycle`, `Uninstaller`,
  `Requirements`, geçişler, yönetim sayfası, WP-CLI). `HitStore` → `WpdbHitRepository`.
- Ölçüm sayacı artık `$_SERVER` okumaz; WordPress adaptörü bir `Request` nesnesi üretir ve yönetici
  paneli / cron / AJAX filtresini uygular.

### Eklendi
- `CoreIsPlatformNeutralTest`: çekirdeğe platform kodu girerse test kırılır.
- WordPress'siz uçtan uca ölçüm testi (bellek içi adaptörlerle) ve 0.2.0'dan güncelleme testi.

### Geriye uyumluluk
Seçenek adları, `{prefix}aihs_hits` tablosu, cron kancaları, yönetim sayfası adresi, CSV biçimi ve
WP-CLI komutu aynen korunur; veritabanı geçişi yoktur.

## [0.2.0] - 2026-09-27

### Eklendi
- **A0 ölçümü** (`measurement` anahtarı): AI botlarının ziyaretleri ve AI platformlarından gelen insan ziyaretleri sayılır.
  - Bot listesi `data/ai-bots.json` (12 bot; UA ve doğrulama kaynağı sağlayıcıların resmi belgelerinden, 2026-09-27),
    yönlendirme listesi `data/ai-referrers.json` (5 platform). Referer yoksa `utm_source` da eşleşir.
  - Sayaç ön yüz, REST, `robots.txt` ve WordPress'e düşen `llms.txt` isteklerinde çalışır; yönetici paneli,
    AJAX ve cron istekleri sayılmaz. İstek başına en fazla bir veritabanı yazması.
  - Doğrulama: yayınlanmış IP listeleri günlük cron ile indirilir (`aihs_ip_ranges`); rDNS sonucu IP'nin tuzlu
    hash'i anahtarıyla 24 saat önbellekte. Doğrulanamayan ziyaret `verified = 0` ile yine sayılır.
  - Araçlar → AI Ölçüm: son 7 / 28 gün, bota göre ziyaret, en çok okunan 10 sayfa, yönlendirmeler, CSV dışa
    aktarma ve ölçümü aç/kapat.
  - WP-CLI: `wp aihs hits report --days=28 --format=table|csv`.
  - 400 günden eski kayıtlar haftalık cron ile silinir.
- Geçiş `Migration_0_2_0`: `{prefix}aihs_hits` tablosu.
- Eklenti güncellendiğinde bekleyen geçişler otomatik çalışır (etkinleştirme kancası güncellemede çalışmaz).
- `Features::set()` ve `Module::deactivate()` (devre dışı bırakınca cron görevleri silinir).

### Neden `measurement` varsayılan AÇIK?
Tüm özellikler varsayılan kapalı gelir; bu tek istisnadır. A0'ın amacı eklenti kurulduğu andan itibaren
"önceki" durumu ölçmektir; kapalı gelseydi temel (baseline) veri kaybolurdu. Ölçüm yalnızca toplu sayaç
tutar: ham IP, tam user-agent, sorgu dizesi veya başka kişisel veri saklanmaz. Araçlar → AI Ölçüm
sayfasından tek tıkla kapatılabilir; kapatılınca sayaç tamamen durur.

### Değişti
- `aihs_db_version` artık her istekte okunduğu için otomatik yüklenir.
- Varsayılan-kapalı testi, onaylı istisnaları (`Features::DEFAULT_ON`) birebir denetleyecek şekilde sıkılaştırıldı.

### Bilinen sınırlar
- Sayfa önbelleğinden sunulan istekler ve sunucuda gerçek dosya olarak duran `llms.txt` sayılamaz; sonuçlar alt sınırdır.
- Doğrulama `REMOTE_ADDR` kullanır; CDN veya ters vekil arkasındaki sitelerde botlar `verified = 0` görünür.
- Bytespider ve meta-externalagent için resmi doğrulama yöntemi yok (`verify: none`); Bytespider'ın UA
  bilgisi yalnızca üçüncü taraf kaynaklardan doğrulanabildi.
- Amazonbot IP listesi bir HTML sayfasının içinden ayıklanır; sayfa yapısı değişirse doğrulama 0'a düşer.
- `aihs_hits` benzersiz anahtarı InnoDB DYNAMIC satır biçimi gerektirir (MySQL 5.7+ / MariaDB 10.2+).
- rDNS önbelleği ıskalandığında o istekte hit yazmasına ek olarak bir önbellek yazması olur (başlangıç listesinde rDNS kullanan bot yok).

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
