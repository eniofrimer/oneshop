# Trajni pokušaji naplate i Stripe sandbox adapter

Datum: 30. 10. 2026. Uvedeni su lokalni ledger, Stripe SDK adapter, potpisani webhook inbox, scheduler obrada, read-only administracija i zaseban javni sandbox checkout. Refund servis i produkcijska naplata nisu uključeni. Stvarna Stripe sandbox kartica/račun još nisu provjereni; bez izričite konfiguracije kupnja ostaje na jasno označenom simulatoru.

## Implementirano

- Jedan nepredvidivi pokušaj po narudžbi, zamrznuti iznos/valuta, račun procesora i idempotency ključ. Samo izričito pripremljena nova stripe-test narudžba može ući u taj put. Postojeća demo narudžba ne može se pretvoriti u provider pokušaj.
- Jedna rezervacija prije naplate. Nepoznat ishod čuva držanje; ponovna priprema vraća isti pokušaj i iste parametre. Ne postoji korisnička radnja za zaobilaženje nepoznatog ishoda drugim pokušajem.
- Parametri budućeg API zahtjeva i mogući hosted URL enkriptirani su postojećim aplikacijskim ključem i skriveni iz uobičajene serijalizacije modela. Ključevi procesora nisu dio zapisa.
- Zaseban inbox s jedinstvenom kombinacijom procesora, računa, test okoline i event ID-a. Čuva minimalne identifikatore i SHA-256 izvornog tijela; ne čuva proizvoljan webhook payload. Isti ID s drugim fingerprintom odbija se.
- Zasebna financijska potvrda s jedinstvenim capture ID-em u istom scopeu. Različiti event ID-evi za istu naplatu ne stvaraju drugu financijsku radnju.
- PaymentLedger provjerava prethodno vezani provider objekt, očekivani scope, identitet pokušaja, iznos, valutu i narudžbu. U istoj SQL transakciji potvrđuje financijski zapis, potroši rezervaciju, stvara Lunar transakciju i outbox zapis.
- Kvar baze ili outbox upisa vraća sve navedene promjene; ponovni poziv ostaje siguran. Vanjski API pozivi nisu dio ovog servisa niti SQL zaključavanja.
- Potvrđen novac ostaje zabilježen i kad je rezervacija već oslobođena, zaliha oštećena ili valuta/iznos odstupaju. Takav zapis ide na provjeru bez automatske isporuke. Potvrđeni USD iznos ne upisuje se kao EUR Lunar transakcija.
- Zakašnjelo opažanje provider povrata/ospore naplate označava provjeru i ne vraća zalihu automatski. Ovo nije implementacija refund ledgera.
- Potvrđeni provider istek može osloboditi svoju rezervaciju jednom. Sam lokalni sat, povratak preglednika, timeout ili događaj koji još nije povezan s pokušajem ne oslobađaju zalihu.

Outbox trenutačno čuva samo financijske činjenice s jedinstvenim ključem. Nema potrošača za račune, poruke, dostavu ili digitalna prava. Njegova prisutnost nije dokaz izvršene isporuke.

## Granice povjerenja

ProviderCheckoutSnapshot, CapturedPayment i VerifiedProviderEvent su interni tipovi. StripeSandboxGateway koristi instalirani SDK 22.0.1, pinani API 2026-09-30.endive i fiksni Stripe API endpoint s uključenom TLS provjerom. Prije poziva provjerava konfigurirani račun kroz GET /v1/account; prihvaća samo sk_test_ ključ, test objekte i izričito uključenu local/testing konfiguraciju. Nema Stripe Connect konteksta niti proizvoljnog endpoint overridea.

HTTP POST /api/webhooks/stripe/sandbox provjerava izvorno tijelo i Stripe-Signature kroz službeni SDK, uključujući njegov petominutni vremenski prozor. Dodatno provjerava API verziju, test okolinu i nepostojanje Connect account/context polja. Događaj se zapisuje prije 200 odgovora, bez browser sesije i bez API poziva u HTTP prijamniku. Sirovi payload i kartični podaci nisu trajno spremljeni.

Obrada čita aktualni Session, PaymentIntent i capture preko autentificiranog API-ja. Ne koristi stari event payload kao financijsku potvrdu. Capture mora biti vezan uz PaymentIntent, u test okolini i s potvrđenim captured/paid poljima. Odstupanje ukupnih iznosa, valute, metapodataka ili stanja ide na provjeru, uz sačuvanu potvrđenu financijsku činjenicu.

Model, DTO i migracijska provjera namjerno prihvaćaju samo Stripe test scope; servis dodatno odbija okolinu izvan local/testing. Promjena env zastavice ne otvara live naplatu. Podrška za PayPal i više pokušaja po narudžbi zahtijeva zaseban dovršen životni ciklus.

Objekt procesora mora biti vezan uz pokušaj prije poravnanja. Webhook s lokalnim UUID-em u metapodacima ne smije sam uspostaviti vezu. Nepoznat ishod stvaranja oporavlja se isključivo istim zamrznutim zahtjevom i idempotency ključem unutar dopuštenog provider prozora; nakon toga treba usklađivanje.

Adapter postavlja konzervativan tehnički prozor ponavljanja stvaranja od 23 sata, prije Stripeova mogućeg uklanjanja V1 idempotency ključa nakon najmanje 24 sata. Nakon tog prozora ne šalje novi zahtjev i ne oslobađa rezervaciju: pokušaj dobiva stanje za usklađivanje. Ovo nije rok isteka narudžbe niti prihvaćena produkcijska politika rezervacija.

Migracija dodaje četiri tablice i ne prepisuje postojeće narudžbe/simulator. Uklanjanje sheme odbija se dok sadrži povijest. Eloquent štiti zamrznuta polja; to nije tvrdnja da privilegirani izravni SQL administrator ne može mijenjati bazu.

## Izvršne provjere ovog koraka

Na odvojenom MariaDB daemonu bez mreže prolazi 19 integracijskih slučajeva / 181 assertions za ovaj ledger. Cijeli postojeći konkurentni skript proširen je na 11 stvarnih višeprocesnih scenarija; oba nova scenarija prolaze: istodobni capture stvara jedan receipt/transakciju/outbox i jedno umanjenje, a capture nasuprot isteku čuva potvrđeni novac i pravilno stanje rezervacije.

Interni ledger 97167ba prošao je [CI na oba SQL enginea](https://github.com/eniofrimer/oneshop/actions/runs/41102822794): 230 PHP testova / 2.433 assertions i 11 konkurentnih scenarija po bazi, uz Pint, Larastan, audit, frontend i native provjere. [Docker image smoke](https://github.com/eniofrimer/oneshop/actions/runs/41102827724) također je zelen.

Novi SDK i admin skup lokalno prolazi 24 slučaja / 247 assertions. Koristi stvarni instalirani SDK i stvarni HMAC verifier, uz sintetički transport koji odbija svaki neočekivani poziv. Pokriva izgubljeni odgovor stvaranja, isti idempotency ključ/parametre nakon novog procesa, 23-satnu zabranu, događaj prije vezivanja, stari istek nakon naplate, API nedostupnost, pogrešan račun/potpis/okolinu/verziju, zasebnu valutu, API poziv pod SQL zaključavanjem i read-only/MFA granicu admina. Kasni event ostaje za retry i kada je pokušaj već ranije plaćen, ali aktualni API read zakaže.

Adapter 0c88dd8 ima zeleni [glavni CI](https://github.com/eniofrimer/oneshop/actions/runs/41106362216), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41106367141), [admin browser na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41106375744) i [fizičku obnovu na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41106371414). Recovery potvrđuje 77 tablica / 392 retka / osam datoteka po engineu: enkriptirani zahtjev/URL, sva stanja pokušaja, receipt/outbox replay, inbox deduplikaciju, identitete i odbijanje drugog aplikacijskog ključa. Četiri ZIP-a provjerena su objavljenim SHA-256 digestima i neovisno je pregledano svih osam izvještaja.

Puni lokalni skup s javnim tokom prolazi: 272 PHP testa / 3.040 assertions, Pint i Larastan. Konkurentni skup ima 14 prolaznih scenarija: dodane su istodobne Stripe predaje, Stripe/simulator za istu košaricu i Stripe/simulator za zadnji komad.

Zasebna lokalna QA kopija prošla je stvarni Chromium tok adresa → pregled → pripremljena narudžba na desktopu, mobilnoj emulaciji i bez JavaScripta. Prikazi prepared/unknown/paid, istek i potrebna provjera koriste izričito sintetičke ledger prijelaze, uz zabranu svakog Stripe mrežnog poziva. Prolazi 18 axe provjera i 23 provjere širine; nema JS grešaka, zahtjeva drugim domenama ni provider POST radnji. Admin prikazuje nepoznat ishod i zasebni USD receipt. Mobilna potvrda i admin detalj vizualno su pregledani. Ovo je provjera lokalnog UI-ja i fixture stanja, ne Stripe kartične forme.

Javni tok `d8f20e8` ima zelene [glavni CI](https://github.com/eniofrimer/oneshop/actions/runs/41110917001), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41110921024), [šest browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41110932294), [oba admin posla](https://github.com/eniofrimer/oneshop/actions/runs/41110928229) i [obnovu na oba enginea](https://github.com/eniofrimer/oneshop/actions/runs/41110924624). Četiri recovery/admin ZIP-a provjerena su digestima; svih osam izvještaja prolazi. Obnova ponovno potvrđuje 77 tablica / 392 retka / osam datoteka i 15 provjera očuvanja po engineu.

Revizija `17d7695` dodatno uklanja zastarjeli hosted URL kada je aktualna session dovršena, ali neplaćena. Regresija potvrđuje da nema ponovnog preusmjeravanja, financijske potvrde ni oslobađanja rezervacije. Fiksni testni ključevi zamijenjeni su privremenim fixture vrijednostima nakon [provjerenog lažnog alarma](SECRET_HANDLING.md). Prolaze [273 testa / 3.066 assertions i 14 konkurentnih scenarija po bazi](https://github.com/eniofrimer/oneshop/actions/runs/41114547994), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41114552069), Pint/Larastan te [provjera tajni](https://github.com/eniofrimer/oneshop/actions/runs/41114512374). Preview migracija sačuvala je svih 785 starih redaka u 72 tablice, migration zapise, env i URL; nove četiri tablice su prazne, a sandbox ostaje isključen. Vlastite potrošne QA baze, HTTP proces i testni daemon uklonjeni su nakon provjera.

## Konfiguracija i obrada

Zadano je ONESHOP_STRIPE_SANDBOX=false. Za namjerno testno uključivanje trebaju STRIPE_TEST_ACCOUNT_ID, STRIPE_TEST_SECRET_KEY i zaseban STRIPE_TEST_WEBHOOK_SECRET, postavljeni privatno u env. Webhook mora koristiti API 2026-09-30.endive i događaje checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded i checkout.session.async_payment_failed. Stvarni javni endpoint ili lokalni Stripe CLI forwarding još nisu postavljeni; mreža VM-a nije mijenjana.

Projektni schedule:run poziva oneshop:reconcile-stripe-sandbox svake minute samo kada je sandbox uključen. Native/cPanel/FastPanel koriste već dokumentirani cron, Docker svoj scheduler. Nije potreban zaseban daemon za webhook obradu. Command ima limit 20 redaka po inbox/attempt batchu (prihvaća 1–100), provjerava soft vremenski budžet od 45 sekundi prije svakog zapisa i koristi overlap lock. Aktivan API poziv može potrajati dulje od tog budžeta; konekcijski timeout je 5 s, pojedinačni zahtjev 15 s, automatski SDK retries su isključeni.

Nedostupni i nevezani događaji ostaju trajno primljeni i pomiču se po updated_at kako ne bi zauvijek blokirali novije događaje. Financijska provjera ne ovisi o redoslijedu dostave. Read-only /admin/payment-attempts prikazuje nepoznate ishode, odstupanja i stvarnu valutu financijske potvrde; ne nudi force-paid, brisanje, capture ili refund.

## Javni sandbox tok

Samo uz potpunu testnu konfiguraciju košarica nudi Stripe sandbox checkout na /checkout/stripe. Simulator ostaje zasebna opcija kada je izričito uključen. Stripe put radi i bez simulatora; oba dijele CheckoutDraft za adresu, dostavu, svježi pregled, zaključavanje kataloga i povijesnu snimku. Već predana košarica ne može se predati drugom načinu plaćanja.

POST predaje traži vlastitu testnu potvrdu i u jednoj SQL transakciji stvara novu stripe-test narudžbu, pokušaj i rezervaciju. Nema API poziva pod zaključavanjem niti prihvata iznosa/valute/računa iz requesta. Ponovljena predaja vraća isti pokušaj. Neispravna cijena, sadržaj, dostava ili zaliha vraćaju cijelu predaju, bez napola stvorene narudžbe.

GET /checkout/stripe/{UUID}/return je samo čitanje uz pripadnost gostujućoj sesiji, private/no-store i no-referrer. Ne prihvaća ?paid=1, status ili tuđi session_id kao potvrdu. Zasebni CSRF POST-ovi pokreću/nastavljaju isti hosted pokušaj, provjeravaju stanje ili traže provider istek. Launch smije preusmjeriti samo na provjereni HTTPS checkout.stripe.com URL. Zatvaranje stranice ili klik povratka ne oslobađaju rezervaciju.

Digitalni checkout i besplatna narudžba još nemaju provider tijek; ovaj Stripe EUR tok traži najmanje 50 centi. To nije opće pravilo da trgovac ne smije imati besplatne proizvode. Checkout koristi postojeći zamrznuti porez/dostavu, bez Stripe automatskog poreza, Adaptive Pricinga ili promo kodova koji bi promijenili taj iznos.

## Sljedeći dio

Slijedi stvarna sandbox provjera s merchant ključevima i povratnim putem. Najmanji vanjski preduvjet je vlasnikov Stripe test account ID, test secret key i pripadni webhook secret, privatno postavljen u projektni env. Stvarnu hosted kartičnu probu treba provesti ručno; Stripe dokumentira [ograničenja automatizacije svoje kartične forme](https://docs.stripe.com/automated-testing). Produkcijski rokovi, merchant porezi, refund politika i računi ostaju otvorene poslovne odluke; PayPal i live naplata nisu uključeni.

Relevantni zahtjevi procesora: [idempotency i najmanje 24 sata prije mogućeg uklanjanja ključa](https://docs.stripe.com/api/idempotent_requests), [potpis, duplikati i redoslijed webhookova](https://docs.stripe.com/webhooks), [istek otvorenog Checkout Sessiona](https://docs.stripe.com/api/checkout/sessions/expire). Stvarni adapter i prihvat moraju potvrditi navedeno ponašanje; samo čitanje dokumentacije nije integracijski dokaz.
