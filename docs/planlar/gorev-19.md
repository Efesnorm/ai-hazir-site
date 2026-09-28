# Görev 19 – A12 A2A kartviziti ve agent: plan (onay bekliyor)

> `gorev-19` dalı, `gorev-18` üzerine kuruldu (bu kopya: `gorev-19-botsuz`, `gorev-18-botsuz` üzerine; sürüm 1.5.0). Kullanıcı uyurken hazırlandı; birleştirme/etiket yok.
> **Hiçbir dış agent'a istek gönderilmedi.** Uçtan uca demo (iki site, iki tarafta insan onayı) elle yapılacak;
> adımları §5'te.

## 1. Resmi belgeden doğrulama (A2A v1.0.0)

Kaynak: a2aproject/A2A deposu, `specification/a2a.proto` ve `docs/specification.md` (sürüm 1.0.0, Linux Foundation).

- **Kartvizit adresi:** `https://{alan}/.well-known/agent-card.json` (RFC 8615).
- **AgentCard zorunlu alanları:** `name`, `description`, `supportedInterfaces[]` (`url`, `protocolBinding`,
  `protocolVersion`), `version`, `capabilities`, `defaultInputModes`, `defaultOutputModes`, `skills[]`
  (`id`, `name`, `description`, `tags` zorunlu; `examples`, `inputModes`, `outputModes` isteğe bağlı).
  İsteğe bağlı: `provider` (`url`, `organization`), `documentationUrl`, `securitySchemes`, `iconUrl` vb.
- **Bağlama:** `JSONRPC` (JSON-RPC 2.0). Yöntem: `SendMessage` (`params` = SendMessageRequest: `message`).
- **Sürüm başlığı:** `A2A-Version: 1.0`. Başlık yoksa 0.3 varsayılır. Desteklenmeyen sürüm için
  `VersionNotSupportedError` (-32009).
- **Görev yaşam döngüsü:** `TASK_STATE_SUBMITTED`, `WORKING`, `COMPLETED`, `FAILED`, `CANCELED`,
  `INPUT_REQUIRED`, `REJECTED`, `AUTH_REQUIRED`. Rol: `ROLE_USER` / `ROLE_AGENT`. Enum'lar JSON'da büyük harfle.
- **Hata kodları:** -32001 TaskNotFound … -32009 VersionNotSupported; standart JSON-RPC hataları (-32600, -32601,
  -32602).
- **Kimlik doğrulama:** kartta `securitySchemes` ile bildirilir. Bizim uç noktamız herkese açık okumaya ve talep
  bırakmaya açıktır (A6/A7 ile aynı); bu yüzden şema bildirilmez, hız sınırı uygulanır.

## 2. Plan

Anahtar: `a2a` (varsayılan kapalı).

**Kanal** `src/Adapters/A2A/` (platformdan bağımsız):
- `A2ASkills`: `musaitlik-sor` (A6 `aihs/check-availability` ile aynı kaynak: `Availability`) ve `teklif-iste`
  (A7 `InquiryService`; hukuk sitelerinde yönlendirme talebi kuralları aynen).
- `AgentCardBuilder`: kartı firma profilinden ve açık yeteneklerden üretir.
- `AgentCardValidator`: resmi proto'nun zorunlu alanlarına ve türlerine göre denetim (sözleşme testi bununla).
- `JsonRpcServer`: JSON-RPC 2.0 + `A2A-Version` denetimi; `SendMessage` → beceri → `Task` (sonuç `artifacts` içinde).
  Görevler saklanmaz: yanıt son durumla döner (`GetTask` vb. için `UnsupportedOperationError`).

**WordPress**:
- `POST /wp-json/aihs/a2a` uç noktası (hız sınırı; her mesaj denetim kaydına: kanal `a2a`).
- `/.well-known/agent-card.json`: yalnızca anahtar açık, en az bir beceri çalışır durumda **ve** üretilen kart
  doğrulayıcıdan geçiyorsa yayınlanır; aksi hâlde 404 (yarım veya eski biçimli kart asla yayınlanmaz).
- **Giden istek:** yalnızca Eşleşmeler ekranında, ortak site listesindeki bir sitenin eşleşen ilanı için
  "A2A ile teklif iste" → gönderilecek mesajın tam önizlemesi → kullanıcı onayı → gönderim. Karşı tarafın kartı,
  ortak sitenin kendi alan adındaki `/.well-known/agent-card.json` adresinden okunur ve doğrulanır. Gönderim ve yanıt
  denetim kaydına işlenir.
- Mimari testi: giden A2A mesajı yalnızca onay işleyicisinden ve yalnızca `ApprovedRequest` ile gönderilebilir.

## 3. Onay bekleyenler (temkinli seçimle uygulandı)
1. Kimlik doğrulama şeması yok (herkese açık; hız sınırı + A7 güvenlik katmanı). Ortaklara özel yetki gerekirse
   `securitySchemes` (ör. API anahtarı) eklenir.
2. Görevler saklanmıyor (senkron beceriler). İnsan onayı gerektiren teklif, A7 teklif kutusunda bekler;
   karşı taraf sonucu e-postayla / kendi paneliyle öğrenir.
3. `A2A-Version` başlığı olmayan (0.3) istekler reddedilir (-32009); v0.3 desteği istenirse eklenir.
4. Mevcut `TelemetrySendsOnlyWithConsentTest` (Görev 16, onay bekliyor) giden POST listesine A2A gönderici eklendi.

## 4. Kaynaklar
- https://a2a-protocol.org/latest/specification/ (1.0.0), https://github.com/a2aproject/A2A (a2a.proto)
- JSON-RPC 2.0 (jsonrpc.org/specification), RFC 8615 (well-known URI)
5. Mevcut testlerde değişiklik: `FeaturesTest` anahtar listesine `a2a => false`; `TelemetrySendsOnlyWithConsentTest`
   (Görev 16, o da onay bekliyor) izinli giden POST dosyalarına `A2AOutbox`.

## 5. Sonuç (uygulandı)
- 3 işleme: kanal (kart, doğrulayıcı, JSON-RPC) → WordPress (uç nokta, kart yayını, onaylı giden istek) → belgeler, sürüm 1.6.0.
- `composer check` temiz: 339 birim, 174 entegrasyon testi.
- Kabul: kart resmi şemaya uygun (sözleşme testi; U1 `advanced` ile aynı zorunlu alanlar); uç nokta kapalıyken kart
  yayınlanmıyor; onay olmadan hiçbir dış istek gönderilmiyor (mimari + entegrasyon: önizleme istek atmıyor, onayla
  tam olarak önizlenen mesaj gidiyor, belirteç tek kullanımlık).
- Elle deneme (geliştirme sitesi, gerçek HTTP): kapalıyken 404, açıkken geçerli kart, `SendMessage` görev döndürüyor.
- **Yapılmayan:** uçtan uca iki site demosu (iki gerçek https site ve iki tarafta insan gerekir). Adımlar
  `docs/kullanim/a2a.md`'de.
