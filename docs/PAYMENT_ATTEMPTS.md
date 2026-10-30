# Trajni pokušaji naplate — prvi interni korak

Datum: 30. 10. 2026. Ovaj korak uvodi lokalni model i provjere. Ne uključuje Stripe API pozive, webhook HTTP rutu, javni provider checkout, refund servis ni produkcijsku naplatu. Trenutačni preview i dalje koristi jasno označeni simulator.

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

ProviderCheckoutSnapshot, CapturedPayment i VerifiedProviderEvent su interni tipovi za budući adapter. Njihovo ime samo po sebi ne provjerava Stripe potpis ili račun: adapter mora provesti autentikaciju, provjeru scopea i svježe API čitanje prije poziva. Ne smiju se konstruirati iz polja korisničkog zahtjeva. U ovom koraku nema HTTP rute do tih servisa.

Model, DTO i migracijska provjera namjerno prihvaćaju samo Stripe test scope; servis dodatno odbija okolinu izvan local/testing. Promjena env zastavice ne otvara live naplatu. Podrška za PayPal i više pokušaja po narudžbi zahtijeva zaseban dovršen životni ciklus.

Objekt procesora mora biti vezan uz pokušaj prije poravnanja. Webhook s lokalnim UUID-em u metapodacima ne smije sam uspostaviti vezu. Nepoznat ishod stvaranja oporavlja se isključivo istim zamrznutim zahtjevom i idempotency ključem unutar dopuštenog provider prozora; nakon toga treba usklađivanje.

Migracija dodaje četiri tablice i ne prepisuje postojeće narudžbe/simulator. Uklanjanje sheme odbija se dok sadrži povijest. Eloquent štiti zamrznuta polja; to nije tvrdnja da privilegirani izravni SQL administrator ne može mijenjati bazu.

## Izvršne provjere ovog koraka

Na odvojenom MariaDB daemonu bez mreže prolazi 19 integracijskih slučajeva / 181 assertions za ovaj ledger. Cijeli postojeći konkurentni skript proširen je na 11 stvarnih višeprocesnih scenarija; oba nova scenarija prolaze: istodobni capture stvara jedan receipt/transakciju/outbox i jedno umanjenje, a capture nasuprot isteku čuva potvrđeni novac i pravilno stanje rezervacije.

Puni lokalni skup prolazi: 230 PHP testova / 2.433 assertions, uz Pint i Larastan. To su sintetičke autentificirane provider snimke, bez Stripe mrežnih poziva ili stvarne testne kartice. Puni CI i PostgreSQL provjera slijede na objavljenom commitu. Dokazi i status ažurirat će se prema stvarnom ishodu.

## Sljedeći dio

Stripe sandbox adapter s provjerom konfiguriranog računa, originalnog webhook tijela/potpisa, test okoline i API verzije; svježe čitanje Checkout Sessiona / PaymentIntenta / capturea; sigurno ponavljanje stvaranja i inbox replay; čitljiv pregled pokušaja. Javni tok treba koristiti novu narudžbu i zamrznuti server iznos. Produkcijski rokovi, merchant porezi, refund politika i računi ostaju otvorene poslovne odluke.

Relevantni zahtjevi procesora: [idempotency i najmanje 24 sata prije mogućeg uklanjanja ključa](https://docs.stripe.com/api/idempotent_requests), [potpis, duplikati i redoslijed webhookova](https://docs.stripe.com/webhooks), [istek otvorenog Checkout Sessiona](https://docs.stripe.com/api/checkout/sessions/expire). Stvarni adapter i prihvat moraju potvrditi navedeno ponašanje; samo čitanje dokumentacije nije integracijski dokaz.
