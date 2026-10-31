# Čisti native prihvat — 31. 10. 2026.

Aktualna aplikacijska revizija je `6a8426c`. Njen čisti fokusirani prihvat i potpuni baseline `5f59c31` odvojeno su opisani; raniji `95a9dc8` je povijesni dokaz.

## Završni paket 6a8426c i oporavak pregleda

| Provjera | Rezultat |
| --- | --- |
| Izvor | `6a8426c5dd32840014df504297cf314b6bfec34d`, [CI 41166816374](https://github.com/eniofrimer/oneshop/actions/runs/41166816374) |
| Artifact / ZIP SHA | `13029424086` / `eedb0bb10a9ee8bdafc7f07d0f5d5b9641e03064f00da8b1979976643b058235` |
| Tar | `oneshop-6a8426c5dd32.tar.gz`, 23.278.192 bajta |
| Tar SHA-256 | `004951228a49105c34b5d066b66f96e5094ef40b34a7cf938bb45cc8b4f86a9c` |
| Instalacija | 305 isporučenih Git datoteka i oba locka odgovaraju nepromjenjivom Git objektu; ista no-dev baza/HTTP izolacija i runtime profili kao niže. |
| Završni integritet | Svih **21.035** originalnih datoteka / **97.878.164** bajta identično je arhivu poslije stvarnih proba. |

Završna rubna provjera reproducirala je redirect loop: nakon gubitka zalihe GET pregleda vraćao je 302 na sebe. Uski zajednički HTTP recovery sada šalje kupca na košaricu za nedostupan artikl, odnosno na pripadajući demo/Stripe obrazac za adresu/dostavu. Briše samo stari quote; čuva košaricu i adresu. JSON ostaje 422, a HttpException guardovi se ne hvataju. Place/rezervacija/naplata pravila nisu mijenjana. Deset regresija pokriva oba načina, native CartException, sačuvanu količinu/`0` adresni dodatak i JSON bez HTML redirecta.

Obje baze prolaze **334 testa / 3.713 assertions**, Pint, Larastan, audit, 14 konkurentnih scenarija i native provjere. [Javni browser](https://github.com/eniofrimer/oneshop/actions/runs/41166820449), [admin/MFA](https://github.com/eniofrimer/oneshop/actions/runs/41166822444), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41166818094), [obnova](https://github.com/eniofrimer/oneshop/actions/runs/41166824245) i tajne su zeleni. Deset CI ZIP-ova ima provjeren digest/CRC: 340 JSON izvještaja i 298 axe provjera bez kršenja; 84 neodlučena rezultata ostaju ograničenje.

Nova čista instalacija izvršno prolazi devet kupnji u Chromiumu/Firefoxu/WebKitu te devet dodatnih stale-stock/shipping/price scenarija na desktopu, 390 px i bez JavaScripta. Tih devet odbijanja ne stvara niti mijenja narudžbe, stavke, transakcije ili rezervacije; uspoređeni su hashovi tih četiriju tablica. Unosi ostaju sačuvani, cijena zahtijeva novi quote/pregled, JS sažetak dobiva fokus, nema prelijevanja, JS grešaka ili vanjskih zahtjeva. Zajedno su to 57 native axe provjera bez prijavljenih kršenja (51 kupovni tok + šest recovery prikaza).

Cached production, stvarni scheduled database queue, javni original prije konverzija i četiri GD WebP konverzije ponovno prolaze. Demo i oba Stripe ulaza/pregleda daju 404 u production, uključujući `/checkout/stripe/pregled`; config/env su vraćeni i vlastiti worker ponovno pokrenut.

Ovaj posljednji paket ima fokusiran novi prihvat. Potpuni native MFA/private-file/admin/500-proizvodni/4096 px dokaz niže pripada `5f59c31`. Izravna usporedba arhiva potvrđuje da je svih **20.621 vendor package datoteka**, ostali app/auth/config/routes i frontend identično baselineu. Razlikuju se samo dva checkout controllera, novi recovery helper, release metadata i dvije generirane Composer classmap datoteke. Aktualni CI zasebno ponovno izvršava obični MFA i ostale admin tokove na obje baze. To je kombinacija novih izvršnih dokaza i precizne ekvivalencije, ne tvrdnja da je svaki nepromijenjeni native tok ponovno odigran.

Objava 31. 10. u **05:14 Europe/Zagreb** zadržala je svih **951 redak / 77 tablica**, isti env/račun/URL i tri frontend datoteke iz tog točnog arhiva. Main [CI](https://github.com/eniofrimer/oneshop/actions/runs/41167763867), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41167763829) i [tajne](https://github.com/eniofrimer/oneshop/actions/runs/41167763825) su zeleni. Privatni snapshot `20261031-041443` stvarno je SQL-importan na zasebnom QA daemonu kroz schema-only korisnika: svih 77 tablica / 951 redak ima identične hashove, a čitanje druge baze je odbijeno. Env nije učitan, obnovljena aplikacija/poslovi nisu pokrenuti i nema vanjskih zahtjeva; privremena baza/korisnik uklonjeni su. Media arhiv ima samo `.gitignore` (15 bajtova), bez uploadanog sadržaja. Nema tvrdnje o key dekripciji, popunjenom media oporavku ili offsite RPO/RTO.

Nakon prihvata uklonjeni su vlastiti privremeni native root, SQL korisnik/baza, QA lozinke i odvojeni testni daemon. Trajni preview DB/HTTP/worker/timer ostali su aktivni. Kasniji docs-only commit ne mijenja ovu funkcionalnu reviziju ni native artefakt.

## Potpuni native baseline 5f59c31

| Provjera | Rezultat |
| --- | --- |
| Puni izvor | `5f59c31320a2a1656aa488b54685149cb12a2820` |
| Izvorni CI | [41161953822](https://github.com/eniofrimer/oneshop/actions/runs/41161953822), native artifact `13027537628` |
| ZIP SHA-256 | `e7fe6d3bcce9addcc14496236c2b8811d18ab4d34c62877bcf25eae6b9fa963b` |
| Tar | `oneshop-5f59c31320a2.tar.gz`, 23.277.491 bajt |
| Tar SHA-256 | `92477bf1c48427cf57e57571e33aa6fc8dee4db1b97bb2180a4c34af795d4215` |
| Git / lockovi | `release.json` i oba lock hasha odgovaraju izvoru; 304 isporučene Git datoteke identične su nepromjenjivom Git objektu. |
| Nakon svih proba | Svih **21.034** originalnih datoteka / **97.876.285** bajtova ostalo je identično arhivu, uključujući vendor, source i frontend. |

Nova čista VM instalacija ima vlastiti ključ, SQL bazu/korisnika i loopback HTTP listener. Izvršno je potvrđen PID, direktorij, PHP naredba i vlasništvo listenera prije browser zapisa. Runtime je PHP 8.5.12 / MariaDB, s database cache/sessions/jobs, bez Redis/PDO-PG ekstenzija, Horizon ili Faker paketa. Oba native CLI profila, stvarni worker i queue/shared-cache probe prolaze. Nijedna programska datoteka nije zakrpana radi prihvata.

Ponovljeni su svi dolje opisani kupovni i admin tokovi: devet javnih kupnji kroz tri enginea, tri sesijske povijesti i zasebna admin kupnja; košarica/tipkovnica u sva tri enginea; fotografije SKU-ova, zalihe, PDF, privatne datoteke, kategorije i dostavna tarifa te obični MFA na dvije širine. Native skup ima **109 axe provjera bez kršenja**, uz 42 automatski neodlučena rezultata (26 kontrast i 16 ARIA vrijednosti). Imenovanje galerije sada je ispravljeno; ovo nije potpuna WCAG potvrda. Izvor ima i [šest zelenih javnih browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41161958704), [admin/MFA na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41161960286), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41161956440), [obnovu na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41161962004) i skeniranje tajni. Obje baze prolaze 324 testa / 3.575 assertions. Deset preuzetih CI ZIP-ova ima provjerene digest/CRC vrijednosti: 340 JSON izvještaja, 298 axe provjera bez kršenja, 84 neodlučena rezultata (52 kontrast i 32 ARIA vrijednosti).

Cached production profil ponovno potvrđuje isključen demo/povijest/Stripe, bez obzira na demo flag, i stvarni scheduler bez stalnog workera. WebP 1200 px daje četiri stvarne GD konverzije; queue/failed-jobs su prazni i svi HTTP asseti odgovaraju.

Dodatna granična proba koristi vlastiti sintetički dvobojni PNG **4096 × 4096**, 64.508 bajtova. Pri 512M PHP memorije `schedule:run` stvarno izvršava conversion job i zasebni probe: **5,65 s**, maksimalni RSS procesa/potomaka **205.344 KiB** (oko 201 MiB), exit 0. Sve četiri PNG konverzije imaju očekivane dimenzije i valjan HTTP sadržaj, bez failed joba. Original služi dok konverzije čekaju. Ovo provjerava jednu graničnu dimenziju; nije tvrdnja o najgorem 5 MB sadržaju, web PHP-u ili hosting SLA-u. Env je vraćen byte-for-byte, cache očišćen i vlastiti worker ponovno pokrenut.

### Referentni katalog i backend

U istom netaknutom paketu postojeći autorizirani editori stvaraju 25, zatim 500 sintetičkih proizvoda, bez factories. Zagrijana stranica od 12 kartica koristi **22 SQL upita** za oba opsega (izvorni baseline prije batch promjene: 99). Filtriranje daje 500 ukupnih / 400 raspoloživih proizvoda; točni su druga stranica, zadnja stranica 42 s osam kartica i jedinstveni SKU 500. Profili su tri uzorka stvarnog HTTP kernela iz CLI-ja, bez mrežne latencije; nisu prihvat trgovčeva još neodabranog referentnog kataloga.

Chromium na 1400/390 px prolazi još deset axe faza kroz stranice 1/42/100, raspoloživu stranicu 34 i imenovanu galeriju, bez kršenja, prelijevanja, JS grešaka, vanjskih/upisnih zahtjeva ili novih narudžbi. Proizvodi bez slike koriste imenovani lokalni SVG. Zasebni QA admin nalazi 500. SKU, filtrira raspoloživost, vidi 0 / 0 / 0 i njegov izostanak u raspoloživom filtru; prolaze još četiri axe provjere na 1440/390 px. Hashovi šest uspoređenih tablica (proizvodi, SKU-ovi, cijene, mediji, narudžbe i rezervacije) ostaju identični prije/poslije tih read-only admin radnji. Završna usporedba svih 21.034 originalnih datoteka ponovljena je nakon tih proba i 4096 px posla.

### Objava i stvarna obnova privatne kopije

Objava `5f59c31` 31. 10. u 04:16 Europe/Zagreb sačuvala je svih **939 redaka / 77 tablica**, env, račun i stalni URL. Nova privatna snimka ima provjerene dozvole, veličine, SHA i gzip. Tri frontend datoteke identične su testiranom native arhivu. U odnosu na prethodnu objavu mijenja se samo sesijska tablica (10 → 28); svih drugih 76 hashova ostaje jednako. Main [CI](https://github.com/eniofrimer/oneshop/actions/runs/41163633358), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41163633302) i [tajne](https://github.com/eniofrimer/oneshop/actions/runs/41163633333) su zeleni.

Izvedena je stvarna obnova privatnih SQL kopija u novoj potrošnoj bazi na fizički odvojenom QA daemonu, kroz korisnika ograničenog na taj schema. Negativni SELECT prema drugoj testnoj bazi je odbijen. Kopija prije prethodne objave čuva svih 77 tablica / 921 redak. Obnovljena aplikacija nije pokretana, instalacijski env nije učitan, obnovljeni poslovi nisu izvršavani i nema vanjskih zahtjeva. Privremeni schema i korisnik su uklonjeni. Javni media arhiv stvarno je izdvojen i uspoređen, ali sadrži samo `.gitignore` (15 bajtova), bez uploadanih slika; privatnog media arhiva nema. To nije dokaz offsite, punog key/application recoveryja ili popunjenog media oporavka. I najnovija kopija iz objave `5f59c31` stvarno je obnovljena: **77 tablica / 939 redaka**, svi hashovi identični, schema-only importer i odbijen SELECT prema drugoj bazi. Privremeni schema i korisnik su uklonjeni. Pripadajući env ima provjeren checksum, ali nije učitan; javni media arhiv ponovno ima samo `.gitignore`, bez uploadanog sadržaja.

Windows pristup, stvarni cPanel/FastPanel račun/FPM/TLS/cron/mail i offsite RPO/RTO ostaju zasebni prihvati. Stvarna naplata, porezni identitet trgovca, računi i slanje paketa nisu ovime uključeni. [Deploy postupak](DEPLOYMENT.md), [ručna proba](NIGHT_DEMO_20261030.md), [aktualno stanje](STATUS.md).

## Raniji čisti paket 95a9dc8

Ovaj zapis pripada reviziji `95a9dc895c501a548d60a908696f41aaff8483ee`. Novije lokalne zakrpe i aktualni preview uvijek su odvojeno označeni u [STATUS-u](STATUS.md). Prihvat koristi vlastitu potrošnu VM instalaciju i fizički odvojeni MariaDB daemon; ne koristi korisnikove preview narudžbe ili račun.

### Podrijetlo i integritet

| Provjera | Rezultat |
| --- | --- |
| Izvor | [CI 41159408466](https://github.com/eniofrimer/oneshop/actions/runs/41159408466), native artifact `13026826462` |
| GitHub ZIP digest | `386e68e37abeaa8ff956b8902600eaab46a56afe22f787e7ba0b67d96c2975d8` |
| Arhiv | `oneshop-95a9dc895c50.tar.gz`, 23.275.220 bajtova |
| Tar SHA-256 | `7e567547ce7fc43e2252135391d4f3bb9227746c23e4ed748589a687e7d64a98` |
| Izvor i lockovi | Puni revision u `release.json`, oba lock hasha i 303 isporučene Git datoteke odgovaraju nepromjenjivom Git objektu. |
| Isključeno | Instalacijski `.env`, testovi, Node modules, Horizon i Faker nisu u paketu. |
| Nakon prihvata | Svih 21.033 originalnih regularnih datoteka / 97.866.128 bajtova, uključujući vendor i source, ostalo je identično arhivu. |

Provjerene su GitHub SHA vrijednosti, ZIP CRC, tar `.sha256`, metadata i pojedinačni sadržaji. Nije se uspoređivao samo trenutačni promjenjivi checkout. Nova instalacijska konfiguracija i generirani storage/cache nisu originalne datoteke arhiva.

### Stvarni runtime i kupnje

PHP 8.5.12 ima `pdo_mysql`, bez Redis/PDO-PG ekstenzija, Horizon ili Faker paketa. Odabrani su database cache, šifrirane database sessions i database queue. Oba CLI profila `cpanel` i `fastpanel`, stvarni odvojeni worker i queue/shared-cache probe prolaze.

- Chromium, Firefox i Linux WebKit svaki prolaze desktop, uski prikaz i kupnju bez JavaScripta: devet testnih kupnji.
- Sva tri prolaze neovisno mijenjanje varijanti u košarici i navigaciju tipkovnicom; ti tokovi ne predaju narudžbu, a keyboard proba dodatno blokira završne upisne zahtjeve.
- Chromium prolazi tri sesijske povijesti: čekanje → odbijanje → ponovni pokušaj → potvrda → nova košarica. Tuđa sesija dobiva 404 za potvrdu.
- Admin kroz vidljive kontrole stvara vlastiti varijabilni proizvod, dva SKU-a i dvije fotografije; mijenja naslovnicu i SKU fotografije, kupuje kao zaseban gost, odbija uređivanje rezervirane/zastarjele zalihe te preuzima PDF. Zalihe prije/poslije iste kupnje su 10 / 1 / 9 i 9 / 0 / 9.
- Privatni upload/download/arhiva/restore, ciklus kategorija i zastarjela dostavna tarifa prolaze u zasebnim admin tokovima.
- Običan administrator prolazi deset MFA faza na 1280/390 px, uključujući upis, TOTP/replay, recovery jednim klikom uz latenciju, raniji pogrešni OTP i ponovno korištenje koda. Izvorni rate limit i cooldown ostaju uključeni. QR, ključevi i kodovi nisu spremljeni u dokaze.

Ukupno je 109 axe provjera bez prijavljenih kršenja. Preostalih 48 automatski neodlučenih rezultata čine 26 `color-contrast`, šest `aria-prohibited-attr` i 16 `aria-valid-attr-value`; to nije potpuna WCAG potvrda niti fizička mobilna/čitačka proba. Imenovanje javne galerije i proizvodi bez slike dodatno se rješavaju u kasnijem inkrementu.

### Cached production i stvarni cron posao

Na istoj čistoj instalaciji privremeno su cacheani production config/routes/views, uz sintetički HTTPS URL, secure cookie postavku i namjerno uključen demo flag. Simulator, sesijska demo povijest i Stripe sandbox svejedno su isključeni. Stvarni loopback HTTP daje 200 za naslovnicu/health/login, preusmjerava zaštićene admin ekrane na isti login i vraća 404 za stari login, Horizon i checkout putove. Ovo ne dokazuje HTTPS transport.

Za cron prihvat zaustavljen je samo vlastiti QA worker. Postojeći autorizirani editori dodaju novu sintetičku fotografiju u nacrt. Konverzije zaista ostaju u database queueu, a prikaz dotad koristi javni original. Stvarni `schedule:run` izvršava zasebni worker i proizvodi WebP datoteke:

| Konverzija | Dimenzije | Bajtovi |
| --- | --- | --- |
| small | 300 × 300 | 6.458 |
| medium | 500 × 500 | 8.134 |
| large | 800 × 800 | 16.600 |
| zoom | 500 × 500 | 8.134 |

Provjereni su stvarni GD sadržaj/dimenzije, disk/putanja, generirane oznake, responsive srcset i HTTP WebP odgovor za svaku datoteku. Queue i failed-jobs ostaju prazni. Zasebni queue/shared-cache probe također prolazi kroz isti scheduler. Originalni env vraćen je byte-for-byte, cache očišćen i vlastiti worker ponovno pokrenut.

Dugi PDF iz sintetičke rollback transakcije ima 12 stavki, četiri A4 stranice i 882.466 bajtova. Poppler nalazi nula okvira izvan stranice, svih 3.095 Č i 1.281 Ž, konačnih 287 EUR i napomenu da nije račun. Izvorni QA redci zadržani su nakon rollbacka.

### Objava i granice

Nakon svih šest zelenih workflowa isti izvor objavljen je na main/preview uz privatnu snimku i usporedbu svih tablica. Sačuvan je 921 redak / 77 tablica, env i stalni URL; tri frontend datoteke preuzete su iz upravo testiranog arhiva. Snimka ima provjerene dozvole, veličine, SHA i gzip, ali nije vraćena niti kopirana offsite.

Poslije ovog prihvata QA je izričito preimenovan u zakrpanu okolinu za probu 500 sintetičkih proizvoda. Ta kasnija proba nije nova čista instalacija ovog arhiva. Privremene lozinke i SQL ovlasti pripadaju samo potrošnom QA-u, ne korisnikovu računu.

Docker je izvršno provjeren u CI-u. Stvarni cPanel/FastPanel račun, web PHP/FPM, domena/TLS, panel cron, mail, offsite kopije i RPO/RTO i dalje traže prihvat ciljnog hosta. Stvarna naplata, porezni identitet trgovca, računi i slanje paketa nisu potvrđeni ovim tehničkim dokazom. [Postupak deploya](DEPLOYMENT.md), [ručna proba](NIGHT_DEMO_20261030.md).
