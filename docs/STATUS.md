# OneShop — provjereno razvojno stanje

Ažurirano: 31. 10. 2026. tijekom noći, Europe/Zagreb. Ovo je ogledni primjerak za ručne testne narudžbe.

## Otvaranje i prva kupnja

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Admin: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Povijest iste sesije: [Moje narudžbe](http://192.168.250.2:8080/moje-narudzbe)
- Vlasnik: `admin@oneshop.local`, ista zasebno predana lozinka, sve ovlasti i bez obaveznog 2FA. Obični administratori koriste MFA.

[Kratka ručna proba](NIGHT_DEMO_20261030.md) vodi od majice s varijantama do simulatora, povijesti, admin narudžbe, PDF-a i zalihe. [Administratorske upute](ADMIN_GUIDE.md) opisuju unos proizvoda, fotografija i dostave.

Hostname `codex`, stalna adresa `192.168.250.2/24` na `eth1`, internet preko `eth0`. Naslovnica, health, login i povijest vraćaju 200 iz VM-a, a proizvodi bez prijave preusmjeravaju na isti login. Chromium provjera stvarnog previewa na 1400/390 px ima četiri prolazna prikaza, bez JS grešaka, neuspjelih resursa, vanjskih zahtjeva ili prelijevanja. Automatski ne-GET zahtjevi blokirani su u toj read-only probi. Windows pristup nije neovisno potvrđen. Zajednička mreža, firewall i drugi projekti nisu mijenjani.

## Aktualna aplikacija u previewu: 6a8426c

Ta je funkcionalna revizija objavljena. [Main CI](https://github.com/eniofrimer/oneshop/actions/runs/41167763867), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41167763829) i [tajne](https://github.com/eniofrimer/oneshop/actions/runs/41167763825) su zeleni. Oba SQL enginea prolaze **334 testa / 3.713 assertions**, Pint, Larastan, audit, konkurentne i native provjere. Isti izvor prolazi [šest javnih browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41166820449), [admin i MFA na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41166822444) te [obnovu na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41166824245).

| Dio | Isporučeno u previewu |
| --- | --- |
| Katalog | Tri demo proizvoda, majica u četiri kombinacije i dvije fotografske boje, nacrti, opcije, SKU-ovi, EUR cijene, zaliha, kategorije, galerija i fotografija SKU-a te izričita objava. |
| Frontend | Četiri pripremljene teme, responsive lokalne slike i galerija, pretraga/dostupnost/paginacija, varijante, košarica s brojem komada i checkout koraci. Kupnja radi i bez JS-a. |
| Kupnja | Adresa, spremljena dostavna tarifa, ukupni iznos, rezervacija, simulator uspjeha/odbijanja/otkazivanja i povijesna potvrda. Uspjeh potroši zalihu jednom. |
| Gost | Sesijska povijest testnih narudžbi nakon nove košarice; drugi preglednik ne dobiva podatke. Nema kupčeva računa. |
| Admin | Grupirana navigacija, posljednje narudžbe, naziv/SKU pretraga, objedinjeni pregled zaliha, dostava, read-only narudžbe/plaćanja/pokušaji, sigurni podržani audit događaji i PDF. |
| Datoteke | Privatni administratorski upload/download uz integritet i arhivu; kupčeva plaćena prava nisu uvedena. |
| MFA | Hrvatski upis/challenge/profil, lokalni QR, TOTP, replay zaštita i jednokratni recovery. Recovery radi prvim klikom i nakon pogrešnog OTP-a; potrošeni kodovi daju normalno odbijanje. |

Vrijeme se prikazuje u `Europe/Zagreb`; baza ostaje UTC. [Ogledne fotografije i promptovi](DEMO_ASSETS.md) koriste ugrađeni imagegen alat i verzionirane lokalne assete. Nije dodan novi Filament paket; koriste se postojeći Filament, Lunar i Spatie API-ji.

**Nema stvarne naplate ni slanja paketa.** Simulator je ograničen na local/testing. Stripe testni adapter, trajni pokušaji i inbox/outbox postoje, ali javni sandbox ostaje isključen do privatne merchant konfiguracije i hosted probe. PDF nije račun. Demo HR dostava 5 EUR i porez 25% nisu potvrđena trgovčeva pravila. [Checkout](DEVELOPMENT_CHECKOUT.md), [Stripe opseg](PAYMENT_ATTEMPTS.md), [pregled naplate](PAYMENT_REVIEW.md).

## Izvršene dorade i granice dokaza

Mobilni Studio/Market header više ne preklapa sadržaj. Lokalno prolazi 20 mjerenja četiriju tema na 320/390/540/768/1400 px; trajni javni browser sada provjerava geometriju kroz svaki korak. Proizvodi bez fotografije imaju imenovanu neutralnu lokalnu sliku; product-list axe radi na obje baze.

MFA koristi usko App proširenje native providera: HTML obaveznost nakon recovery odabira, brisanje starog pogrešnog OTP-a i normalno odbijanje praznog popisa. Serverska pravila, verifikatori, lockovi, prozor i limiti ostaju naslijeđeni. Šest novih testova / 71 assertions pokriva i zadnji kod, TOTP nakon iscrpljenja te opoziv admina/promjenu lozinke tijekom challengea. Stvarni browser prolazi deset faza / 20 axe provjera po bazi na 1280/390 px, recovery jednim klikom uz 250 ms latencije i tri native cooldown prozora. Ključevi, QR i rezervni kodovi nisu snimani. Poseban browser test odbija četiri osjetljiva prikaza i prihvaća očišćen prikaz, bez mrežnih zahtjeva ili negativnih screenshotova.

PDF s dugim nazivima/SKU-ovima/adresama koristi fiksne stupce i prelamanje. Lokalni Poppler dokaz na sintetičkih 12 stavki daje četiri stranice, 0 okvira izvan A4, svih 3.095 znakova Č i 1.281 znak Ž, konačni iznos i napomenu da nije račun. Prva/zadnja stranica vizualno su pregledane; postojeći PDF skup prolazi 5 testova / 41 assertions. QA izmjene bile su rollback transakcija. Poppler provjera nije automatski CI test.

Čista završna native instalacija `6a8426c` ima provjeren CI ZIP/tar/lock SHA, 305 identičnih Git datoteka i svih **21.035 originalnih datoteka** neizmijenjenih nakon proba. PHP 8.5.12 radi bez Redis/PDO-PG/Horizon/Faker ovisnosti uz database cache/sessions/jobs. Devet kupnji kroz tri enginea i devet stale-stock/shipping/price scenarija daju 57 native axe provjera bez kršenja i nula novih/izmijenjenih financijskih zapisa pri odbijanju. Cached production odbija simulator i Stripe preglede, a stvarni scheduler obrađuje database queue/GD konverzije bez stalnog workera. Potpuni native admin/MFA/private-file/500-proizvodni/4096 px baseline pripada `5f59c31`; točna usporedba potvrđuje istih 20.621 vendor package datoteka i nepromijenjen ostatak app/auth/config/routes/frontenda. Granični PNG u tom baselineu prolazi pri 512M: 5,65 s / oko 201 MiB RSS-a / 0 failed jobs. [Pojedinačni dokazi i granice](NATIVE_ACCEPTANCE_20261031.md). To nije potvrda stvarnog panela, web PHP-a ili TLS-a; Docker je izvršno provjeren u CI-u.

## Fotografije SKU-ova — objavljene

Akcija **Fotografija SKU-a** bira postojeću javnu JPEG/PNG/WebP fotografiju istog proizvoda. Native Lunar pivot čuva ostale poveznice i pozicije. Odabir ne mijenja SKU/cijenu/zalihu. Detalj, galerija i košarica prate varijantu; djelomičan odabir boje prikazuje sliku samo kad je dijele svi odgovarajući SKU-ovi. Kupnja i dalje traži potpunu jedinstvenu kombinaciju. Pješčana demo majica ima odgovarajući lokalni asset, a vlastiti upload ima prednost.

Puni gore povezani CI je zelen. Stvarni admin tok provjerava dvije SKU fotografije, promjenu/reset i no-JS košaricu uz očuvanu cijenu i stock granice. Na postojećem previewu zasebna read-only Chromium proba na 1400/390 px potvrđuje učitanu pješčanu WebP fotografiju već nakon odabira boje. Postojeći dropdown nije zamijenjen radio gumbima. Dodavanje je onemogućeno do potpunog odabira; proba blokira sve ne-GET zahtjeve i ne stvara narudžbe.

## Pregled zaliha — objavljen

**Katalog → Zalihe** daje jedinstven read-only pregled aktivnih SKU-ova s nazivom proizvoda/varijante, fizičkom, rezerviranom i raspoloživom zalihom. Pretraga nalazi baš taj SKU ili primarni naziv proizvoda; filtri odvajaju raspoloživost, praćenje, objavu i fizičku isporuku. Klik otvara postojeći editor proizvoda. Nema novih mutacijskih ruta, migracija ili lock promjena.

Stvarni admin tok u CI-u na obje baze i u čistom native paketu daje 20 faza. Četiri axe provjere pokrivaju foto editor i zalihe na 1440/390 px. Pregled pokazuje 10 / 1 / 9 prije postojeće kupnje, zatim 9 / 0 / 9 nakon uspjeha. Nije dodana dodatna narudžba radi pregleda.

## Brži katalog i pristupačniji fallback — objavljeno

Referentni QA katalog od 25 i 500 proizvoda potvrdio je ograničenu paginaciju, a otkrio ponavljano dohvaćanje istih vrsta podataka za svaku karticu. Skupno učitavanje naslovnica, valute, SKU-ova i cijena smanjuje zagrijanu stranicu od 12 proizvoda s **99 na 22 SQL upita**. Batch-only usporedba čuva identičan HTML kartica na četiri ista upita; nakon toga zasebno je poboljšan prikaz bez fotografije. To je mala lokalna proba, ne prihvat velikog kataloga ili hosting SLA.

Pet novih testova / 70 assertions čuva naslovnice po proizvodu, javni disk, cijene/valutu/pakiranja, rezervacije i svježinu podataka; uključene su konvertirane slike i query budget. Povezani skup prolazi 56 testova / 685 assertions, Pint, Larastan i build. Nedostajući javni materijal sada koristi isti imenovani neutralni SVG kao admin. Pravi pagination fixture više ne nasljeđuje skriveni demo-photo marker. Galerija dobiva imenovanu grupu za assistive tehnologije. QA Chromium na 1400/390 px prolazi deset axe faza kroz stranice 1/42/100, raspoloživu stranicu 34 i galeriju, bez kršenja, JS grešaka, vanjskih/upisnih zahtjeva ili kupnji. Puni CI i čisti native prihvat su zeleni, a dorade su na glavnom previewu. I netaknuti paket `5f59c31` prolazi 25/500-proizvodnu probu s 22 zagrijana SQL upita, deset javnih i četiri dodatne admin axe provjere. Posljednji SKU i availability filter rade bez promjene šest uspoređenih trgovinskih tablica. Ukupno deset CI ZIP-ova ima provjeren digest/CRC; 298 axe provjera nema kršenja, uz 84 neodlučena rezultata koji ostaju zabilježeni.

## Oporavak zastarjelog pregleda — objavljen

Ako zaliha nestane nakon pregleda, kupac se vraća na uređivu košaricu. Ako nedostaju adresa ili dostava, vraća se na pripadajući obrazac sa spremljenim unosima. Nema GET redirect loopa; stari quote se briše, JSON ostaje 422, a produkcijski/mode guardovi ostaju zatvoreni. Promjena cijene i dalje zahtijeva novi pregled i izričitu predaju. Deset novih regresija u oba checkout načina te devet stvarnih desktop/mobile/no-JS scenarija potvrđuje i očuvanje financijskih tablica.

## Očuvanje i sigurnost

Objava `6a8426c` sačuvala je **951 postojeći redak / 77 tablica**, env, račun i stalni URL. Nije bilo novih migracija ili promjene lockova. Privatna snimka 31. 10. u 05:14 Europe/Zagreb ima provjerene pristupe 0700/0600, veličine, SHA-256 i gzip. Stvarna izolirana SQL obnova najnovije kopije potvrđuje svih 77 tablica / 951 redak, bez učitavanja env-a ili pokretanja aplikacije. Importer ne može čitati drugu bazu; privremena baza/korisnik su uklonjeni. Media arhiv nema uploadanih podataka, a offsite i pun key/application recovery nisu izvršeni. Frontend je preuzet iz točno testiranog native arhiva (tri datoteke). Projektni DB/HTTP/worker/timer su aktivni i omogućeni; scheduler završava s exit 0. Aktualni read-only HTTP dokaz potvrđuje login 200 i `/admin/inventory` → isti login. Dev preview ima Horizon paket i odbija neprijavljeni pristup s 403; no-dev native paket vraća 404. Novi prikazi na 1400/390 px nemaju JS grešaka, vanjskih zahtjeva, neuspjelih asseta ili prelijevanja. Privremeni QA root/baza/korisnik, QA lozinke i testni daemon uklonjeni su. Nadzor jednom u minuti do 04:20 UTC bilježi 357 zdravih uzoraka, bez neuspjelog ili uzorkovanog maintenance intervala; još traje. To su uzorci, ne mjerenje neprekidnog uptimea.

GitHub [alert #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) zatvoren je kao sintetički testni fixture. Metadata provjera nakon main pusha potvrđuje `resolved / false_positive`; nema otvorenog alerta. Fiksni Stripe fixture ključevi uklonjeni su iz aktualnog izvora. Pre-push i CI skeniraju povijest/source s preciznim povijesnim iznimkama, bez općeg izuzimanja testova. [Postupak i dokazi](SECRET_HANDLING.md).

## Preostalo za stvarnog trgovca

1. Merchant Stripe sandbox konfiguracija/hosted proba i live naplata; PayPal/virman, refund ledger i potvrđena politika isteka.
2. Kupčev identitet/Google i plaćena digitalna prava.
3. Identitet trgovca, porezi/B2B/OIB, računi i odabrana dokumentna integracija.
4. Ugovorene tarife, prijevoznici/paketomati i stvarna predaja pošiljke.
5. Ciljni hosting/domena, HTTPS/mail/cron, retencija/offsite obnova i RPO/RTO; opseg sadržaja, import/export i jezici.

[Mapa jutarnjeg nastavka](MORNING_REVIEW.md), [plan](PLAN.md), [review](CLAUDE_REVIEW.md), [hosting granice](DEPLOYMENT.md) i [browser postupak](BROWSER_TESTING.md). Otvorene odluke nisu potvrđeni zahtjevi. Browser dokazi su Linux/CI: uski Firefox nije Android, Linux WebKit nije fizički Safari/iPhone, a axe nije potpuna proba čitačem ekrana.
