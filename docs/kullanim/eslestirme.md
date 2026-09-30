# Eşleştirme (1.5.0)

"Aranan" ilanlarınızı, satılan ve tedarik edilebilen ilanlarla otomatik eşleştirir. İlk kullanım: kablo
dağıtıcısının ihtiyacı ↔ kablo fabrikasının ürünleri. **Eşleşmeler öneridir; hiçbir teklif veya mesaj otomatik
gönderilmez.**

```bash
wp eval 'AIHazirSite\Core\Features::set( "matching", true );'
```

## Kullanım: AI Hazır Site → Eşleşmeler

Bir aranan ilan seçip **Eşleştir**'e basın. Adaylar puan sırasıyla listelenir. Her satırda ölçüt başına alınan puan
görünür (ör. "Özellik uyumu 25/25 · Miktar karşılama 12,5/25 · …"); bu puanların toplamı adayın puanıdır.

### Kesin şartlar (uymayan listelenmez, nedeni gösterilir)

- **Tür:** aranan ilan yalnızca satılan veya tedarik edilebilen ilanla eşleşir.
- **Kategori:** aranan ilanda kategori varsa aynı olmalı.
- **Şablon:** iki taraf da "genel" dışında bir şablon kullanıyorsa aynı olmalı.
- **Standart** (ürün şablonu): aranan ilandaki her standart adayda da olmalı.
- **Bölge:** iki tarafta da doluysa aynı olmalı.
- **Teslim süresi:** adayın teslim süresi, aranan ilandaki süreden uzun olmamalı.

### Puan (0–100)

S = w1·özellik uyumu + w2·miktar karşılama + w3·teslim uyumu + w4·fiyat örtüşmesi. Ağırlıkların toplamı 1'dir;
başlangıçta dördü eşittir (0,25) ve Ayarlar'dan değiştirilebilir.

- **Özellik uyumu:** aranan ilandaki özelliklerin (standart hariç) adayda aynı değerle bulunma oranı.
- **Miktar karşılama:** adayın miktarı ÷ istenen miktar, en çok 1. Birimler farklıysa 0.
- **Teslim uyumu:** sınır içindeyse 1.
- **Fiyat örtüşmesi:** fiyat aralığı bütçeyle kesişiyorsa 1; kesişmiyorsa aradaki fark bütçeye oranla puanı düşürür.
  Farklı para birimi karşılaştırılmaz.

Bir ölçütte bilgi eksikse 0,5 sayılır. Aranan ilan o ölçütü hiç sormuyorsa 1 sayılır.

## Ortak siteler

Ayarlar'a ortak sitelerin AI Katalog REST adresini (ör. `https://fabrika.example/wp-json/aihs/v1/`) her satıra bir
tane yazın. Kurallar:

- Yalnızca `https` adresler kabul edilir.
- Otomatik keşif yapılmaz.
- Ortak sitelerin yanıtları bir saat önbellekte tutulur.
- Erişilemeyen ortak site atlanır ve ekranda belirtilir; bu sitenin ilanlarıyla eşleştirme sürer.
