# OpenAPI 3.1 resmi JSON Şeması

- Dosya: `oas-3.1-schema-2022-10-07.json`
- Kaynak: https://spec.openapis.org/oas/3.1/schema/2022-10-07 (OpenAPI Initiative), 2026-10-01'de indirildi, değiştirilmedi.
- SHA-256: `da01ba28852cac0de53893797cb8d1942bc3b05084f526dcc216717dec314ed0`
- Lisans: Apache License 2.0 (OpenAPI Specification deposu).
- Kullanım: yalnızca testlerde (`OpenApiDocumentTest`), eklenti paketine girmez (`/tests` export-ignore).
- Not: `opis/json-schema` bu şemadaki `$dynamicRef: "#meta"` referansını belgenin köküne çözüyor (JSON Schema 2020-12'ye
  aykırı; en küçük örnekle doğrulandı). Test, dosyayı diskte değiştirmeden, bellekte bu referansı standardın bu durumda
  gösterdiği hedefe (`$ref: "#/$defs/schema"`, aynı dosyadaki `$dynamicAnchor: "meta"`) çevirir.
