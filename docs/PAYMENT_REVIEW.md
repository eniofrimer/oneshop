# Pregled naplate i konkurentnih promjena

Datum: 30. 10. 2026. Početna revizija: 432769b. Korisnik je pokrenuo nastavak s Astrom nakon noćnog razvojnog MVP-a.

Ovaj pregled zatvara konkretne greške postojećeg checkouta i određuje tehničke uvjete sljedećeg koraka. Stvarna naplata, produkcijske rezervacije i kupčeva digitalna prava još nisu implementirani. Poslovne odluke iz [jutarnje mape](MORNING_REVIEW.md) ostaju otvorene.

## Dokazani nalazi i ispravci

| Nalaz | Reprodukcija prije ispravka | Ispravak |
| --- | --- | --- |
| Promijenjeni detalji kupnje prihvaćeni uz isti iznos | Pet HTTP regresija promijeni naziv proizvoda, SKU, vrijednost opcije, naziv ili opis dostave nakon pregleda. Svih pet prije ispravka stvara narudžbu umjesto traženja novog pregleda. | HMAC pregleda sadrži strukturirane, sortirane stavke, nazive/opcije, SKU, količine i pojedinačne iznose, odabranu dostavu i adresu. Sam nepromijenjeni ukupni iznos više nije dovoljan. |
| Stara cijena nakon čekanja na redak u MariaDB-u | Zaseban proces čeka na zaključanom proizvodu dok drugi proces mijenja cijenu i potvrđuje transakciju. Checkout nakon čekanja prihvaća stari pregled zbog ranije uspostavljene REPEATABLE READ snimke. | Sve podržane OneShop SQL veze izričito koriste READ COMMITTED. Zaključavanja ostaju uključena. Postavka je na aplikacijskoj sesiji, bez globalne promjene poslužitelja. Runtime provjera čita stvarnu izolaciju veze. |
| Nezaključana vrijednost opcije | Uređivač zajedničke opcije ne zaključava proizvod. Samo zaključavanje proizvoda/varijante ne štiti njen opis kroz stvaranje narudžbe. | Predaja zaključava i korištene vrijednosti opcija po ID-u do završetka validacije i stvaranja povijesnih stavaka. Konkurentna promjena oznake traži novi pregled. |

READ COMMITTED koristi novu snimku za svako obično čitanje. Zaključani retci i zajednički redoslijed zaključavanja i dalje su potrebni za zaštitu od promjena tijekom obrade. Ovo nije zamjena za rezervacije kod vanjskog procesora. [MariaDB dokumentacija izolacije](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/set-commands/set-transaction).

Stari nepotvrđeni pregled nakon objave ovog ispravka traži ponovno otvaranje pregleda. Već predane narudžbe, njihove snimke i testne naplate ostaju sačuvane.

## Izvršne provjere

[Konkurentni test](../tests/Concurrency/checkout.php) koristi zasebne PHP procese i stvarne SQL veze. Roditeljski proces drži točno određeni redak; djeca signaliziraju dolazak do zaključavanja, a zatim se provjerava da nisu završila prije otpuštanja. Provjere ne ovise o slučajnom preklapanju dvaju brzih zahtjeva.

| Scenarij | Očekivani rezultat |
| --- | --- |
| Dvije istodobne predaje iste košarice | Jedna narudžba; oba poziva vraćaju isti checkout. |
| Dvije istodobne potvrde istog checkouta | Jedna uspješna transakcija, zaliha umanjena jednom. |
| Dvije narudžbe za zadnji komad | Jedna uspije, druga ima payment validacijsku grešku; zaliha je nula. |
| Cijena izmijenjena dok predaja čeka proizvod | Novi pregled obavezan; narudžba nije stvorena. |
| Oznaka opcije izmijenjena dok predaja čeka vrijednost | Novi pregled obavezan; narudžba nije stvorena. |

Skripta zahtijeva APP_ENV=testing, bazu oneshop_test, konfiguraciju bez cachea, izričitu zastavicu ONESHOP_CHECKOUT_CONCURRENCY=1 i poznatu izoliranu Compose uslugu ili vlastiti /tmp testni socket. Za socket dodatno provjerava datadir i isključenu mrežu. Odbija postojeći katalog/košarice/narudžbe. Sama ne radi reset baze. Testni procesi mijenjaju isključivo sintetičke podatke. Skripta nije dio native releasea.

Glavni CI pokreće provjere nakon PHP testova na PostgreSQL-u i MariaDB-u. Lokalno je pripremljen zaseban MariaDB daemon bez mreže; korisnikov preview socket nije korišten. To je dokaz konkurentnog rada servisa/baze, a ne provjera istodobnih browser sesijskih upisa ili vanjskog procesora.

[HTTP i integracijske regresije](../tests/Feature/DevelopmentCheckoutTest.php) dodatno pokrivaju pet promjena detalja, rollback zalihe kada zapis transakcije zakaže, siguran ponovni pokušaj i košaricu u kojoj je samo jedna stavka naknadno ostala bez zalihe. Postojeće provjere čuvaju tuđu sesiju, CSRF tok u stvarnom pregledniku, produkcijsku zabranu simulatora i nepromijenjene administratorske MFA granice.

## Tehnička osnova produkcijske naplate — za implementaciju

Sljedeći model je prijedlog tehničke izvedbe, ne tvrdnja da je već ugrađen:

1. **Neovisna narudžba i pokušaj plaćanja.** Narudžba čuva prihvaćene stavke/adrese/poreze/iznose. Pokušaj ima vlastiti nepredvidivi ID, procesora, račun trgovca, test/live okolinu, očekivani iznos/valutu, stabilni idempotency ključ i jedinstvenu vezu na objekt procesora. Ponovni HTTP poziv ne stvara novu naplatu. Novi pokušaj ne pokreće se dok je prethodni ishod nepoznat.
2. **Rezervacija prije odlaska procesoru.** Pod zaključavanjem svih SKU-ova u stabilnom redoslijedu provjeriti dostupnu količinu i zapisati rezervacije. Administratorska promjena zalihe mora poštovati aktivne rezervacije. Dostupnost u katalogu/košarici i svi drugi potrošači zalihe moraju koristiti isti izračun. Rezervacija bez tih integracija ne smije se uključiti.
3. **Nepoznat ishod je zasebno stanje.** Timeout API poziva, zatvoren preglednik ili lokalni istek nisu dokaz odbijene naplate. Rezervacija ostaje zadržana dok se stanje procesora ne razriješi. Oporavak koristi isti idempotency ključ i provjeru postojećeg objekta; ne šalje novu naplatu naslijepo. Trajanje rezervacije i operativni rokovi zahtijevaju dogovor.
4. **Trajni prijam potvrđenih događaja.** Provjeriti izvor/potpis, račun, okolinu i vezu s lokalnim pokušajem, zatim trajno zabilježiti događaj pod jedinstvenim ključem procesora/računa/događaja. Obrada mora biti ponovljiva i neovisna o redoslijedu dostave. Ista financijska radnja pod drugim event ID-em također ne smije napraviti drugi capture.
5. **Poravnanje u jednoj lokalnoj transakciji.** Zaključati narudžbu/pokušaj, provjeriti potvrđeni iznos i valutu, pretvoriti rezervaciju u jednom umanjenu zalihu te jednom zapisati financijsku radnju. U istoj transakciji zapisati outbox zadatke za dokument/isporuku/prava. Vanjske API pozive ne držati unutar SQL zaključavanja.
6. **Zakašnjela naplata i iznimke.** Financijska potvrda mora se zabilježiti i kada se zaliha više ne može sigurno dodijeliti. Takav slučaj ide u vidljivo stanje za usklađivanje; ne izmišlja zalihu, ne proglašava paket poslanim i ne tretira naplatu kao da se nije dogodila.
7. **Povrati su odvojene radnje.** Jedinstvena vanjska referenca i zaključavanje ukupno vraćenog iznosa sprečavaju višestruki povrat. Povrat novca sam po sebi ne dokazuje povrat robe ni pravo automatskog vraćanja zalihe. Digitalna prava imaju vlastita pravila dodjele/revokacije.

Stripe dokumentira moguće duplikate i neuređeni dolazak događaja; provjera koristi izvorno tijelo i potpis. To određuje zahtjeve prijamnika, ali samo čitanje dokumentacije nije prihvat stvarne integracije. [Stripe webhooks](https://docs.stripe.com/webhooks).

PayPal veže provjeru uz konfigurirani webhook ID i izvornu poruku. Njegov simulator ne zamjenjuje provjeru stvarne sandbox naplate; mock događaji imaju zasebna ograničenja provjere. [PayPal webhook verification](https://developer.paypal.com/api/rest/webhooks/rest/).

## Obavezni scenariji prije uključivanja vanjskog procesora

- Dvije rezervacije zadnjeg komada; admin pokušava spustiti zalihu ispod rezervirane; dvije košarice zaključavaju više SKU-ova suprotnim redoslijedom.
- Pad nakon lokalne namjere, nakon prihvata API poziva i nakon potvrđene naplate prije lokalnog commita.
- Duplicirani event ID, različiti događaji za isti capture, zakašnjeli ili obrnuti redoslijed, pogrešan potpis/račun/okolina/iznos/valuta.
- Istek u utrci s potvrdom; potvrđeno otkazivanje nasuprot nepoznatom stanju; naplata nakon već razriješene rezervacije.
- Ponovljeni/djelomični povrat; nedostupan dokumentni servis; siguran replay outboxa bez drugog računa, isporuke ili digitalnog prava.
- Odvojeni kupčev guard i sesija; kupac ne dobiva administratorski pristup; gost ne čita tuđu narudžbu poznavanjem njenog ID-a.

Prvi stvarni adapter zahtijeva odabran redoslijed Stripe/PayPal integracije, sandbox račun/ključeve i prihvaćen povratni URL. Pravila virmana, poreza/računa, rezervacijskih rokova, povrata i dostave nisu automatski preuzeta iz demo podataka.


## Potvrđeni rezultat za 644484c

[Glavni CI 41067565695](https://github.com/eniofrimer/oneshop/actions/runs/41067565695) prolazi na PostgreSQL-u i MariaDB-u: 197 PHP testova / 2.058 assertions te svih pet dodatnih konkurentnih scenarija na svakoj bazi. Pint, Larastan, audit, build, native runtime/scheduler i release provjere su zeleni. [Docker smoke 41067565687](https://github.com/eniofrimer/oneshop/actions/runs/41067565687) također prolazi.

[Browser 41067564459](https://github.com/eniofrimer/oneshop/actions/runs/41067564459) prolazi svih šest tema/engine kombinacija, a [admin 41067568896](https://github.com/eniofrimer/oneshop/actions/runs/41067568896) oba SQL enginea. Osam ZIP-ova potvrđeno je SHA-256 digestima; svih 30 izvještaja provjereno je neovisno: 198 axe provjera, 18 simuliranih kupnji, 12 dodatnih keyboard putanja bez narudžbe i šest prolaznih admin izvještaja. Privatni sažetak ostaje u ignoriranom storage/app/private/runtime/astra-644484c/verification.json. Nije provedena nova čista instalacija native paketa na stvarnom hosting panelu.

Korisnikov preview zadržava postojeći env, proizvode, vlasnika i stalni URL. Runtime potvrđuje READ COMMITTED; queue worker uredno je završio stari proces i obnovljen je kroz postojeći projektni servis. Privremeni odvojeni testni daemon/direktorij uklonjeni su nakon provjere vlastitog markera, UID-a i točnih putanja. Windows preglednik nije neovisno provjeren.
