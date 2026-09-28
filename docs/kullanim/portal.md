# Portal modu (1.2.0)

Bir turizm portalı gibi, birçok işletmeyi listeleyen bir site, bu işletmelerin tümünü AI'lara tek noktadan sunar.
Tek firmalı sitelerde bu özellik kapalı kalır ve hiçbir çıktı değişmez.

## Açma

```bash
wp eval 'AIHazirSite\Core\Features::set( "portal_mode", true );'
```

`catalog` anahtarı da açık olmalıdır; işletme sayfaları için AI katalog sayfası (`schema_output` ya da `llms_txt`) açık olmalıdır.

## Portal yöneticisi: AI Katalog → İşletmeler

- **İşletme ekle / düzenle:** ad, sektör, ülke, diller, kurumsal e-posta ve telefon, sertifikalar ve adres kısaltması
  (ör. `kapadokya-balon-turlari`; boş bırakılırsa addan üretilir).
- **İşletme yetkilisi:** kullanıcıyı önce WordPress'in **Kullanıcılar** ekranında (ör. Abone rolüyle) oluşturun, sonra
  burada kullanıcı adını veya e-postasını yazıp bir işletmeye bağlayın. Yöneticiler bir işletmeye bağlanmaz.
- **İlanların işletmeleri:** her ilanı bir işletmeye ya da portalın kendisine atayın.
- **İşletme bazında AI görünürlüğü (son 28 gün):** işletme sayfasının AI botlarınca okunma sayısı, ilan sayısı ve talep
  sayısı. Portalın kendi satırıyla birlikte toplam her zaman tutar; işletmelere "AI görünürlük paketi" satarken bu
  tabloyu kullanabilirsiniz.
- İlanı olan işletme silinmez; önce ilanları silin ya da başka bir işletmeye atayın.

## İşletme yetkilisi: İşletmem

Yetkili, yönetim panelinde yalnızca **İşletmem** menüsünü görür: kendi işletmesinin ilanlarını listeler, ekler,
düzenler ve siler. Başka işletmelerin ilanları görünmez; adres çubuğuna başka bir ilanın numarası yazılsa bile
açılmaz, değiştirilemez ve silinemez.

## AI'lar ne görür

| Kanal | Portal modunda |
| --- | --- |
| `/ai-katalog/` | İşletme dizini; her ilanda işletme adı ve sayfası |
| `/ai-katalog/isletme/{kısaltma}/` | İşletmenin profili, geçerli ilanları ve kendi JSON-LD'si |
| JSON-LD | Portal `Organization` aynı kalır; her ilanın satıcısı işletmenin kendi `Organization` düğümü |
| `/llms.txt` | "İşletmeler" dizini; ilan satırında "İşletme: …" |
| REST | İlanlarda `business`; `/listings?business={kısaltma}`; `GET /businesses` |
| MCP | `aihs/search-listings` girdisinde `business`; `aihs/list-businesses` aracı |

`business` verilmezse arama tüm işletmeleri kapsar: tek sorgu, tüm işletmelerden sonuç.
