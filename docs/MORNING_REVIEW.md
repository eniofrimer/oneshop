# Jutarnji nastavak s Astrom

Korisnik je izričito odgodio kritične dijelove do jutra. Ovaj dokument priprema pregled; ne odobrava produkcijsku naplatu niti unaprijed bira poslovna pravila. Aktualne izvršne rezultate i stalni URL pogledati u [STATUS.md](STATUS.md).

Nastavak s Astrom pokrenut je 30. 10. 2026. Konkretni nalazi, ispravci i produkcijski scenariji sada su u [pregledu naplate](PAYMENT_REVIEW.md). Ranija odgoda ne blokira taj tehnički pregled; poslovni i vanjski pristupi i dalje se ne pretpostavljaju.

## Redoslijed tehničkog pregleda

| Područje | Početne datoteke | Što sljedeća faza treba razriješiti |
| --- | --- | --- |
| Naplata i zaliha | [CheckoutDraft](../app/Checkout/CheckoutDraft.php), [PaymentLedger](../app/Payments/PaymentLedger.php), [StripeSandboxGateway](../app/Payments/StripeSandboxGateway.php), [dokazi i granice](PAYMENT_ATTEMPTS.md) | Rezervacije i trajni testni pokušaji su implementirani. Adapter i javni tok imaju oba SQL enginea, Docker, browser/admin i recovery dokaz; 17d7695 prolazi 273 testa i 14 konkurentnih scenarija po bazi te je u previewu uz isključen sandbox. Stvarni merchant sandbox prihvat, PayPal, produkcijska politika i refund ledger ostaju otvoreni. |
| Procesori i dokumenti | [Granice razvojnog checkouta](DEVELOPMENT_CHECKOUT.md), [pregled odluka](CLAUDE_REVIEW.md), [OrderMoney](../app/Orders/OrderMoney.php), [OrderSummary](../app/Orders/OrderSummary.php) | Stripe testni adapter i provjera poruke/iznosa/valute su implementirani; stvarni merchant prihvat, produkcijsko poravnanje/povrati, PayPal/virman i dokumentni identitet ostaju otvoreni. Interni PDF sažetak nije račun. |
| Kupac i administratorska prijava | [GuestCart](../app/Storefront/GuestCart.php), [User](../app/Models/User.php), [MFA middleware](../app/Http/Middleware/RequireAdministratorMfa.php), [auth testovi](../tests/Feature/AuthenticationBoundaryTest.php), [superadmin testovi](../tests/Feature/SuperAdministratorTest.php) | Odvojeni kupčev identitet/session guard, Google prijava i povrat gostujuće narudžbe. Izričito tražena superadmin iznimka bez obaveznog MFA ostaje zahtjev; obični administratori zadržavaju MFA. |
| Plaćena digitalna prava | [DigitalAssetEditor](../app/Digital/DigitalAssetEditor.php), [DigitalAssetsTest](../tests/Feature/DigitalAssetsTest.php) | Dodjela/revokacija prava, gostujuće poveznice i autorizacija nakon stvarne potvrđene naplate. Administratorski upload/download/arhiva već rade; kupčeva prava nisu implementirana. |
| Porez i dostava | [ShippingOptions](../app/Shipping/ShippingOptions.php), [StoreShippingModifier](../app/Shipping/StoreShippingModifier.php), [VariantEditor](../app/Catalog/VariantEditor.php), [ShippingMethodTest](../tests/Feature/ShippingMethodTest.php) | Potvrđena trgovčeva pravila i zaokruživanje, B2B/OIB/Sudreg, ugovorene tarife i odabrane integracije. Demo 25% i HR dostava 5 EUR su testni podaci. |
| Hosting i oporavak | [DEPLOYMENT](DEPLOYMENT.md), [recovery rehearsal](RECOVERY_REHEARSAL.md), [CheckRuntime](../app/Console/Commands/CheckRuntime.php), [native primjer](../deploy/hosting.env.example) | Prihvat stvarnog cPanel/FastPanel računa ili Docker hosta: web PHP, HTTPS, cron/worker, mail, tajne i offsite obnova prema dogovorenom RPO/RTO. CI/native VM dokaz nije potvrda stvarnog panela. |

## Dokazi koje treba sačuvati

Za stvarni Stripe sandbox prihvat nedostaju vlasnikovi testni account ID, secret key i pripadni webhook secret, postavljeni privatno u env, te jedna ručna hosted kartična proba. Ne slati ključeve u chat niti ih commitati. Trenutačni fixture/SDK/UI dokazi ne zamjenjuju taj korak. Bez tih podataka nastavlja se neovisni razvoj uz isključen vanjski procesor.

Postojeći testovi čuvaju serverske iznose i povijesne adrese/stavke, granicu tuđe sesije, ponovljenu demo predaju/uspjeh, zadnju zalihu, promijenjeni pregled i neovisne brojače zahtjeva. Uz njih su dodani izvršni Stripe SDK, inbox/ledger i konkurentni dokazi sa sintetičkim provider odgovorima; detalji su u PAYMENT_ATTEMPTS.md. Merchant kartična proba, produkcijski prihvat i potpuna porezna pravila još nisu potvrđeni.

Prije implementacije produkcijske naplate sastaviti konkretne scenarije pada i prihvata na obje podržane baze. Ne uključivati simulator u production niti otvarati napuštenu `/login` rutu radi olakšavanja nove prijave. Podatke korisnikova previewa ne koristiti za destruktivne testove; testna baza mora biti fizički odvojena i potrošna.

## Najmanji potrebni poslovni podaci po fazi

Za sam tehnički pregled kodova i pripremu scenarija nije potreban unos korisnika. Kada implementacija dođe do vanjske usluge, zatražiti samo podatke tog koraka:

- Procesor: koji se spaja prvi te testni račun/ključevi i njegova povratna adresa; tajne idu u privatnu konfiguraciju, ne u Git ili dokumentaciju.
- Trgovac: identitet, valuta/države prodaje, računovođina porezna pravila i odabrana dokumentna integracija.
- Dostava: ugovoreni partneri/usluge i stvarne tarife; paketomati/karte zahtijevaju konkretan izvor podataka.
- Hosting: konkretna instanca i domena te odabrana lokacija/retencija kopija i RPO/RTO.

Četiri pripremljene teme, osnovni katalog i ručne tarife ne zatvaraju odluke o vizualnom builderu, importu/exportu ili popisu dodatnih jezika. Te odluke ostaju otvorene u [PLAN-u](PLAN.md).

Za jutarnju ručnu probu koristi [kratki redoslijed](NIGHT_DEMO_20261030.md). Sesijska povijest narudžbi, admin globalna pretraga i sigurni pregled aktivnosti su isporučeni; kupčev račun i oporavak pristupa nakon gubitka sesije nisu time uvedeni. `23a9f6d` ima puni CI s 295 testova / 3.278 assertions po bazi te novu čistu native instalaciju i obnovu. Aktualne verzije i granice uvijek su u STATUS.md.
