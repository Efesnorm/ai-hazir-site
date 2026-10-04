# Portal modu (1.2.0)

Bir turizm portalı gibi, birçok işletmeyi listeleyen bir site, bu işletmelerin tümünü AI'lara tek noktadan sunar.
Tek firmalı sitelerde bu özellik kapalı kalır ve hiçbir çıktı değişmez.

## Açma

```bash
wp eval 'AIHazirSite\Core\Features::set( "portal_mode", true );'
```

`catalog` anahtarı da açık olmalıdır; işletme sayfaları için AI katalog sayfası (`schema_output` ya da `llms_txt`) açık olmalıdır.

## Portal yöneticisi: AI Hazır Site → İşletmeler

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

## Portal ağı (1.20.0)

Aynı sahibin birden çok sitesi (ör. ülke portalları) bir **ağ** olur ve AI'lara ağ olarak duyurulur. Portal modundan
bağımsızdır; anahtar: `portal_network` (önkoşul: REST API).

**AI Hazır Site → Portal Ağı** ekranında her site bir rol seçer:
- **Anne site:** ağın adını ve üye sitelerin adreslerini (her satıra bir https adresi, en çok 25) girer. Ağ buradan
  yönetilir ve ileride ağ raporu buradan izlenir. Anne sabit değildir; başka bir site anne yapılabilir.
- **Üye:** yalnızca anne sitenin adresini girer. Kardeş listesi anneden gelir.

Ekran yalnızca seçili rolün alanlarını gösterir. Üye sitede ağın adı girilmez; doğrulandıktan sonra anneden gelir
ve salt okunur görünür (1.23.1).

**Karşılıklı onay:** bir site ancak anne onu listelemişse **ve** site o anneyi göstermişse ağda görünür. Kontrol saatte
bir kendiliğinden yapılır; kurulumdan sonra önce üyede, sonra annede, sonra yeniden üyede "Şimdi kontrol et"e basın.
Ekran her sitenin durumunu gösterir: doğrulandı / bu siteyi anne olarak göstermiyor / anne başka / annede kayıtlı değil /
erişilemedi.

**AI'lar ne görür (yalnızca doğrulanmış siteler):**
- Ana sayfa yapılandırılmış verisinde sitenin kuruluşu ağın çatı kuruluşuna bağlanır (schema.org `parentOrganization`);
  annenin /ai-katalog/ sayfasında ağ ve üyeleri her zaman yer alır (SEO eklentisi olsa da, 1.23.1). Profil adı boş bir
  üye, annede site başlığıyla görünür; ülke yalnızca profilden gelir, bu yüzden her üyede Firma Profili'ni doldurun.
  anne sitede ağın kendisi ve üyeleri (`subOrganization`). SEO eklentisi kuruluşu üretiyorsa bilgi /ai-katalog/
  sayfasının yayıncısında verilir.
- llms.txt'de "Kardeş portallar – <ağ adı>" bölümü (her kardeşin llms.txt ve API adresi).
- `GET /wp-json/aihs/v1/network`: ağ adı, anne ve doğrulanmış siteler (OpenAPI belgesinde de).

Kardeş sitelerden gelen bilgiler (ad, ülke) temizlenir ve yalnızca veri olarak kullanılır. Bir kardeş geçici olarak
erişilemezse doğrulaması 24 saat korunur. Kapatınca bütün çıktılar kalkar, ayarlar korunur.

## Faaliyet alanı: NACE Rev. 2.1 (1.22.0)

Portallar her sektöre açık olduğundan sektör serbest metinle karşılaştırılamaz ("Nakliye", "Lojistik", "Transport").
Firma Profili ve her işletmenin formunda **Faaliyet alanı (NACE Rev. 2.1)** seçilebilir: AB'nin ortak sınıflandırması,
22 bölüm (ör. H = ulaştırma ve depolama, I = konaklama ve yiyecek hizmetleri, N = mesleki, bilimsel ve teknik
faaliyetler). İsteğe bağlıdır. Seçilirse REST, MCP ve llms.txt'de görünür ve AI'lar `sector=H` ile arar: portalda ilan
işletmesinin bölümüyle, portalın kendi ilanları sitenin bölümüyle eşleşir.

## Kardeş portal önerisi (1.22.0)

Anahtar: `network_suggestions` (önkoşul: Portal ağı). Bir AI bu sitede arar ve sonuç bulamazsa, yanıtta doğrulanmış
kardeş portallardaki en çok 3 uygun ilan `network_suggestions` olarak gelir (site, ülke, işletme, ilan adresi ve verinin
alındığı an). AI isterse `network=true` ile sonuç olsa da öneri ister. Kardeş katalogları saatlik ağ kontrolünden sonra
okunur (kardeş başına en çok 500 ilan); öneri en çok ~2 saat eskidir. Kardeş sitelerin hız sınırları için gerekirse
Entegrasyonlar → Güvenlik yazılımları (Wordfence izin listesi, barındırma firmasına metin) kullanılır.

## "Komşu ülkelerde" bloğu (1.23.0)

Anahtar: `network_block` (önkoşul: Portal ağı). Sayfa düzenleyicide **Komşu ülkelerde** bloğunu ekleyin ya da
klasik düzenleyicide/araç bileşeninde kısa kodu kullanın:

```
[aihs_komsu_ulkeler count="6" category="Tur" sector="I" title="Komşu ülkelerde"]
```

Ayarlar (hepsi isteğe bağlı): `title`, `count` (1–12), `type` (offer / demand / supply), `category`, `region`,
`sector` (NACE bölüm harfi), `site` (yalnızca bu kardeş portalın adresi). Blok doğrulanmış kardeşlerin ilanlarını
gösterir; uygun ilan yoksa hiçbir şey göstermez. Bağlantılar `utm_source=<siteniz>&utm_medium=portal-agi&utm_campaign=komsu-ulkeler`
ile etiketlidir; Google Analytics'te "Kampanyalar" altında görünür.

## Ağ yönlendirmeleri (1.23.0)

Anahtar: `network_referrals` (önkoşul: AI ölçümü ve Portal ağı). Doğrulanmış bir kardeş portaldan gelen insan
ziyaretleri **AI Ölçüm** raporunda "Ağ yönlendirmeleri" tablosunda, kardeş başına ve açılan sayfaya göre sayılır.
Kardeş, tarayıcının bildirdiği önceki sayfadan (Referer) ya da bloktaki UTM etiketinden tanınır. Kişisel veri
saklanmaz. Sayfa önbelleğinden (ör. LiteSpeed) dönen ziyaretler sayılmayabilir.
