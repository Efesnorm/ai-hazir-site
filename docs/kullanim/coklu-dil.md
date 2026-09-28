# Çoklu dil (1.1.0)

İhracat yapan bir firmanın yabancı alıcılarının AI asistanları, katalogdaki bilgiyi kendi dillerinde okuyabilir.
**Otomatik çeviri yapılmaz:** yalnızca sizin girdiğiniz çeviriler yayınlanır.

## Açma

```bash
wp eval 'AIHazirSite\Core\Features::set( "multilingual", true );'
```

`catalog` anahtarı da açık olmalıdır. Sonra **AI Katalog → Çeviriler**:

1. **Diller:** varsayılan dil (ilanları girdiğiniz dil, ör. `tr`) ve diğer diller (ör. `en, de, ar`).
   Polylang veya WPML kuruluysa diller oradan okunur; bu bölüm yalnızca gösterir.
2. **Firma profili:** sektörün diğer dillerdeki karşılığı.
3. **İlanlar:** her ilanın dil başına durumu ("tamam" ya da "N alan eksik"); **Çevir** ile başlık, açıklama,
   kategori ve bölge girilir. Boş bırakılan alan, o dilde varsayılan dildeki metinle gösterilir.

Tek dil tanımlıyken (ya da anahtar kapalıyken) hiçbir çıktı değişmez.

## AI'lar dili nasıl seçer

| Kanal | Dil seçimi | Eksik çeviri işareti |
| --- | --- | --- |
| REST `/wp-json/aihs/v1/…` | `?lang=en` ya da `Accept-Language: en` başlığı | Gövdede `translation.missing` ve `fallback_language`; yanıtta `Content-Language` |
| MCP / Abilities | Araç girdisinde `lang` (yayınlanan diller listelenir) | Çıktıda `translation.missing` |
| `/llms.txt` | `/llms.txt?lang=en` (varsayılan metin diğer dillerin adreslerini listeler) | Satır sonunda "(çevirisi yok, tr dilinde: açıklama)" |
| `/ai-katalog/` | `/ai-katalog/?lang=en` (`hreflang` bağlantılarıyla) | Metin `lang="tr"` özniteliğiyle işaretlenir; JSON-LD'de `inLanguage` |

Yayınlanmayan bir dil istenirse varsayılan dilde cevap verilir.

## Çevrilmeyenler

Firma adı, iletişim bilgileri, sayılar, fiyatlar, tarihler ve şablon alan değerleri dilden bağımsızdır.
Şablon alan etiketleri (ör. "Kalan yer") ve eklentinin kendi kalıp metinleri şimdilik Türkçedir
(bkz. [bilinen sınırlar](../bilinen-sinirlar.md)).
