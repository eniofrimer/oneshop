# Jutarnji nastavak s Astrom

Korisnik je izričito odgodio kritične dijelove do jutra. Ovaj dokument priprema pregled; ne odobrava produkcijsku naplatu niti unaprijed bira poslovna pravila. Aktualne izvršne rezultate i stalni URL pogledati u [STATUS.md](STATUS.md).

## Redoslijed tehničkog pregleda

| Područje | Početne datoteke | Što sljedeća faza treba razriješiti |
| --- | --- | --- |
| Naplata i zaliha | [DevelopmentCheckoutService](../app/Checkout/DevelopmentCheckoutService.php), [DevelopmentCheckout](../app/Models/DevelopmentCheckout.php), [CheckoutController](../app/Http/Controllers/Storefront/CheckoutController.php), [checkout testovi](../tests/Feature/DevelopmentCheckoutTest.php) | Model produkcijskih pokušaja, rezervacije/istek, nepoznat ishod, zakašnjela naplata, dupli događaji i oporavak. Postojeći simulator nema rezervacije i ne predstavlja vanjskog procesora. |
| Procesori i dokumenti | [Granice razvojnog checkouta](DEVELOPMENT_CHECKOUT.md), [pregled odluka](CLAUDE_REVIEW.md), [OrderMoney](../app/Orders/OrderMoney.php), [OrderSummary](../app/Orders/OrderSummary.php) | Adapteri Stripe/PayPal/virmana, provjera poruke/iznosa/valute, poravnanje/povrati i dokumentni identitet. Interni PDF sažetak nije račun. |
| Kupac i administratorska prijava | [GuestCart](../app/Storefront/GuestCart.php), [User](../app/Models/User.php), [MFA middleware](../app/Http/Middleware/RequireAdministratorMfa.php), [auth testovi](../tests/Feature/AuthenticationBoundaryTest.php), [superadmin testovi](../tests/Feature/SuperAdministratorTest.php) | Odvojeni kupčev identitet/session guard, Google prijava i povrat gostujuće narudžbe. Izričito tražena superadmin iznimka bez obaveznog MFA ostaje zahtjev; obični administratori zadržavaju MFA. |
| Plaćena digitalna prava | [DigitalAssetEditor](../app/Digital/DigitalAssetEditor.php), [DigitalAssetsTest](../tests/Feature/DigitalAssetsTest.php) | Dodjela/revokacija prava, gostujuće poveznice i autorizacija nakon stvarne potvrđene naplate. Administratorski upload/download/arhiva već rade; kupčeva prava nisu implementirana. |
| Porez i dostava | [ShippingOptions](../app/Shipping/ShippingOptions.php), [StoreShippingModifier](../app/Shipping/StoreShippingModifier.php), [VariantEditor](../app/Catalog/VariantEditor.php), [ShippingMethodTest](../tests/Feature/ShippingMethodTest.php) | Potvrđena trgovčeva pravila i zaokruživanje, B2B/OIB/Sudreg, ugovorene tarife i odabrane integracije. Demo 25% i HR dostava 5 EUR su testni podaci. |
| Hosting i oporavak | [DEPLOYMENT](DEPLOYMENT.md), [recovery rehearsal](RECOVERY_REHEARSAL.md), [CheckRuntime](../app/Console/Commands/CheckRuntime.php), [native primjer](../deploy/hosting.env.example) | Prihvat stvarnog cPanel/FastPanel računa ili Docker hosta: web PHP, HTTPS, cron/worker, mail, tajne i offsite obnova prema dogovorenom RPO/RTO. CI/native VM dokaz nije potvrda stvarnog panela. |

## Dokazi koje treba sačuvati

Postojeći testovi čuvaju serverske iznose i povijesne adrese/stavke, granicu tuđe sesije, ponovljenu demo predaju/uspjeh, zadnju zalihu, promijenjeni pregled i neovisne brojače zahtjeva. To su regresijski dokazi trenutnog razvojnog ponašanja. Ne dokazuju konkurentno poravnanje kod vanjskog procesora, webhook inbox, produkcijske rezervacije ili sve porezne slučajeve.

Prije implementacije produkcijske naplate sastaviti konkretne scenarije pada i prihvata na obje podržane baze. Ne uključivati simulator u production niti otvarati napuštenu `/login` rutu radi olakšavanja nove prijave. Podatke korisnikova previewa ne koristiti za destruktivne testove; testna baza mora biti fizički odvojena i potrošna.

## Najmanji potrebni poslovni podaci po fazi

Za sam tehnički pregled kodova i pripremu scenarija nije potreban unos korisnika. Kada implementacija dođe do vanjske usluge, zatražiti samo podatke tog koraka:

- Procesor: koji se spaja prvi te testni račun/ključevi i njegova povratna adresa; tajne idu u privatnu konfiguraciju, ne u Git ili dokumentaciju.
- Trgovac: identitet, valuta/države prodaje, računovođina porezna pravila i odabrana dokumentna integracija.
- Dostava: ugovoreni partneri/usluge i stvarne tarife; paketomati/karte zahtijevaju konkretan izvor podataka.
- Hosting: konkretna instanca i domena te odabrana lokacija/retencija kopija i RPO/RTO.

Četiri pripremljene teme, osnovni katalog i ručne tarife ne zatvaraju odluke o vizualnom builderu, importu/exportu ili popisu dodatnih jezika. Te odluke ostaju otvorene u [PLAN-u](PLAN.md).
