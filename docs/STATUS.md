# OneShop — provjereno razvojno stanje

Ažurirano 1. 11. 2026., Europe/Zagreb. Funkcionalna revizija **`1f3ae12`**, objavljena u **00:55**. Ogledni primjerak spreman je za ručne testne narudžbe. Naplata i dostava su simulirane; PDF nije račun.

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

Doradu `1f3ae12` potvrđuju [CI](https://github.com/eniofrimer/oneshop/actions/runs/41252931068), [browser](https://github.com/eniofrimer/oneshop/actions/runs/41252936334), [admin/MFA](https://github.com/eniofrimer/oneshop/actions/runs/41252938222), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41252933659), [obnova](https://github.com/eniofrimer/oneshop/actions/runs/41252939963) i [tajne](https://github.com/eniofrimer/oneshop/actions/runs/41252930531). Obje baze imaju **364 testa / 4.239 assertions**; Pint, Larastan, audit i build prolaze. Jedanaest preuzetih ZIP-ova ima provjeren digest/CRC: **496 axe izvještaja, nula kršenja, 102 neodlučene stavke**. Neodlučene stavke su color-contrast i reference lijeno prikazanih Filament kontrola; nisu prikazane kao prolaz ručnog pristupačnog prihvata.

Svježi neizmijenjeni no-dev CI paket na zasebnoj fizičkoj SQL instanci prolazi tri Chromium kupnje, tipkovnički negativni pokušaj dostave bez postavljanja narudžbe, runtime ciljeve cPanel/Fastpanel, queue i cached production config/routes/views. **21 axe provjera nema kršenja, uz sedam neodlučenih stavki. Svih 21.046 originalnih regularnih datoteka ostaje byte-identično.** PHP 8.5.12 ima PDO-MySQL/GD, bez Redis/PDO-PG/Horizon/Faker ovisnosti. To je provjera native profila u VM-u, ne stvarnog hosting računa/FPM-a/TLS-a. Docker se izvršno provjerava u CI-u; VM nema Docker daemon.

Tri enginea i obje baze imaju CI dokaz. Potpuni raniji native admin/MFA/private-file/500-proizvodni/4096-px baseline pripada `5f59c31`/`6a8426c`, uz točno opisanu usporedbu neizmijenjenih dijelova. Uski Firefox nije Android, Linux WebKit nije fizički Safari/iPhone, a automatizirani axe nije ručna proba čitačem ekrana. [Ova noć — pojedinačni dokazi](NIGHT_ACCEPTANCE_20261101.md), [raniji native baseline](NATIVE_ACCEPTANCE_20261031.md), [arhiva statusa](STATUS_HISTORY_20261031.md).

## Očuvanje podataka i sigurnost

Najnovija objava sačuvala je **svih 940 tada postojećih redaka u 77 tablica**, env, vlasnika i stalni URL. Razlika prema prethodnom ukupnom broju je samo automatsko čišćenje 39 isteklih sesija prije objave; sve druge tablice su identične. Nema migracija ili lock promjena. Privatna snimka `20261031-235541` ima provjerene 0700/0600 pristupe, veličine i SHA-256. U tom koraku nije obnavljana; ranija stvarna SQL obnova i aktualni CI recovery odvojeni su dokazi. Tri build datoteke identične su testiranom native paketu. Projektni DB/HTTP/worker/timer su active/enabled, scheduler Result=success/exit 0; user linger je read-only potvrđen kao yes, bez reboot testa.

GitHub [alert #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) ranije je provjeren i zatvoren kao `resolved / false_positive` za sintetički Stripe testni fixture. Fiksni fixture ključevi uklonjeni su iz aktualnog izvora. Pre-push i CI skeniraju povijest/source s preciznim povijesnim iznimkama. Ključevi i lozinke nisu dio dokumentacije. [Postupak](SECRET_HANDLING.md).

## Otvoreno za stvarnog trgovca

Stvarna naplata i Stripe hosted prihvat traže privatnu merchant konfiguraciju. PDF, demo HR tarifa 5 EUR i stopa 25% nisu potvrđena pravila trgovca. Identitet/OIB/B2B, računi, prijevoznici/paketomati, stvarna predaja pošiljke, Google/kupčev identitet i plaćena digitalna prava ostaju otvoreni. Hosting/domena/HTTPS/mail/cron, offsite i puni key/application recovery te RPO/RTO zahtijevaju konkretno okruženje; ne predstavljaju se kao potvrđeni ovim VM probama.

[Minimalne jutarnje odluke](MORNING_REVIEW.md), [plan](PLAN.md), [review](CLAUDE_REVIEW.md), [hosting](DEPLOYMENT.md), [browser postupak](BROWSER_TESTING.md), [pregled naplate](PAYMENT_REVIEW.md).
