# OneShop — provjereno razvojno stanje

Ažurirano 1. 11. 2026., Europe/Zagreb. Funkcionalna revizija **`84b2c33`**, objavljena u **03:16**. Ogledni primjerak spreman je za ručne testne narudžbe. Naplata i dostava su simulirane; PDF nije račun.

## Otvaranje i ručna proba

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Admin: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Povijest iste sesije: [Moje narudžbe](http://192.168.250.2:8080/moje-narudzbe)
- Vlasnik: `admin@oneshop.local`, ista zasebno predana lozinka, sve ovlasti i bez obaveznog 2FA. Obični administratori koriste MFA.

[Brza ručna proba](NIGHT_DEMO_20261030.md) vodi kroz majicu/boju/veličinu, košaricu, dostavu, simulator, povijest, admin/PDF i zalihu. [Admin upute](ADMIN_GUIDE.md) opisuju unos i objavu proizvoda. Koristi izmišljene kontaktne podatke; kartica nije potrebna.

Hostname `codex`, stalni `192.168.250.2/24` na `eth1`, internet preko `eth0`. Najnoviji read-only HTTP dokaz iz VM-a potvrđuje health/naslovnicu/login/robots 200, proizvode/zalihe → isti login, sitemap 404 te neispravni upit/dostupnost 422 s poveznicom na odgovarajuće polje. Svi odgovori ostaju private/no-store/noindex. Windows pristup nije neovisno potvrđen. Mreža, firewall i drugi projekti nisu mijenjani.

## Isporučene funkcije

| Dio | Ponašanje u previewu |
| --- | --- |
| Katalog | Tri demo proizvoda, majica u četiri kombinacije i dvije fotografske boje; nacrti, opcije, SKU/cijene/zalihe, kategorije, galerija i izričita objava. |
| Frontend | Četiri teme, responsive lokalne slike i povećani pregled, pretraga/dostupnost/sortiranje/paginacija, putanja proizvoda, najviše četiri druga javna artikla i varijante. |
| Kupnja | Košarica, adresa, spremljena dostavna tarifa i pregled iznosa; rezervacija, simulator uspjeha/odbijanja/otkazivanja i povijesna potvrda. Uspjeh potroši zalihu jednom. Radi i bez JS-a. |
| Oporavak | Poveznice iz sažetka na stvarno pogrešno polje, očuvani unosi, uređivanje zastarjele košarice/adrese/dostave bez redirect loopa. Promjena cijene traži novi pregled. |
| Gost | Povijest testnih narudžbi u istoj sesiji; drugi preglednik ne dobiva podatke. Kupčev račun nije uveden. |
| Admin | Grupirana navigacija, posljednje narudžbe, naziv/SKU pretraga, fizička/rezervirana/raspoloživa zaliha, dostava, read-only narudžbe/plaćanja/pokušaji, podržane aktivnosti i PDF. |
| Datoteke i MFA | Privatni admin upload/download s integritetom/arhivom; obični admin ima lokalni QR/TOTP, replay zaštitu i jednokratni recovery. Kupčeva plaćena prava nisu uvedena. |

Vrijeme prikaza je `Europe/Zagreb`, baza UTC. Ogledne [fotografije i promptovi](DEMO_ASSETS.md) su verzionirani lokalno. Korišteni su postojeći Filament/Lunar/Spatie API-ji; novi paket nije bio potreban. Canonical/Open Graph i stvarni BreadcrumbList postoje, ali javno indeksiranje traži zasebni opt-in; [SEO opseg](STOREFRONT_SEO.md), [navigacija](PRODUCT_DISCOVERY.md), [obrasci](FORM_ACCESSIBILITY.md).

## Najnovija izvršna provjera

Doradu `84b2c33` potvrđuju [CI](https://github.com/eniofrimer/oneshop/actions/runs/41263118259), [browser](https://github.com/eniofrimer/oneshop/actions/runs/41263122174), [admin/MFA](https://github.com/eniofrimer/oneshop/actions/runs/41263123679), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41263120392), [obnova](https://github.com/eniofrimer/oneshop/actions/runs/41263125341) i [tajne](https://github.com/eniofrimer/oneshop/actions/runs/41263118218). Obje baze imaju **376 testova / 4.456 assertions**; Pint, Larastan, audit, 16 frontend testova, konkurentni scenariji i queue/scheduler prolaze. Jedanaest preuzetih ZIP-ova s provjerenim digestom/CRC-om daje **496 axe izvještaja bez kršenja, uz 102 neodlučene stavke**. Zajednički auditor sada uključuje automatizirani podskup WCAG 2.2 AA, uključujući `target-size`, bez isključenih pravila. Svih **48 negativnih frame kombinacija** prolazi uz pozitivne kontrole.

Dva premala Filament gumba za uklanjanje vrijednosti/oznake povećana su kroz zaseban Vite CSS ovog panela, bez vendor izmjena ili novog paketa. Stvarni klik i dodir u proširenim kutovima rade na 1440/390 px; izmjerene površine su 24 × 24 CSS px pri zadanom fontu. [Postupak i ručni preostali pregled](BROWSER_TESTING.md).

Nepodržani iznos sada se odbija prije stvaranja modela narudžbe, uz istu provjeru i na snapshotu. Pet novih negativnih regresija ima nula order-creation događaja, financijskih zapisa, rezervacija i provider poziva; dvije pozitivne čuvaju besplatnu demo narudžbu i najveći postojeći podržani iznos. Granice, quote, porez i tarife nisu promijenjeni. [Detalji](DEVELOPMENT_CHECKOUT.md).

Prijava i ostali dinamički odgovori sada ograničavaju ugradnju na isti origin pomoću XFO-a i zasebne CSP frame-ancestors politike. Postojeće aplikacijske CSP politike i stroži DENY se čuvaju. Izravan URL radi; ugradnja na stranicu drugog origina je odbijena. [Opseg i primarni izvori](HTTP_SECURITY.md).

Javni prikaz EUR iznosa koristi cijele cente, a JavaScript gotov server `price_label` bez float dijeljenja. Svih 2.000.001 uspoređenih uobičajenih iznosa ostaje jednako formatirano; rubni PHP integer zadržava cent koji je raniji float gubio. To nije proširenje cjenovnog limita. [Opseg formattera i naplate](DEVELOPMENT_CHECKOUT.md).

Svježi neizmijenjeni no-dev CI paket prolazi **devet gostujućih kupnji u tri enginea te jedan administratorski proizvod/fotografije/zalihe/kupnju**, privatne datoteke, kategorije/dostavu i obični administratorski MFA/replay/recovery. **88 axe provjera nema kršenja, uz 46 neodlučenih stavki. Svih 21.051 originalnih regularnih datoteka ostaje byte-identično.** PHP 8.5.12 ima PDO-MySQL/GD, bez Redis/PDO-PG/Horizon/Faker ovisnosti; database queue i runtime ciljevi cPanel/Fastpanel prolaze. Cached production config/routes/views uključuju stvarno učitan novi hashirani CSS, identičan paketu; env i devet trgovinskih tablica ostaju isti. To je loopback HTTP sa sintetičkim HTTPS metadata originom, ne stvarni TLS/panel/FPM prihvat. Sedam native servisnih graničnih slučajeva prethodno je izvršeno na `e72b745`, uz identičnih 78 QA tablica / 532 retka nakon svakog rollbacka i bez committed narudžbe/provider poziva. Server iznosa je neizmijenjen, a njegove HTTP regresije ponovljene su u aktualnom CI-u na obje baze.

Aktivni `84b2c33` preview zasebno prolazi devet HTTP provjera i **12 read-only Chromium axe prikaza na 1400/390/320 px bez kršenja ili neodlučenih stavki**, uz isti WCAG 2.2 auditor: katalog/sortiranje, stvarni Pijesak/L i `25,00 €`, proizvod/galerija i prazna prijava s novim CSS-om. Svi ne-GET/HEAD i vanjski zahtjevi su blokirani; tri automatska login Livewire POST pokušaja nisu poslana. Nema narudžbe, JS grešaka, neuspjelih resursa ili prelijevanja. Dodatna read-only proba razmaka teksta na 1400/800/390/320 px ima osam prikaza bez prelijevanja ili preklapanja zaglavlja; ona nije browser-zoom ili screen-reader certifikacija. Read-only frame proba aktivnog VM-a pripada objavi `4d7b595`; neizmijenjenu politiku ponovno potvrđuju aktualni HTTP i puni CI.

Docker je izvršno potvrđen u CI-u; VM nema Docker daemon. Native runtime cilj je provjeren profil, ne stvarni hosting račun/FPM/TLS. [Granice hostinga](DEPLOYMENT.md).

Tri enginea i obje baze imaju CI dokaz. Potpuni raniji native admin/MFA/private-file/500-proizvodni/4096-px baseline pripada `5f59c31`/`6a8426c`, uz točno opisanu usporedbu neizmijenjenih dijelova. Uski Firefox nije Android, Linux WebKit nije fizički Safari/iPhone, a automatizirani axe nije ručna proba čitačem ekrana. [Ova noć — pojedinačni dokazi](NIGHT_ACCEPTANCE_20261101.md), [raniji native baseline](NATIVE_ACCEPTANCE_20261031.md), [arhiva statusa](STATUS_HISTORY_20261031.md).

## Očuvanje podataka i sigurnost

Najnovija objava sačuvala je **svih 979 tada postojećih redaka u 77 tablica**, env, vlasnika i stalni URL. Nema migracija ili lock promjena. Privatna snimka `20261101-021647` ima provjerene 0700/0600 pristupe, veličine i SHA-256. **Stvarna izolirana SQL obnova te snimke potvrđuje svih 77 tablica / 979 redaka**, uz identične sadržajne hashove i negativnu kontrolu pristupa drugoj QA bazi. Env je checksum-provjeren, nije učitan; obnovljena aplikacija/poslovi nisu pokrenuti. Privremena restore baza/korisnik uklonjeni su. To nije offsite, dekripcija ključeva ili puni file/application recovery.

Usporedba prije prve i prije završne noćne objave potvrđuje svih **76 tablica izvan sesija / 915 redaka kao identične**. Samo su normalne nove/istekle sesije mijenjale ukupan broj. Četiri build datoteke su identične testiranom native paketu. Privremeni QA root, baza, korisnik i lozinka te fizički zaseban testni SQL daemon uklonjeni su; glavni DB/HTTP/worker/timer ostaju active/enabled, scheduler Result=success/exit 0. User linger je read-only potvrđen kao yes; reboot test nije izveden.

GitHub [alert #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) ranije je provjeren i zatvoren kao `resolved / false_positive` za sintetički Stripe testni fixture. Fiksni fixture ključevi uklonjeni su iz aktualnog izvora. Pre-push i CI skeniraju povijest/source s preciznim povijesnim iznimkama. Ključevi i lozinke nisu dio dokumentacije. [Postupak](SECRET_HANDLING.md).

## Otvoreno za stvarnog trgovca

Stvarna naplata i Stripe hosted prihvat traže privatnu merchant konfiguraciju. PDF, demo HR tarifa 5 EUR i stopa 25% nisu potvrđena pravila trgovca. Identitet/OIB/B2B, računi, prijevoznici/paketomati, stvarna predaja pošiljke, Google/kupčev identitet i plaćena digitalna prava ostaju otvoreni. Hosting/domena/HTTPS/mail/cron, offsite i puni key/application recovery te RPO/RTO zahtijevaju konkretno okruženje; ne predstavljaju se kao potvrđeni ovim VM probama.

[Minimalne jutarnje odluke](MORNING_REVIEW.md), [plan](PLAN.md), [review](CLAUDE_REVIEW.md), [hosting](DEPLOYMENT.md), [browser postupak](BROWSER_TESTING.md), [pregled naplate](PAYMENT_REVIEW.md).
