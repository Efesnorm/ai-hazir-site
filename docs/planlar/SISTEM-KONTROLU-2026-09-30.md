# Sistem kontrolü – 2026-09-30 gecesi

Kullanıcı isteği: "Sistemin çalışıp çalışmadığını kontrol edecek testlere devam; bulduğun sorunları not et."
Kod: `litespeed-sunucu-1.14.0` dalı (1.14.0, 2026-09-30 onaylandı ve birleştirildi).

## Sonuçlar

| # | Kontrol | Sonuç |
| --- | --- | --- |
| 1 | **Yükseltme 1.0.0 → 1.14.0** (temiz WordPress, PHP 8.3; 1.0.0 paketiyle kurulum + veri, sonra 1.14.0 paketiyle "yüklenenle değiştir") | ✅ Sürüm 1.14.0, şema aynı, açık özellikler, profil ve 3 ilan korundu; llms.txt birebir aynı; adres kuralları yerinde; PHP hatası yok |
| 2 | **Önerilen kurulum (ürün)** yönetici olarak önizleme + uygula | ✅ Önizleme 5 özellik, uygulamadan sonra 16 özellik açık; hiçbiri kapanmadı |
| 3 | **Herkese açık kanallar** (gerçek HTTP) | ✅ Keşif `<link>` + `Link` başlığı; llms.txt, /ai-katalog/ (JSON-LD), REST, A2A kartı, WordPress site haritası (`wp-sitemap-aihs-1.xml`), `/ai-katalog-sitemap.xml`, robots.txt, doğrulama sayfası: 200 |
| 4 | **A2A ve MCP mantığı** | ✅ 1000 m → "evet", 6000 m (stok 5000) → "hayır" |
| 5 | **14 yönetim ekranı** (Ayarlar, önizleme, Entegrasyonlar, Ölçüm, Uyum, Bot erişimi, Rapor, Katalog ×4, Teklif kutusu, Eklentiler, Başlangıç) | ✅ Hepsi 200, PHP hatası yok |
| 6 | **Kaldırma** (verileri de sil açık) | ⚠️ **Sorun bulundu ve düzeltildi**: kaldırmadan sonra zamanlanmış IndexNow bildirimi (`aihs_indexnow_submit`) kalıyordu. Neden: kaldırma ilanları silerken `deleted_post` IndexNow'u tetikliyordu. Düzeltme: kaldırmanın sonunda eklentinin zamanlanmış görevlerinin hepsi silinir (`Uninstaller::cron_hooks()`). Test: `UninstallCronTest`. Diğer her şey temiz: seçenekler, 3 tablo, ilanlar, meta, adres kuralları (1.12.1 düzeltmesi canlıda doğrulandı) |
| 7 | **LiteSpeed sunucu önbelleği** yönetim ekranından aç/kapat (yerel Apache) | ✅ Kural WordPress bloğunun başına yazıldı, site 200, kapatınca silindi. Not: WP-CLI'den açınca `.htaccess` yazılmıyor (WordPress'in kendi kuralı; ekrandan açılmalı) |
| 8 | **A2A iki site demosu** (1.14.0 koduyla, `tools/a2a-demo`) | ✅ Eşleşme puan 100 → önizleme → onay → fabrikanın teklif kutusunda "yeni" |
| 9 | **Canlı siteler** (yalnızca okuma, az istek) | ✅ makedonya.tr: tüm kanallar, IndexNow anahtar dosyası, keşif, site haritası satırı. ✅ voltkab.com: önce dönemi (llms.txt 404, beklenen), GPTBot güncel sayfa. ⚠️ intekarglobal.com: GPTBot'a sayfa LiteSpeed sunucu önbelleğinden (`hit`) → 1.14.0 ile çözülecek |
| 10 | **WordPress 6.9 + PHP 8.3** | ✅ 370 birim, 218 entegrasyon |
| 11 | **WordPress 7.1.2 + PHP 8.4** | ✅ 370 birim, 218 entegrasyon; eskimiş kullanım uyarısı yok |
| 12 | **Güvenlik yoklaması** | ✅ Bozuk JSON standart hatayla reddediliyor; aşırı `per_page` 400; arama parametresinde özel karakter sorunsuz; talepler dışarıdan okunamıyor (`/inquiries` yalnızca POST); iletişim e-postası veritabanında şifreli (düz metin yok); yönetim ekranında `<script>` ve `<img onerror>` kaçışlanmış (XSS yok) |
| 13 | **Performans** (sunucu içi PHP süresi ve sorgu sayısı, dönüşümlü ölçüm) | ⚠️ Ana sayfa: eklentiyle +11–13 sorgu (3 ilan). **153 ilanla ana sayfa 334, llms.txt 314 sorgu** (eklentisiz 23 / 17): ilan başına ayrı yazı + meta sorgusu (N+1). Ayrıntı aşağıda |
| 14 | **Kurulum paketi içeriği** (1.14.0 zip) | ✅ Eklentinin test/araç/belge/geliştirme dosyaları yok; geliştirme bağımlılıkları (PHPUnit, PHPStan, PHPCS) yok; çeviri şablonu var; readme.txt başlığı WordPress standardına uygun |

## Bulunan sorunlar ve durumları

| Sorun | Önem | Durum |
| --- | --- | --- |
| Kaldırmadan sonra IndexNow zamanlanmış görevi kalıyor | Düşük (görev bir kez çalışıp kendiliğinden kaybolur, ama iz) | Düzeltildi, 1.14.0 dalında |
| WP-CLI'den `litespeed_server_bypass` açılınca `.htaccess` yazılmaz | Bilgi | Belgelendi (CHANGELOG, bilinen sınırlar) |
| intekarglobal.com: barındırma sunucu önbelleği bot yanıtını saklayıp sonraki botlara veriyor | Orta (ölçüm eksik) | 1.14.0 + barındırma talebi |
| **İlan sayısıyla doğrusal sorgu (N+1)**: ana sayfa JSON-LD'si yalnızca "son güncelleme" tarihi için tüm ilanları tek tek yüklüyor (`SchemaModule::home_document()` → `listings()` → `WpListingRepository::all()` → ilan başına `find()`); llms.txt de girdi özeti için aynısını yapıyor. 153 ilanda ana sayfa 334, llms.txt 314 sorgu | **Yüksek** (ölçeklenme; "AI botlarına önbellekten sayfa sunma" açıkken her bot isteği bu maliyeti öder) | **1.14.1 ile düzeltildi**: ilanlar `WP_Query` önbellek doldurmasıyla toplu okunuyor. 152 ilanda eklentinin sorguları: ana sayfa JSON-LD 312 → 16, llms.txt 311 → 14, /ai-katalog/ 311 → 14 (`ListingQueryCountTest`) |
| Teklif mesajı veritabanına HTML'iyle olduğu gibi kaydediliyor (çıktıda kaçışlandığı için bugün XSS yok) | Düşük | **1.14.2 ile düzeltildi**: e-postada etiketler temizleniyor, başlık `text/plain` (`InquiryNotifierPlainTextTest`) |
| A2A'ya 2 MB'lık gövde kabul ediliyor (JSON-RPC hatasıyla yanıtlanıyor) | Düşük | **1.14.2 ile düzeltildi**: 64 KB üstü 413 (`A2ABodyLimitTest`). MCP uç noktası da 1.14.3 ile (`McpBodyLimitTest`) |
| Eklenti kökünde lisans dosyası (`LICENSE`) yok; lisans yalnızca eklenti başlığında ve readme.txt'de (GPLv2 or later) | Düşük | **1.14.2 ile düzeltildi**: `LICENSE` (GPL-2.0 resmi metni), pakette de var |
| 28 Eylül makedonya.tr yardımcı dosyası (`aihs-deneme-ozellikleri-ac.php`) yalnızca WP-CLI'de denenmişti; web kapsayıcısında denenmemişti | Bilgi | Canlıda sorunsuz çalıştı; artık gerek yok (1.9.0 Ayarlar ekranı) |

## Ölçüm yöntemi (performans)
Docker'da çalışan yerel sitede, yalnızca ölçüm için geçici bir zorunlu eklenti: `X-AIHS-Off` başlıklı isteklerde AI Hazır
Site yüklenmez; her istekte sunucu içi PHP süresi ve sorgu sayısı sayfa sonuna yazılır (`SAVEQUERIES`). Eklentili ve
eklentisiz istekler dönüşümlü, 7–15'er kez; medyan. Mutlak süreler Docker'ın Windows disk yavaşlığıyla şişik (tek istek
2–3 sn); karşılaştırma için sorgu sayısı esas alındı. Ölçüm düzeni ve 150 test ilanı sonra silindi.
