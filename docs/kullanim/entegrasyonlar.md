# Entegrasyonlar (1.8.0)

**Araçlar → AI Hazır Entegrasyonlar**: sitenizdeki diğer eklentilerle yapılabilecek işler. Hiçbiri kendiliğinden
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

## AI Katalog'u site haritasına ekle (`catalog_sitemap`)

Arama motorlarından okuyan AI agentlar ilanlarınızı ancak `/ai-katalog/` dizine girince görür. Açınca sayfa (portal
modunda işletme sayfaları da) son değişiklik tarihiyle sitenizin site haritasına eklenir:

- **Rank Math / Yoast SEO**: site haritası dizinine `/ai-katalog-sitemap.xml` satırı eklenir.
- **WordPress'in kendi site haritası**: `wp-sitemap-aihs-1.xml`.

Açıp kapatınca Rank Math ve Yoast'un site haritası önbelleği temizlenir.
