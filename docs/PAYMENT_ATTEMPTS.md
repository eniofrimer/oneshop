# Trajni pokušaji naplate i Stripe sandbox adapter

Datum: 30. 10. 2026. Uvedeni su lokalni ledger, Stripe SDK adapter, potpisani webhook inbox, scheduler obrada i read-only administracija. Nisu uključeni javni provider checkout, refund servis ili produkcijska naplata. Stvarna Stripe sandbox kartica/račun još nisu provjereni; preview ostaje na jasno označenom simulatoru.

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

Recovery fixture sada uključuje enkriptirani hosted URL/zahtjev, paid/unknown/expired pokušaje, receipt/outbox i inbox. Nakon fizičke obnove mora dokazati dekripciju izvornim ključem, odbijanje drugog ključa, ponovljivo poravnanje starog holda, deduplikaciju događaja i napredovanje receipt identiteta. Izvršni rezultat novog recovery koraka još se čeka; nije unaprijed označen prolaznim.

Puni lokalni skup s adapterom prolazi: 254 PHP testa / 2.680 assertions, Pint i Larastan. CI/Docker/recovery poslovi slijede na novom commitu. Nijedna od ovih sintetičkih provjera nije prihvat stvarnog Stripe računa ili hosted kartične forme.

## Konfiguracija i obrada

Zadano je ONESHOP_STRIPE_SANDBOX=false. Za namjerno testno uključivanje trebaju STRIPE_TEST_ACCOUNT_ID, STRIPE_TEST_SECRET_KEY i zaseban STRIPE_TEST_WEBHOOK_SECRET, postavljeni privatno u env. Webhook mora koristiti API 2026-09-30.endive i događaje checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded i checkout.session.async_payment_failed. Stvarni javni endpoint ili lokalni Stripe CLI forwarding još nisu postavljeni; mreža VM-a nije mijenjana.

Projektni schedule:run poziva oneshop:reconcile-stripe-sandbox svake minute samo kada je sandbox uključen. Native/cPanel/FastPanel koriste već dokumentirani cron, Docker svoj scheduler. Nije potreban zaseban daemon za webhook obradu. Command ima limit 20 redaka po inbox/attempt batchu (prihvaća 1–100), provjerava soft vremenski budžet od 45 sekundi prije svakog zapisa i koristi overlap lock. Aktivan API poziv može potrajati dulje od tog budžeta; konekcijski timeout je 5 s, pojedinačni zahtjev 15 s, automatski SDK retries su isključeni.

Nedostupni i nevezani događaji ostaju trajno primljeni i pomiču se po updated_at kako ne bi zauvijek blokirali novije događaje. Financijska provjera ne ovisi o redoslijedu dostave. Read-only /admin/payment-attempts prikazuje nepoznate ishode, odstupanja i stvarnu valutu financijske potvrde; ne nudi force-paid, brisanje, capture ili refund.

## Sljedeći dio

Javni Stripe sandbox checkout mora koristiti novu narudžbu, postojeću provjeru pregleda i zamrznuti server iznos. Zatim slijedi stvarna sandbox provjera s merchant ključevima i povratnim putem. Produkcijski rokovi, merchant porezi, refund politika i računi ostaju otvorene poslovne odluke; PayPal i live naplata nisu uključeni.

Relevantni zahtjevi procesora: [idempotency i najmanje 24 sata prije mogućeg uklanjanja ključa](https://docs.stripe.com/api/idempotent_requests), [potpis, duplikati i redoslijed webhookova](https://docs.stripe.com/webhooks), [istek otvorenog Checkout Sessiona](https://docs.stripe.com/api/checkout/sessions/expire). Stvarni adapter i prihvat moraju potvrditi navedeno ponašanje; samo čitanje dokumentacije nije integracijski dokaz.
