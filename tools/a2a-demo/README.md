# A2A iki site demosu (yerel)

PRD Faz 4'ün kapı şartı "fabrika ile dağıtıcı agentlarının konuşması". Bu klasör iki WordPress sitesini
(**dagitici.test** ve **fabrika.test**) gerçek HTTPS ile kurar ve A2A akışını uçtan uca çalıştırır. Eklenti kodu
değişmez; iki gerçek sitede çalışan kodun aynısıdır.

```bash
tools/a2a-demo/demo.sh          # kur ve çalıştır (her seferinde temiz başlar, ~2 dk)
tools/a2a-demo/demo.sh temizle  # kapsayıcıları ve verileri sil
```

Gerekenler: Docker (Compose v2). Windows'ta Git Bash ile çalışır.

## Ne olur
1. **fabrika.test**: katalog, teklif kutusu, REST ve A2A açılır; "NYY 3x2,5 enerji kablosu, 5000 m, 5 gün" ilanı girilir.
2. **dagitici.test**: katalog, eşleştirme ve A2A açılır; "NYY 3x2,5 kablo, 2000 m, 10 gün" aranan ilanı girilir,
   fabrika ortak site olarak eklenir.
3. Dağıtıcı fabrikanın agent kartını HTTPS ile okur ve doğrular.
4. **Eşleşme**: aranan ilan fabrikanın ilanıyla eşleşir.
5. **Önizleme**: gönderilecek JSON-RPC mesajı ve alıcı adres gösterilir (`A2AAdmin::page()`).
6. **İnsan onayı**: "Onaylıyorum, gönder" düğmesinin çağırdığı işleyici (`A2AAdmin::handle_approve()`: yetki, nonce,
   tek kullanımlık belirteç) çalışır. Demoda bu adımı betiği çalıştıran kişi atar.
7. Fabrikanın agent'ı talebi alır; talep fabrikanın **Teklif Kutusu**'na "yeni" olarak düşer (otomatik onay yok).
8. İki sitenin denetim kayıtları yazdırılır (dağıtıcı: `send`, fabrika: `message`).

Örnek çıktı: [docs/demo/a2a-yerel-2026-09-29.md](../../docs/demo/a2a-yerel-2026-09-29.md).

## Nasıl kurulur
- `docker-compose.yml`: MariaDB (iki veritabanı), iki `wordpress:php8.3-apache`, bir `caddy` (HTTPS önü, `tls internal`
  yerel sertifika yetkilisi; kapsayıcılar `*.test` adlarını ona çözer), WP-CLI kapsayıcıları. Eklenti klasörü salt
  okunur bağlanır.
- `aihs-a2a-demo.php` (yalnızca bu ortamda yüklü zorunlu eklenti): `WP_ENVIRONMENT_TYPE` "local" iken ve yalnızca
  `.test` alan adları için, WordPress'in güvenli HTTP işlevlerinin özel ağ adresi engelini ve yerel sertifikanın
  doğrulanmasını kapatır. Canlı sitelerde gerekmez ve bulunmaz.
- `adimlar.php`: WP-CLI ile çalışan demo adımları.

## Canlı demo (iki gerçek site)
Adımlar [docs/kullanim/a2a.md](../../docs/kullanim/a2a.md) "Uçtan uca demo" bölümünde. Canlıda bu klasöre gerek yoktur.
