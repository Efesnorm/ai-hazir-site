# Sistem kontrolü – 2026-09-30 gecesi

Kullanıcı isteği: "Sistemin çalışıp çalışmadığını kontrol edecek testlere devam; bulduğun sorunları not et."
Kod: `litespeed-sunucu-1.14.0` dalı (1.14.0, birleştirme onayı bekliyor).

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

## Bulunan sorunlar ve durumları

| Sorun | Önem | Durum |
| --- | --- | --- |
| Kaldırmadan sonra IndexNow zamanlanmış görevi kalıyor | Düşük (görev bir kez çalışıp kendiliğinden kaybolur, ama iz) | Düzeltildi, 1.14.0 dalında |
| WP-CLI'den `litespeed_server_bypass` açılınca `.htaccess` yazılmaz | Bilgi | Belgelendi (CHANGELOG, bilinen sınırlar) |
| intekarglobal.com: barındırma sunucu önbelleği bot yanıtını saklayıp sonraki botlara veriyor | Orta (ölçüm eksik) | 1.14.0 + barındırma talebi |
