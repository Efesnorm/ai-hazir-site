# Entegrasyonlar (1.8.0)

**AI Hazır Site → Entegrasyonlar**: sitenizdeki diğer eklentilerle yapılabilecek işler. Hiçbiri kendiliğinden
yapılmaz; her biri bu ekrandan **Aç / Kapat** ile yönetilir ve kapatınca eklediklerimiz geri alınır. Eklenti devre
dışı bırakılınca önbellek entegrasyonu kapanır ve önbellek eklentinizdeki satırlarımız silinir.

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
