# Entegrasyonlar (1.8.0)

**AI Hazır Site → Entegrasyonlar**: sitenizdeki diğer eklentilerle yapılabilecek işler. Hiçbiri kendiliğinden
yapılmaz; her biri bu ekrandan **Aç / Kapat** ile yönetilir ve kapatınca eklediklerimiz geri alınır. Eklenti devre
dışı bırakılınca önbellek eklentinizdeki satırlarımız ve `.htaccess` kuralımız silinir; ayarlar açık kalır ve eklenti yeniden
etkinleştirilince kendiliğinden yeniden yazılır (1.19.1).

## AI botlarına önbellekten sayfa sunma (`bot_cache_bypass`)

Sayfa önbelleği AI botlarına kayıtlı kopyayı sunar: botlar eski içeriği görür, AI Ölçüm bu ziyaretleri sayamaz.
Açınca AI bot listemizdeki adlar (GPTBot, ChatGPT-User, ClaudeBot…) önbellek eklentinizin "bu tarayıcılara önbellek
sunma" listesine eklenir. İnsan ziyaretçiler için hiçbir şey değişmez.

| Eklenti | Nasıl |
| --- | --- |
| WP Rocket | "Never Cache User Agent(s)" listesine `rocket_cache_reject_ua` filtresiyle eklenir; WP Rocket'ın .htaccess ve yapılandırma dosyası yeniden üretilir. WP Rocket ekranındaki kendi satırlarınız değişmez. |
| LiteSpeed Cache (7.2+) | "Do Not Cache User Agents" ayarına LiteSpeed'in API'siyle eklenir. Yalnızca bizim eklediğimiz satırlar kapatınca çıkarılır. |
| W3 Total Cache, WP Super Cache | Ekranda algılanır; bot adlarını "Rejected User Agents" alanına elle ekleyin. |

Bedeli: botlar önbelleksiz sayfa alır, sunucu yükü biraz artar. Barındırma firmanızın hız sınırı varsa botlar ona
daha sık takılabilir.

## LiteSpeed sunucu önbelleği (`litespeed_server_bypass`, 1.14.0)

Bazı barındırmalar LiteSpeed sunucusunda, LiteSpeed Cache eklentisi olmadan da sayfaları önbelleğe alır (yanıt
başlığında `X-LiteSpeed-Cache: hit`). O zaman AI botlarının istekleri WordPress'e hiç ulaşmaz. Bu satır yalnızca sunucu
LiteSpeed ise ve LiteSpeed Cache eklentisi yoksa görünür.

- Açınca `.htaccess`'in WordPress bölümünün başına AI botları için "önbellek kullanma" kuralı yazılır ve bot yanıtlarına
  "saklama" başlıkları konur. İnsan ziyaretçiler için önbellek aynen çalışır.
- Açmadan önce `.htaccess` yedeği alın. Dosya yazılamazsa ekranda elle eklenecek satırlar görünür.
- Doğrulama: GPTBot kimliğiyle arka arkaya iki istek ikisi de `X-LiteSpeed-Cache: hit` **olmamalı**. Önde başka bir
  önbellek katmanı hâlâ `hit` veriyorsa barındırma firmasından AI botları için istisna isteyin.

## AI Katalog'u site haritasına ekle (`catalog_sitemap`)

Arama motorlarından okuyan AI agentlar ilanlarınızı ancak `/ai-katalog/` dizine girince görür. Açınca sayfa (portal
modunda işletme sayfaları da) son değişiklik tarihiyle sitenizin site haritasına eklenir:

- **Rank Math / Yoast SEO**: site haritası dizinine `/ai-katalog-sitemap.xml` satırı eklenir.
- **WordPress'in kendi site haritası**: `wp-sitemap-aihs-1.xml`.

Açıp kapatınca Rank Math ve Yoast'un site haritası önbelleği temizlenir.

## Değişiklikleri IndexNow ile bildir (`indexnow`, 1.10.0)

İlan, profil ya da işletme değişince `/ai-katalog/` adresi IndexNow ile Bing, Yandex, Naver, Seznam ve Yep'e
bildirilir. ChatGPT arama ve Copilot büyük ölçüde Bing dizinine dayanır. Google IndexNow kullanmaz (Google için site
haritası).

- Önkoşul: Schema.org yapılandırılmış veri ya da llms.txt açık (katalog sayfası yayında).
- Değişiklikten 10 dakika sonra tek bildirim gider; iki bildirim arası en az 1 saattir. **Şimdi bildir** düğmesi de var.
- Gönderilen: site adı, anahtar ve herkese açık katalog adresleri. Kişisel veri gönderilmez.
- Anahtar dosyası `/{anahtar}.txt` otomatik yayınlanır; ekranda bağlantısı görünür. Rank Math'in "Instant Indexing"
  modülü açıksa ikisi birlikte çalışır (her istekte anahtar dosyasının yeri verilir).
- Son bildirimin yanıtı ekranda Türkçe açıklanır. **403** görürseniz arama motoru anahtar dosyasına ulaşamamıştır:
  barındırma firmanızın bot korumasının `/{anahtar}.txt` adresini engelleyip engellemediğini kontrol edin.

## Güvenlik yazılımları (`security_integrations`, 1.21.0)

Güvenlik katmanları AI botlarını ve portal ağımızın sunucudan sunucuya isteklerini yavaşlatabilir ya da engelleyebilir.
Bu bölüm yalnızca yazılımların belgelediği yolları kullanır; hiçbir işlem kendiliğinden yapılmaz.

| Yazılım | Ne görürsünüz | Tek tıkla işlem |
| --- | --- | --- |
| **Imunify Security** (AI Bot Management) | Hazır ayarların AI botlarına dakikada kaç istek verdiği (Balanced: doğrulanmış AI tarayıcısına 10, Strict: 3, Monitor: sınırsız); `wp-config.php`'de sabitlenmiş ayar | **Balanced'ı sabitle**: belgelenmiş `IMUNIFY_AI_BOT_PROTECTION_PRESET` sabiti `wp-config.php`'ye "balanced" olarak yazılır (kimse yanlışlıkla Strict'e çekemez). "Monitor" ya da "kapalı" asla yazılmaz. Sabit sitede zaten tanımlıysa dosyaya dokunulmaz. |
| **Wordfence** | Portal ağındaki doğrulanmış sitelerin sunucu adresleri | **Ağ üyelerinin sunucularını izin listesine ekle** (Wordfence'in herkese açık `wordfence::whitelistIP()` işlevi). AI botlarının IP'leri eklenmez: izin listesi bütün güvenlik kurallarını atlatır. Wordfence'te silme için herkese açık işlev olmadığından eklenenler listelenir; gerekirse Wordfence ekranından elle silinir. |
| **Cloudflare** | Sitenin Cloudflare arkasında olduğu | Yok (panelde "AI botlarını engelle", AI Crawl Control ve Bot Fight Mode'u kontrol edin) |
| Solid Security, All-In-One Security, NinjaFirewall, Sucuri | Kurulu olduğu | Yok (kötü bot listelerini ve hız sınırlarını kontrol edin) |

**Barındırma firmasına iletilecek metin:** sunucu düzeyindeki Imunify360, WAF ve IP listeleri yalnızca barındırma firmasında
değiştirilebilir. Ekran, AI tarayıcılarının resmi IP listesi adreslerini ve ağ üyelerinin sunucu adreslerini içeren hazır
bir metin verir.

**wp-config.php nasıl yazılır:** yalnızca `# BEGIN AI Hazir Site` / `# END AI Hazir Site` arasındaki kendi bloğumuz,
`<?php` satırının hemen altına; sonuç PHP olarak denetlenir, dosya geçici dosya + taşıma ile tek seferde değişir, kopya
bırakılmaz. Dosya doğrudan yazılamıyorsa eklenecek satır gösterilir. Anahtar kapatılınca ve eklenti silinince blok kaldırılır.
