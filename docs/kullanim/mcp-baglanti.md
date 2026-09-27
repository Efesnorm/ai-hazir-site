# Claude'u AI Katalog'a MCP ile bağlama

AI Hazır Site, firmanızın profilini ve geçerli ilanlarını **MCP (Model Context Protocol)** sunucusu
olarak yayınlayabilir. Claude gibi AI asistanları bu sunucuya bağlanıp kataloğunuzu doğrudan
sorgular: "Stokta 3x2,5 kablo var mı, kaç günde gelir?" gibi soruları ilanlarınızdaki bilgiyle cevaplar.

Sunucu **salt okunurdur**: yalnızca yayında olan bilgiyi (profil, geçerli ilanlar) okur, hiçbir şeyi değiştirmez.

## 1. Özelliği açın

1. **AI Katalog → Firma Profili**'ni doldurun ve en az bir ilan ekleyin.
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
| `aihs-search-listings` | Geçerli ilanları arar (tür, kategori, bölge, anahtar kelime, şablon alanları) |
| `aihs-get-listing` | Tek ilanın ayrıntıları |
| `aihs-check-availability` | "Bu miktar var mı, istenen günde gelir mi?" → yes / no / unknown ve gerekçe |

## 2a. Yayındaki site: Claude'a "custom connector" olarak ekleyin

Siteniz internetten erişilebiliyorsa (HTTPS):

1. Claude'da **Customize → Connectors** (Team/Enterprise'da yönetici: **Organization settings → Connectors**).
2. **+** → **Add custom connector**.
3. Ad: `AI Katalog – Firmanız`, URL: `https://siteniz.com/wp-json/aihs/mcp`.
4. Kimlik doğrulama gerekmez (sunucu herkese açık okumadır).
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

## Güvenlik ve sınırlar

- Sunucu yalnızca okur; araçlar `readOnlyHint` ile işaretlidir.
- Her istemci için dakikada 60 araç çağrısı sınırı vardır (`aihs_abilities_rate_limit` filtresi).
- Tarayıcıdan gelen isteklerde `Origin` başlığı sitenin kendisi değilse istek reddedilir
  (başka kökenlere izin vermek için `aihs_mcp_allowed_origins` filtresi).
- Cevaplar ilandaki bilgiye dayanır. Stok veya teslim süresi girilmemişse cevap `unknown` olur;
  kesin teklif için firmayla iletişime geçilmelidir.
- MCP çağrıları **Araçlar → AI Ölçüm** sayfasında "MCP çağrıları" tablosunda sayılır (istemci bilgisi saklanmaz).
- Kapatmak için `mcp` anahtarını kapatın; `abilities` açık kalabilir.
