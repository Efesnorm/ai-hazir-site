# Görev 01 – A0: AI bot ve AI yönlendirme ölçümü (sürüm 0.2.0)

## Amaç
Eklenti kurulduğu andan itibaren sitenin "önce" durumunu ölçmek: hangi AI botları geliyor,
hangi sayfaları okuyor, AI platformlarından kaç insan tıklayıp geliyor.
Bu veri, sonraki artımların etkisini göstermek için temel (baseline) olacak.

## Kapsam
1. **Bot listesi** `data/ai-bots.json` (kodda sabit değil, düzenlenebilir veri). Her kayıt:
   `id`, `name`, `operator`, `ua_pattern` (büyük/küçük harf duyarsız), `category`
   (`training` | `search` | `user_agent`), `verify` (`none` | `ip_ranges` | `rdns`), `verify_source`.
   Başlangıç kayıtları: GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User,
   Claude-SearchBot, PerplexityBot, Perplexity-User, CCBot, Bytespider, Amazonbot,
   meta-externalagent. Yayından önce her birinin UA değeri ve doğrulama kaynağı sağlayıcının
   resmi belgesinden kontrol edilecek; emin olunamayanlar `verify: none` kalır.
2. **Yönlendirme listesi** `data/ai-referrers.json`: chatgpt.com, perplexity.ai, claude.ai,
   gemini.google.com, copilot.microsoft.com (alan adı + ad).
3. **Geçiş** `Migration_0_2_0`: tablo `{prefix}aihs_hits`
   (`day` DATE, `kind` [`bot`|`referral`], `source_id`, `path` (en fazla 191 karakter),
   `verified` TINYINT, `hits` INT). Benzersiz anahtar: `day, kind, source_id, path, verified`.
   Kayıt tek sorguyla artırılır: `INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1`.
4. **Sayaç** `src/Modules/Measurement/Tracker.php`: ön yüz, REST ve `robots.txt` / `llms.txt`
   isteklerinde çalışır; yönetici paneli ve cron isteklerini saymaz. Ham IP, tam UA ve sorgu
   dizesi saklanmaz. İstek başına en fazla bir veritabanı yazması.
5. **Doğrulama** `Verifier.php`: `ip_ranges` için yayınlanmış IP listeleri günlük cron ile
   indirilip önbelleğe alınır; `rdns` sonucu IP'nin tuzlu hash'i anahtarıyla 24 saat önbellekte
   tutulur. Doğrulama başarısızsa kayıt `verified = 0` ile yine sayılır.
6. **Yönetim sayfası** (Araçlar → AI Ölçüm): son 7 ve 28 gün; bota göre ziyaret, en çok okunan
   10 sayfa, yönlendirme kaynaklarına göre insan ziyareti, CSV dışa aktarma.
   Sayfada şu uyarı görünür: "Sayfa önbelleği kullanan sitelerde önbellekten sunulan istekler
   sayılamaz; sonuçlar alt sınırdır."
7. **WP-CLI**: `wp aihs hits report --days=28 --format=table|csv`.
8. **Özellik anahtarı** `measurement`. Bu modül için varsayılan AÇIK (tek istisna; gerekçesi
   CHANGELOG'da yazılı). Kapatılınca sayaç tamamen durur.
9. **Veri saklama**: 400 günden eski satırlar haftalık cron ile silinir.

## Kapsam dışı
Haftalık 10 soruluk AI testi (elle yapılır), sunucu log analizi, grafikler, uyum puanı (U1).

## Kabul testleri
- [ ] Her örnek UA doğru `source_id` ve `category` ile eşleşiyor; normal tarayıcı UA'sı eşleşmiyor (birim).
- [ ] Aynı gün aynı bot aynı sayfa iki kez → tek satır, `hits = 2` (entegrasyon).
- [ ] Yönetici paneli ve cron istekleri sayılmıyor (entegrasyon).
- [ ] Veritabanında ham IP veya tam UA bulunmuyor (entegrasyon).
- [ ] Doğrulama kaynağına erişilemezse sayfa yüklemesi bozulmuyor, kayıt `verified = 0` (birim).
- [ ] Anahtar kapalıyken hiçbir yazma yapılmıyor (entegrasyon).
- [ ] `down()` tabloyu kaldırıyor, `up()` yeniden kuruyor; görev 00 testleri hâlâ geçiyor.
- [ ] Sayaç, istek başına ortalama 5 ms'den fazla ek süre getirmiyor (basit ölçüm testi).
- [ ] CSV dışa aktarma yönetim sayfasındaki sayılarla birebir aynı.

## Çalışma şekli
Önce plan ve dosya ağacı, onayımı bekle. Bot listesindeki her kaydın kaynağını plan içinde
göster; doğrulayamadıklarını açıkça işaretle.
