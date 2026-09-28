=== AI Hazır Site ===
Contributors: osmanefeeren
Tags: ai, llms-txt, schema, mcp, robots-txt
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sattığınızı, aradığınızı ve tedarik edebildiğinizi AI agentların okuyabileceği biçimde yayınlar; AI uyum puanı, rapor ve rozet verir.

== Description ==

AI Hazır Site, firmanızın "ne satıyorum, ne arıyorum, ne tedarik edebilirim" bilgisini ChatGPT, Claude, Gemini ve Perplexity gibi AI asistanlarının ve agentların doğru okuyabileceği biçimde yayınlar ve sitenizin bu asistanlara ne kadar hazır olduğunu ölçer.

Her özellik ayrı bir anahtarla açılır ve kapatılır. Kurulumdan sonra yalnızca AI ölçümü açıktır; geri kalan her şeyi siz açarsınız.

= AI Agent Uyum Paketi =

* **AI ölçümü:** hangi AI botlarının geldiğini, en çok okudukları sayfaları ve AI platformlarından gelen ziyaretleri gösterir. IP adresi ve tarayıcı bilgisi saklanmaz.
* **AI uyum taraması:** siteyi yedi kritere göre 100 üzerinden puanlar; her eksik için puana etkisini ve nasıl düzeltileceğini söyler.
* **AI uyum sihirbazı:** eksikleri adım adım tamamlatır; her adım tek tıkla geri alınır.
* **AI bot erişimi:** her AI botuna robots.txt üzerinden izin verir ya da engeller; diğer satırlara dokunmaz.
* **AI uyum raporu:** puan, kontrol bazında durum, ilk ve son tarama karşılaştırması, son 28 günün AI ölçüm özeti; PDF olarak indirilir.
* **AI Hazır rozeti:** puan eşiği (varsayılan 70) aşılınca kısa kod veya blokla gösterilen rozet; sitenin kendi doğrulama sayfasına bağlanır.

= AI Katalog =

* **İlanlar ve firma profili:** satılan, aranan ve tedarik edilebilen ürün ve hizmetler; geçerlilik süresiyle.
* **Sektör şablonları:** genel, ürün, ihracat ürünü, hizmet ve tur şablonları; yenileri JSON dosyasıyla eklenir.
* **Schema.org yapılandırılmış veri:** firma için Organization, ilanlar için Product, Service veya TouristTrip; yayından önce doğrulanır.
* **llms.txt ve /ai-katalog/ sayfası:** AI'ların hızlı okuyacağı sade metin ve HTML.
* **REST API:** salt okunur `/wp-json/aihs/v1/` uç noktaları, önbellek başlıkları ve hız sınırı.
* **MCP sunucusu ve Abilities API:** AI asistanları kataloğu doğrudan sorgular ("Stokta var mı, kaç günde gelir?").
* **Çoklu dil:** yalnızca girdiğiniz çeviriler yayınlanır; AI'lar `lang` ya da `Accept-Language` ile dili seçer, eksik çeviri işaretlenir.
* **Teklif kutusu:** AI agentlar ve insanlar firmaya talep bırakır. Hiçbir talep otomatik onaylanmaz, talep sahibine otomatik yanıt gitmez; iletişim bilgisi şifreli saklanır ve süre dolunca silinir.

= Gizlilik =

Eklenti ham IP adresi, tarayıcı bilgisi veya sorgu dizesi saklamaz. Teklif kutusu açıksa talep sahibinin iletişim bilgileri şifreli olarak ve yalnızca saklama süresi boyunca (varsayılan 180 gün) tutulur. Eklenti kendi başına hiçbir dış hizmete veri göndermez; yalnızca AI botlarının resmi IP listelerini yayımlandıkları adreslerden indirir.

== Installation ==

1. Eklentiler → Yeni Ekle → Eklenti Yükle ile `ai-hazir-site.zip` dosyasını yükleyin ve etkinleştirin.
2. Araçlar → AI Ölçüm sayfasında AI bot ziyaretlerini izleyin.
3. Araçlar → AI Uyum sayfasında taramayı açıp sitenizin puanını görün; AI Uyum Sihirbazı eksikleri tamamlatır.
4. Diğer özellikleri ihtiyacınıza göre açın. Ayrıntılı kullanım belgeleri eklentinin kaynak deposundaki `docs/kullanim/` klasöründedir.

Güncellemeden önce sitenizin ve veritabanınızın yedeğini alın.

== Frequently Asked Questions ==

= Rozet neden görünmüyor? =

Rozet yalnızca AI uyum raporu açıkken ve son taramanın puanı eşiğin (varsayılan 70) üstündeyse görünür. Eşik altında kısa kod ve blok hiçbir şey göstermez. Eşik `aihs_badge_threshold` süzgeciyle değiştirilebilir.

= Yerel geliştirme ortamında tarama "ölçülemedi" diyor. =

Tarama sitenin kendi adreslerini çeker. Kendine HTTP isteği atamayan ortamlarda (ör. bazı yerel Docker kurulumları) kontroller ölçülemez; canlı sitede sorun olmaz.

= Eklentiyi silersem verilerim ne olur? =

Varsayılan olarak veriler korunur. Eklentiyi silerken tüm verisinin (tablolar, ayarlar, ilanlar, talepler) kaldırılmasını istiyorsanız silmeden önce `aihs_delete_data_on_uninstall` seçeneğini açın, ör. WP-CLI ile: `wp option update aihs_delete_data_on_uninstall 1`.

== Changelog ==

= 1.1.0 =
* Çoklu dil: ilan ve profil çevirileri; REST, MCP, llms.txt ve AI katalog sayfası istenen dilde. Otomatik çeviri yok; eksik çeviri varsayılan dilde gösterilir ve işaretlenir. Polylang ve WPML'in dil listesi kullanılır.

= 1.0.0 =
* AI uyum raporu (PDF), AI Hazır rozeti (kısa kod ve blok) ve doğrulama sayfası.
* MVP sürümü: tüm özellik anahtarlarının varsayılanları son kez denetlendi; kullanıcı belgeleri ve bilinen sınırlar listesi.

= 0.12.0 =
* Teklif kutusu ve güvenlik katmanı.

= 0.11.0 =
* Abilities API ve MCP sunucusu.

Tüm değişiklikler: CHANGELOG.md.

== Upgrade Notice ==

= 1.1.0 =
Çoklu dil desteği eklendi (varsayılan kapalı). Tek dilli sitelerde hiçbir çıktı değişmez.

= 1.0.0 =
İlk kararlı sürüm. Güncellemeden önce yedek alın. Mevcut veriler ve ayarlar korunur; yeni özellikler kapalı gelir.
