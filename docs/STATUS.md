# OneShop — provjereno razvojno stanje

Ažurirano: 31. 10. 2026. tijekom noći, Europe/Zagreb. Ovo je ogledni primjerak za ručne testne narudžbe.

## Otvaranje i prva kupnja

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Admin: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Povijest iste sesije: [Moje narudžbe](http://192.168.250.2:8080/moje-narudzbe)
- Vlasnik: `admin@oneshop.local`, ista zasebno predana lozinka, sve ovlasti i bez obaveznog 2FA. Obični administratori koriste MFA.

[Kratka ručna proba](NIGHT_DEMO_20261030.md) vodi od majice s varijantama do simulatora, povijesti, admin narudžbe, PDF-a i zalihe. [Administratorske upute](ADMIN_GUIDE.md) opisuju unos proizvoda, fotografija i dostave.

Hostname `codex`, stalna adresa `192.168.250.2/24` na `eth1`, internet preko `eth0`. Naslovnica, health, login i povijest vraćaju 200 iz VM-a, a proizvodi bez prijave preusmjeravaju na isti login. Chromium provjera stvarnog previewa na 1400/390 px ima četiri prolazna prikaza, bez JS grešaka, neuspjelih resursa, vanjskih zahtjeva ili prelijevanja. Automatski ne-GET zahtjevi blokirani su u toj read-only probi. Windows pristup nije neovisno potvrđen. Zajednička mreža, firewall i drugi projekti nisu mijenjani.

## Aktualni preview i main: 14a0efd

Ta je funkcionalna revizija objavljena. [Main CI](https://github.com/eniofrimer/oneshop/actions/runs/41157090563), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41157090612) i [tajne](https://github.com/eniofrimer/oneshop/actions/runs/41157090561) su zeleni. Oba SQL enginea prolaze **311 testova / 3.433 assertions**, Pint, Larastan, audit, konkurentne i native provjere. Isti izvor prolazi [šest javnih browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41155925727), [admin i MFA na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41155927993) te [obnovu na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41155930077).

| Dio | Isporučeno u previewu |
| --- | --- |
| Katalog | Tri demo proizvoda, majica u četiri kombinacije i dvije fotografske boje, nacrti, opcije, SKU-ovi, EUR cijene, zaliha, kategorije, galerija i fotografija SKU-a te izričita objava. |
| Frontend | Četiri pripremljene teme, responsive lokalne slike i galerija, pretraga/dostupnost/paginacija, varijante, košarica s brojem komada i checkout koraci. Kupnja radi i bez JS-a. |
| Kupnja | Adresa, spremljena dostavna tarifa, ukupni iznos, rezervacija, simulator uspjeha/odbijanja/otkazivanja i povijesna potvrda. Uspjeh potroši zalihu jednom. |
| Gost | Sesijska povijest testnih narudžbi nakon nove košarice; drugi preglednik ne dobiva podatke. Nema kupčeva računa. |
| Admin | Grupirana navigacija, posljednje narudžbe, naziv/SKU pretraga, dostava, read-only narudžbe/plaćanja/pokušaji, sigurni podržani audit događaji i PDF. |
| Datoteke | Privatni administratorski upload/download uz integritet i arhivu; kupčeva plaćena prava nisu uvedena. |
| MFA | Hrvatski upis/challenge/profil, lokalni QR, TOTP, replay zaštita i jednokratni recovery. Recovery radi prvim klikom i nakon pogrešnog OTP-a; potrošeni kodovi daju normalno odbijanje. |

Vrijeme se prikazuje u `Europe/Zagreb`; baza ostaje UTC. [Ogledne fotografije i promptovi](DEMO_ASSETS.md) koriste ugrađeni imagegen alat i verzionirane lokalne assete. Nije dodan novi Filament paket; koriste se postojeći Filament, Lunar i Spatie API-ji.

**Nema stvarne naplate ni slanja paketa.** Simulator je ograničen na local/testing. Stripe testni adapter, trajni pokušaji i inbox/outbox postoje, ali javni sandbox ostaje isključen do privatne merchant konfiguracije i hosted probe. PDF nije račun. Demo HR dostava 5 EUR i porez 25% nisu potvrđena trgovčeva pravila. [Checkout](DEVELOPMENT_CHECKOUT.md), [Stripe opseg](PAYMENT_ATTEMPTS.md), [pregled naplate](PAYMENT_REVIEW.md).

## Izvršene dorade i granice dokaza

Mobilni Studio/Market header više ne preklapa sadržaj. Lokalno prolazi 20 mjerenja četiriju tema na 320/390/540/768/1400 px; trajni javni browser sada provjerava geometriju kroz svaki korak. Proizvodi bez fotografije imaju imenovanu neutralnu lokalnu sliku; product-list axe radi na obje baze.

MFA koristi usko App proširenje native providera: HTML obaveznost nakon recovery odabira, brisanje starog pogrešnog OTP-a i normalno odbijanje praznog popisa. Serverska pravila, verifikatori, lockovi, prozor i limiti ostaju naslijeđeni. Šest novih testova / 71 assertions pokriva i zadnji kod, TOTP nakon iscrpljenja te opoziv admina/promjenu lozinke tijekom challengea. Stvarni browser prolazi deset faza / 20 axe provjera po bazi na 1280/390 px, recovery jednim klikom uz 250 ms latencije i tri native cooldown prozora. Ključevi, QR i rezervni kodovi nisu snimani. Poseban browser test odbija četiri osjetljiva prikaza i prihvaća očišćen prikaz, bez mrežnih zahtjeva ili negativnih screenshotova.

PDF s dugim nazivima/SKU-ovima/adresama koristi fiksne stupce i prelamanje. Lokalni Poppler dokaz na sintetičkih 12 stavki daje četiri stranice, 0 okvira izvan A4, svih 3.095 znakova Č i 1.281 znak Ž, konačni iznos i napomenu da nije račun. Prva/zadnja stranica vizualno su pregledane; postojeći PDF skup prolazi 5 testova / 41 assertions. QA izmjene bile su rollback transakcija. Poppler provjera nije automatski CI test.

Posljednja čista native VM instalacija vezana je uz `23a9f6d`, ne uz novije zakrpe. Ona potvrđuje no-dev paket, database cache/sessions/jobs, stvarni worker i scheduler, oba runtime profila te production odbijanje simulatora i povijesti. Nakon browser tokova svih 21.002 uspoređenih programskih datoteka ostalo je jednako arhivu. Kasnije lokalne UI/MFA/foto probe koriste izričito označenu QA kopiju sa zabilježenim zakrpama i regeneracijom autoloadera. Nova završna čista instalacija slijedi nakon sljedećeg prihvaćenog inkrementa. To nije potvrda stvarnog cPanel/FastPanel računa ili TLS-a; Docker je izvršno provjeren u CI-u, a ova VM nema Docker daemon.

## Fotografije SKU-ova — objavljene

Akcija **Fotografija SKU-a** bira postojeću javnu JPEG/PNG/WebP fotografiju istog proizvoda. Native Lunar pivot čuva ostale poveznice i pozicije. Odabir ne mijenja SKU/cijenu/zalihu. Detalj, galerija i košarica prate varijantu; djelomičan odabir boje prikazuje sliku samo kad je dijele svi odgovarajući SKU-ovi. Kupnja i dalje traži potpunu jedinstvenu kombinaciju. Pješčana demo majica ima odgovarajući lokalni asset, a vlastiti upload ima prednost.

Puni gore povezani CI je zelen. Stvarni admin tok provjerava dvije SKU fotografije, promjenu/reset i no-JS košaricu uz očuvanu cijenu i stock granice. Na postojećem previewu zasebna read-only Chromium proba na 1400/390 px potvrđuje učitanu pješčanu WebP fotografiju već nakon odabira boje. Postojeći dropdown nije zamijenjen radio gumbima. Dodavanje je onemogućeno do potpunog odabira; proba blokira sve ne-GET zahtjeve i ne stvara narudžbe.

## Sljedeći inkrement: pregled zaliha — lokalno provjeren

**Katalog → Zalihe** daje jedinstven read-only pregled aktivnih SKU-ova s nazivom proizvoda/varijante, fizičkom, rezerviranom i raspoloživom zalihom. Pretraga nalazi baš taj SKU ili primarni naziv proizvoda; filtri odvajaju raspoloživost, praćenje, objavu i fizičku isporuku. Klik otvara postojeći editor proizvoda. Nema novih mutacijskih ruta, migracija ili lock promjena.

Lokalno prolazi 14 testova / 118 assertions, uključujući postojeću globalnu pretragu i nadzornu ploču, Pint i Larastan. Zasebna pooštrena SKU-0 regresija također prolazi. Stvarni QA admin tok daje 20 faza i četiri axe provjere foto editora i zaliha na 1440/390 px; pregled pokazuje 10 / 1 / 9 prije iste postojeće kupnje, zatim 9 / 0 / 9 nakon uspjeha. Nisu dodane dodatne narudžbe radi tog pregleda. Puni CI i završna čista native instalacija slijede; ovaj ekran još nije na glavnom previewu.

## Očuvanje i sigurnost

Objava `14a0efd` sačuvala je **918 postojećih redaka / 77 tablica**, env i stalni URL. U odnosu na prethodnu objavu razlikuje se samo tablica sesija (25 → 7); svih ostalih 76 tabličnih hashova je jednako. Nije bilo novih migracija ili promjene lockova. Privatna snimka 31. 10. u 02:48 Europe/Zagreb ima provjerene pristupe 0700/0600, veličine, SHA-256 i gzip; nije vraćena niti kopirana offsite. Projektni DB/HTTP/worker/timer su aktivni i omogućeni, scheduler završava s exit 0. Nadzor jednom u minuti do 01:23 UTC bilježi 180 zdravih uzoraka bez neuspjelog uzorka; završni rezultat još nije proglašen.

GitHub [alert #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) zatvoren je kao sintetički testni fixture. Metadata provjera nakon main pusha potvrđuje `resolved / false_positive`; nema otvorenog alerta. Fiksni Stripe fixture ključevi uklonjeni su iz aktualnog izvora. Pre-push i CI skeniraju povijest/source s preciznim povijesnim iznimkama, bez općeg izuzimanja testova. [Postupak i dokazi](SECRET_HANDLING.md).

## Preostalo za stvarnog trgovca

1. Merchant Stripe sandbox konfiguracija/hosted proba i live naplata; PayPal/virman, refund ledger i potvrđena politika isteka.
2. Kupčev identitet/Google i plaćena digitalna prava.
3. Identitet trgovca, porezi/B2B/OIB, računi i odabrana dokumentna integracija.
4. Ugovorene tarife, prijevoznici/paketomati i stvarna predaja pošiljke.
5. Ciljni hosting/domena, HTTPS/mail/cron, retencija/offsite obnova i RPO/RTO; opseg sadržaja, import/export i jezici.

[Mapa jutarnjeg nastavka](MORNING_REVIEW.md), [plan](PLAN.md), [review](CLAUDE_REVIEW.md), [hosting granice](DEPLOYMENT.md) i [browser postupak](BROWSER_TESTING.md). Otvorene odluke nisu potvrđeni zahtjevi. Browser dokazi su Linux/CI: uski Firefox nije Android, Linux WebKit nije fizički Safari/iPhone, a axe nije potpuna proba čitačem ekrana.
