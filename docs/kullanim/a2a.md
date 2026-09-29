# A2A kartviziti ve agent (1.6.0)

Siteniz, başka firmaların AI agent'larının **A2A 1.0** protokolüyle konuşabildiği bir agent olur. Karşı taraf
müsaitlik sorabilir ve teklif isteği bırakabilir. Siz de eşleşen bir ortağa, **yalnızca onayınızla**, teklif
isteği gönderebilirsiniz. Her iki tarafta da son karar insandadır.

```bash
wp eval 'AIHazirSite\Core\Features::set( "a2a", true );'
```

## Gelen istekler

| Adres | Ne |
| --- | --- |
| `/.well-known/agent-card.json` | Agent kartviziti. Yalnızca en az bir beceri çalışırken ve kart resmi şemaya uyarken yayınlanır; aksi hâlde 404. |
| `POST /wp-json/aihs/a2a` | JSON-RPC 2.0, yöntem `SendMessage`. `A2A-Version: 1.0` başlığı gerekir. |

Beceriler (DataPart içinde `"skill"` alanıyla seçilir):

- `musaitlik-sor` (katalog açıkken): `{"skill": "musaitlik-sor", "listing_id": 12, "quantity": 1500, "within_days": 10}`
  → yes / no / unknown ve gerekçeler.
- `teklif-iste` (teklif kutusu açıkken): `{"skill": "teklif-iste", "listing_id": 12, "message": "…", "contact": {…}}`
  → talep teklif kutusuna düşer. Otomatik onay ve otomatik yanıt yoktur.

Her gelen mesaj denetim kaydına işlenir (kanal `a2a`, IP yerine tuzlu özet). Hız sınırı: istemci başına dakikada 30
istek (`aihs_a2a_rate_limit` süzgeci).

## Giden istek (onayla)

1. **AI Katalog → Eşleşmeler**'de bir aranan ilanı eşleştirin. Ortak siteden gelen adayın yanında
   **A2A ile teklif iste** düğmesi çıkar (A2A açıkken).
2. Açılan sayfada gönderilecek JSON-RPC mesajının tamamı ve alıcı adres görünür. Alıcının kartviziti, ortak sitenin
   kendi alan adındaki `/.well-known/agent-card.json` adresinden okunur ve doğrulanır. Geçersizse gönderim yapılmaz.
3. **Onaylıyorum, gönder** dediğinizde yalnızca o mesaj gönderilir. Onay belirteci tek kullanımlıktır ve 15 dakika
   geçerlidir. Gönderim ve yanıt denetim kaydına işlenir.

Önizleme sayfasını açmak hiçbir istek göndermez.

## Uçtan uca demo

**Yerel (otomatik):** `tools/a2a-demo/demo.sh` iki siteyi gerçek HTTPS ile kurar ve aşağıdaki akışı baştan sona
çalıştırır ([tools/a2a-demo/README.md](../../tools/a2a-demo/README.md), örnek çıktı
[docs/demo/a2a-yerel-2026-09-29.md](../demo/a2a-yerel-2026-09-29.md)).

**Canlı (elle, iki gerçek site):** Dağıtıcı sitesi (D) ve fabrika sitesi (F), ikisi de 1.6.0 veya üstü ve https.
1.9.0'dan beri özellikler **Ayarlar → AI Hazır Site** ekranından açılır (teklif kutusu kendi ekranından, KVKK
uyarısıyla).

1. **F:** `catalog`, `inquiries`, `rest_api`, `a2a` açık. Bir "satılan" ilan girin (ör. NYY 3x2,5, 5000 m, 5 gün).
   `https://F/.well-known/agent-card.json` adresinin açıldığını kontrol edin.
2. **D:** `catalog`, `matching`, `a2a` açık. Firma profilinde kurumsal e-posta dolu olsun. "Aranan" ilan girin
   (NYY, 2000 m, 10 gün). Eşleşmeler → Ayarlar'da ortak site olarak `https://F/wp-json/aihs/v1/` girin.
3. **D:** Eşleşmeler'de aranan ilanı seçin; F'nin ilanı listede. **A2A ile teklif iste** → mesajı okuyun →
   **Onaylıyorum, gönder**. (İnsan onayı 1.)
4. **F:** AI Katalog → Teklif Kutusu'nda talep "yeni" olarak görünür (kanal a2a). İnceleyip **Onayla** ve e-postayla
   dönün. (İnsan onayı 2.)
5. Kayıt: iki sitenin denetim kayıtları (D: `send`, F: `message` + `submit`) ve ekran görüntüleri.
