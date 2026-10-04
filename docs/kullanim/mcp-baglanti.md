# Claude'u AI Katalog'a MCP ile bağlama

AI Hazır Site, firmanızın profilini ve geçerli ilanlarını **MCP (Model Context Protocol)** sunucusu
olarak yayınlayabilir. Claude gibi AI asistanları bu sunucuya bağlanıp kataloğunuzu doğrudan
sorgular: "Stokta 3x2,5 kablo var mı, kaç günde gelir?" gibi soruları ilanlarınızdaki bilgiyle cevaplar.

Katalog araçları **salt okunurdur**. Teklif kutusu açıksa (bkz. aşağıda) tek yazma aracı, firmaya talep
bırakmaktır; talepler otomatik onaylanmaz ve talep sahibine otomatik yanıt gitmez.

## 1. Özelliği açın

1. **AI Hazır Site → Firma Profili**'ni doldurun ve en az bir ilan ekleyin.
2. Özellik anahtarlarını açın (ikisi de gerekir):
   - `abilities` – katalog yetenekleri (WordPress Abilities API),
   - `mcp` – MCP sunucusu.

   WP-CLI ile:

   ```bash
   wp eval 'AIHazirSite\Core\Features::set("abilities", true); AIHazirSite\Core\Features::set("mcp", true);'
   ```

Sunucu adresi: `https://siteniz.com/wp-json/aihs/mcp`

Sunucudaki araçlar:

| Araç | Ne yapar |
| --- | --- |
| `aihs-get-profile` | Firma adı, sektör, ülke, diller, sertifikalar, kurumsal iletişim |
| `aihs-search-listings` | Geçerli ilanları arar (tür, kategori, bölge, anahtar kelime, şablon alanları, faaliyet alanı `sector`; kardeş portal önerisi açıksa `network`) |
| `aihs-get-listing` | Tek ilanın ayrıntıları |
| `aihs-check-availability` | "Bu miktar var mı, istenen günde gelir mi?" → yes / no / unknown ve gerekçe |
| `aihs-submit-inquiry` | *(teklif kutusu açıksa)* Teklif isteği, teklif veya iletişim talebi bırakır |
| `aihs-request-referral` | *(hukuk gibi ücretsiz bilgi verilen hizmet sitelerinde, yukarıdakinin yerine)* Yönlendirme talebi; ücret dışında her konu |

### Teklif kutusu (isteğe bağlı)

**AI Hazır Site → Teklif Kutusu** ekranındaki uyarıyı okuyup
kutuyu açtığınızda, AI asistanları firmanıza talep bırakabilir. İletişim bilgileri şifreli saklanır, varsayılan
180 gün sonra silinir; yeni talepler yönetici e-posta adresinize bildirilir. Talepleri bu ekrandan onaylar,
reddeder veya silersiniz.

## 2a. Yayındaki site: Claude'a "custom connector" olarak ekleyin

Siteniz internetten erişilebiliyorsa (HTTPS):

1. Claude'da **Customize → Connectors** (Team/Enterprise'da yönetici: **Organization settings → Connectors**).
2. **+** → **Add custom connector**.
3. Ad: `AI Katalog – Firmanız`, URL: `https://siteniz.com/wp-json/aihs/mcp`.
4. Kimlik doğrulama gerekmez (sunucu herkese açıktır).
5. Yeni bir sohbette bağlayıcıyı açın ve sorun: *"Firmanın stokta 3x2,5 kablosu var mı, 500 metre kaç günde gelir?"*

Not: Claude bağlantıyı Anthropic'in sunucularından kurar; yerel (localhost) ya da güvenlik duvarı
arkasındaki siteler bu yolla bağlanamaz. Kaynak: [Getting started with custom connectors using remote MCP](https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp).

## 2b. Yerel site: Claude Desktop'a WP-CLI (STDIO) ile bağlayın

Geliştirme ortamında (wp-env) Claude Desktop, sunucuyu WP-CLI üzerinden doğrudan çalıştırabilir.

1. WordPress CLI kapsayıcısının adını bulun:

   ```bash
   docker ps --format "{{.Names}}" | grep -- "-cli-1" | grep -v tests
   ```

2. Claude Desktop → **Settings → Developer → Edit Config** ile `claude_desktop_config.json` dosyasına ekleyin
   (`KAPSAYICI_ADI` yerine 1. adımdaki adı yazın):

   ```json
   {
     "mcpServers": {
       "ai-katalog": {
         "command": "docker",
         "args": ["exec", "-i", "KAPSAYICI_ADI", "wp", "mcp-adapter", "serve", "--server=aihs-catalog", "--user=admin"]
       }
     }
   }
   ```

3. Claude Desktop'ı yeniden başlatın; araç simgesinde `ai-katalog` görünür.

Canlı bir sunucuda WP-CLI ile bağlanacaksanız `--user` için yalnızca okuma yetkisi olan bir kullanıcı kullanın.

## REST ve OpenAPI (1.18.0)

MCP kullanmayan agentlar ve araçlar aynı kataloğu REST ile okuyabilir. `rest_api` açıkken API'nin tamamı OpenAPI 3.1
belgesiyle tarif edilir: `https://siteniz.com/wp-json/aihs/v1/openapi.json`. Bu adres sayfalardaki keşif bağlantısında
(`rel="service-desc"`) ve llms.txt'de de yer alır. OpenAPI'den araç üreten sistemlere (ör. ChatGPT'de özel GPT
"Actions") bu adres verilebilir; kimlik doğrulama gerekmez.

## Güvenlik ve sınırlar

- Katalog araçları yalnızca okur (`readOnlyHint`); talep aracı yazar ama hiçbir şeyi otomatik onaylamaz.
- Talep bırakma istemci başına dakikada 3, günde 20 ile sınırlıdır; şüpheli talepler karantinaya düşer.
- Her istemci için dakikada 60 araç çağrısı sınırı vardır (`aihs_abilities_rate_limit` filtresi).
- Tarayıcıdan gelen isteklerde `Origin` başlığı sitenin kendisi değilse istek reddedilir
  (başka kökenlere izin vermek için `aihs_mcp_allowed_origins` filtresi).
- Cevaplar ilandaki bilgiye dayanır. Stok veya teslim süresi girilmemişse cevap `unknown` olur;
  kesin teklif için firmayla iletişime geçilmelidir.
- MCP çağrıları **AI Hazır Site → AI Ölçüm** sayfasında "MCP çağrıları" tablosunda sayılır (istemci bilgisi saklanmaz).
- Kapatmak için `mcp` anahtarını kapatın; `abilities` açık kalabilir.
