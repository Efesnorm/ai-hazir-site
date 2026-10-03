# Öneri – Balkan portal ağı (5 ülke portalı ortak çalışsın) – kararlar alındı

Kaynak: 2026-10-04 kullanıcı isteği. Portallar (hepsi WordPress, Türkçe/İngilizce): kosova.org.tr, yunanistan.org.tr,
makedonya.org.tr, arnavutluk.org.tr, sirbistan.org.tr. Aynı sektör (turizm), farklı ülkeler. Hedef: AI ile işbirliği,
tıklanma, ortak veri; portallar birbiriyle rekabet değil işbirliği yapsın.

Bu belge [oneri-ortak-ag.md](oneri-ortak-ag.md)'deki genel "ortak ağ" fikrinin portallar için somutlaşmış hâlidir.

## Bugün var olan (kod gerekmez)
Her portala kurulum + "tur" önerilen kurulumu + portal modu:
- İşletmeler (otel, tur firması, rehber…) ayrı profillerle; ilanlar işletmeye bağlı; işletme yetkilisi yalnızca kendi
  profilini/ilanlarını düzenler ("İşletmem").
- Her işletmeye `/ai-katalog/isletme/<ad>/` sayfası (satıcı = işletme, JSON-LD), llms.txt işletme rehberi, REST
  `/businesses`, MCP/A2A cevaplarında işletme bilgisi, işletme başına AI görünürlük raporu.
- Tur şablonu (TouristTrip), ölçüm, uyum taraması, teklif kutusu, keşif, IndexNow.
**Ama her portal kendi başına:** portallar birbirini tanımıyor.

## Eksikler ve öneri (yeni geliştirme)

### Faz 1 – Ağ tanımı ve AI işbirliği (öneri: 1.20.0, anahtar `portal_network`, varsayılan kapalı)
1. **Ağ tanımı, karşılıklı onay:** Ayarlar'da ağ adı (ör. "Balkan Turizm Portalları") ve kardeş portal adresleri.
   Bir portal ancak iki taraf da birbirini listelemişse ağda görünür (karşılıklı doğrulama; tek taraflı ekleme ile
   başkasının adına bilgi verilmez). Kardeşin AI Hazır Site olduğu profil uç noktasından doğrulanır.
2. **Yapılandırılmış veri (Schema.org, kanıtlanmış):** her portalın Organization kaydına ortak çatı
   `parentOrganization` (ağın kendisi, tek ortak `@id`), çatı kaydında `subOrganization` listesi. Arama motorları ve
   AI'lar beş sitenin aynı ağın parçası olduğunu anlar.
3. **llms.txt "Kardeş portallar" bölümü:** her kardeş için ülke, llms.txt ve AI Katalog API adresi.
4. **AI cevaplarında işbirliği:** MCP / REST / A2A aramasında yerel sonuç yoksa (ör. kosova.org.tr'ye "Ohrid turu"
   soruldu) kardeş portalların uygun ilanları önerilir: portal, işletme, ilan adresi, `retrieved_at`; en çok 3.
   Veri kardeşin herkese açık REST'inden, 1 saat önbellekle (istek başına canlı sorgu yok). Yönlendirme yok,
   gizli içerik yok.
5. **Çok ülkeli turlar:** bir tur ilanı birden çok ülkeyi kapsayabilir (ör. "Kosova–Arnavutluk–K. Makedonya").
   İlan **tek bir portalda** yaşar (asıl adres, canonical); kapsadığı ülkelerin portalları onu kendi katalog sayfasında
   ve llms.txt'de "kardeş portalda" bağlantısıyla gösterir. Kopya içerik oluşmaz.

### Faz 2 – İnsan tıklamaları (öneri: 1.21.0)
6. **"Komşu ülkelerde" bloğu (kısa kod / blok):** portal sayfalarında kardeş portalların seçilmiş tur ilanları;
   bağlantılar standart UTM parametreleriyle (`utm_source=<portal>&utm_medium=portal-agi`) – GA4 dahil her analitikte
   ölçülebilir.
7. **Ölçümde "Ağ yönlendirmeleri":** kardeş portallardan gelen insan ziyaretleri ayrı satırda (AI yönlendirmelerinin
   yanında). Hangi portalın hangisine ne kadar ziyaretçi gönderdiği görünür.

### Faz 3 – Ortak veri (öneri: 1.22.0)
8. **Ağ raporu (merkez portal):** seçilen bir portal, diğerlerinin toplu sayılarını (AI bot okumaları, ChatGPT-User,
   AI yönlendirmeleri, ağ yönlendirmeleri, uyum puanı, talep sayısı – kişisel veri yok) tek ekranda gösterir.
   Erişim WordPress'in kendi **Uygulama Parolaları** (Application Passwords, çekirdek özellik) ile, yalnızca ağ üyelerine,
   HTTPS üzerinden. Ham veri paylaşılmaz, yalnızca toplamlar.
9. **Talep yönlendirme (işletmeye):** portal modunda bir işletmenin ilanına gelen talep, portal yöneticisine ek olarak
   o işletmenin yetkilisine de bildirilir (bugün yalnızca portal yöneticisine gidiyor).

## Riskler
- **Imunify:** portallar birbirinin REST'ini sunucudan okur; sunucu "bilinmeyen otomatik istemci" sayılabilir (Balanced:
  dakikada 5). Okuma saatte bir ve az istekle yapılır; gerekirse barındırmadan sunucu IP'lerinin beyaz listeye alınması.
- **Kopya içerik:** çok ülkeli tur tek yerde yaşar, diğerleri bağlantı verir (canonical).
- **Bağımlılık:** bir kardeş erişilemezse öneri verilmez, eski veri gösterilmez.

## Kurulum sırası (öneri)
1. Beş portala 1.19.x kurulum, "tur" kurulumu, portal modu; işletmeler ve tur ilanları (içerik işi).
2. Faz 1 → canlı deneme (iki portal arasında) → beşine yayma.
3. Faz 2, Faz 3.

## Kullanıcı kararları (2026-10-04)
- Beş portal aynı sahibe ait.
- Portallar **her sektöre açık** ülke rehberi (nakliye, hizmet, turizm…); yalnızca turizm değil.
- Ağ raporu **anne site**de. Anne site **seçilebilir**, sabit değil; sistem başka sektör projelerinin ağları için de
  kullanılabilir (Balkan ağı için ilk seçim makedonya.org.tr olabilir).
- Uygulama planı: [1.20.0-portal-agi-faz1.md](1.20.0-portal-agi-faz1.md) ve sonraki sürümler.

## İlk sorular (yanıtlandı)
- Ağ adı ve çatı kuruluş (aynı sahip mi? marka adı?).
- İçerik modeli: portallarda işletmeler (otel, tur firması) mı listelenecek, yoksa portalın kendi turları mı?
- Ağ raporu için merkez portal hangisi?
- yunanistan.org.tr'nin barındırma/önbellek bilgisi (diğer dördü `siteler.md`'de).
