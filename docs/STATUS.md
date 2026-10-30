# OneShop — provjereno razvojno stanje

Ažurirano: 31. 10. 2026. tijekom noći, Europe/Zagreb. Ovo je razvojni ogledni primjerak za ručne probne narudžbe.

## Otvaranje i prva kupnja

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Admin: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Povijest iste sesije: [Moje narudžbe](http://192.168.250.2:8080/moje-narudzbe)
- Vlasnik: `admin@oneshop.local`, ista zasebno predana lozinka, sve ovlasti i bez obaveznog 2FA. Obični administratori zahtijevaju MFA.

[Kratka ručna proba](NIGHT_DEMO_20261030.md) vodi od majice s varijantama do simulatora, admin narudžbe, PDF-a i provjere zalihe. [Administratorske upute](ADMIN_GUIDE.md) opisuju unos vlastitog proizvoda i dostave.

Hostname je `codex`, stalna adresa `192.168.250.2/24` na `eth1`, internet preko `eth0`. Naslovnica, health, prijava i povijest vraćaju 200 iz VM-a; zaštićeni ekrani preusmjeravaju na isti login. Windows pristup nije neovisno potvrđen. Zajednička mreža, firewall i drugi projekti nisu mijenjani.

## Što radi

| Dio | Isporučeno |
| --- | --- |
| Katalog | Tri demo proizvoda, uključujući majicu u četiri kombinacije; nacrti, opcije, SKU-ovi, osnovne EUR cijene, zaliha, kategorije, fotografije, provjera i izričita objava. |
| Frontend | Četiri pripremljene teme, responsive lokalne slike, izbor varijante, javna galerija, pretraga/dostupnost/paginacija, košarica s brojem komada i checkout koracima. Radi i bez JavaScripta. |
| Kupnja | Adresa, spremljena dostavna tarifa, porez/ukupan iznos, predaja testne narudžbe, rezervacija, simulator uspjeha/odbijanja/otkazivanja i povijesna potvrda. Uspjeh potroši zalihu jednom. |
| Povijest gosta | Sesijska lista testnih narudžbi ostaje dostupna nakon nove košarice; drugi preglednik i pogađanje broja ne daju pristup. Nema kupčeva korisničkog računa. |
| Admin | Grupirana navigacija, posljednje narudžbe, naziv/SKU pretraga u vrhu, dostava, read-only narudžbe/plaćanja/pokušaji i PDF. Aktivnosti prikazuju podržane događaje bez sirovih properties/description podataka. |
| Datoteke | Privatni administratorski upload, provjera integriteta, preuzimanje i arhiva; kupčeva plaćena prava nisu uvedena. |

Prikaz vremena koristi `Europe/Zagreb`; baza ostaje UTC. [Ogledne fotografije i promptovi](DEMO_ASSETS.md) izrađeni su ugrađenim imagegen alatom; vlastita prenesena galerija ima prednost. Nije dodan novi Filament paket: korišteni su postojeći Filament i Spatie Activitylog API-ji.

**Nema stvarne naplate ni slanja paketa.** Simulator je ograničen na local/testing. Stripe testni adapter, trajni pokušaji, webhook inbox i financijski outbox su implementirani, ali javni sandbox ostaje isključen do privatne merchant konfiguracije i stvarne hosted probe. PDF nije račun. Demo HR dostava 5 EUR i porez 25% nisu potvrđena trgovčeva pravila. [Checkout](DEVELOPMENT_CHECKOUT.md), [Stripe opseg](PAYMENT_ATTEMPTS.md), [pregled naplate](PAYMENT_REVIEW.md).

## Aktualni preview: f50fb67

Ta je funkcionalna revizija na previewu i GitHub mainu. [Main CI](https://github.com/eniofrimer/oneshop/actions/runs/41143036089), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41143036142) i [provjera tajni](https://github.com/eniofrimer/oneshop/actions/runs/41143036115) su zeleni. Oba SQL enginea prolaze **295 testova / 3.280 assertions i 14 konkurentnih scenarija**, Pint, Larastan, audit i native provjere. [Admin CI](https://github.com/eniofrimer/oneshop/actions/runs/41141231791) potvrđuje oba enginea i četiri nove axe provjere popisa s proizvodima bez fotografije; dva ZIP hasha i svih deset JSON izvještaja neovisno su provjereni.

Širi prihvat za `23a9f6d` uključuje [šest javnih browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41137727769), [oba admin posla](https://github.com/eniofrimer/oneshop/actions/runs/41137730550) i [obnovu na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41137733917). Deset ZIP artefakata provjereno je SHA-256 hashom, a pregledana su 284 JSON izvještaja: **36 javnih simuliranih kupnji, 246 axe provjera bez potvrđenih povreda, 12 checkout putanja tipkovnicom i šest admin tokova**. Axe ima i 53 contrast nalaza za ručni pregled. Obnova uspoređuje **77 tablica / 392 retka / osam datoteka**, uključujući ključeve, rezervacije, payment replay i ID slijedove. To su sintetičke obnove, ne vraćanje korisnikova previewa.

Native arhiv iz [razvojnog CI-a](https://github.com/eniofrimer/oneshop/actions/runs/41137721865), **23.171.562 bajta**, zasebno je potvrđen hashom/revizijom/lockovima i čisto instaliran na vlastiti QA MariaDB daemon. PHP 8.5.12 nema Redis/PDO PostgreSQL ekstenzije, Horizon ili Faker. Database cache/sessions/jobs i oba runtime profila prolaze. Cacheana production konfiguracija odbija simulator i povijest čak i uz demo zastavicu, a stvarni scheduler izvršava queue probe bez stalnog workera. Local profil prolazi devet kupnji u Chromiumu/Firefoxu/WebKitu, još tri kroz povijest te tri admin toka. **Svih 21.002 uspoređenih programskih datoteka ostalo je jednako arhivu.** To nije potvrda stvarnog cPanel/FastPanel računa ili TLS-a.

Širi admin pregled pronašao je neimenovane poveznice kod proizvoda bez fotografije. Popravak `f50fb67` dodaje neutralnu lokalnu sliku s nazivom proizvoda. Nakon zabilježene zakrpe na zasebnoj QA kopiji svih **23 desktop/uska admin prikaza** prolazi bez potvrđenih axe povreda, prelijevanja, JS grešaka ili vanjskih zahtjeva. Dvije dodatne axe provjere po bazi sada prolaze i u trajnom admin CI-u.

Sljedeći lokalno izvršeni inkrement prevodi MFA upis, challenge, rezervne kodove i profil. Stvarni browser na običnom potrošnom adminu prolazi lokalni QR, upis, loš/ispravan TOTP, replay unutar važećeg prozora, jednokratni recovery i svježu prijavu. Devet faza ima prolazni axe/overflow i provjeru da u artefaktima nema vidljivih tajni; nema JS grešaka ili vanjskih zahtjeva. Autorizacijska politika i native limiti nisu mijenjani. Puni CI tog novog inkrementa još slijedi.

## Očuvanje previewa i sigurnost

Posljednja objava sačuvala je svih **936 postojećih redaka u 77 tablica**, env, URL i migracije. Nije bilo resetiranja, novih migracija ili promjene lockova. Privatna snimka od 31. 10. u 00:10 ima provjerene pristupe 0700/0600, veličine, SHA-256 i gzip; nije vraćena niti kopirana offsite. Projektni DB/HTTP/worker/timer su aktivni i omogućeni; scheduler tick završava s exit 0. Pokrenuto je uzorkovanje healtha/javne slike i samo tih servisa jednom u minuti. Završni rezultat nadzora još nije proglašen.

GitHub [alert #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) zatvoren je kao sintetički testni fixture. Provjera nakon novih pushova nema otvorenih alerta. Fiksni ključevi uklonjeni su iz aktualnog izvora; pre-push i CI skeniraju povijest i staged source s preciznim povijesnim iznimkama. [Dokazi i postupak](SECRET_HANDLING.md).

## Preostalo za stvarnog trgovca

1. Privatna Stripe sandbox konfiguracija i hosted merchant proba, zatim live naplata, PayPal/virman, refund ledger i potvrđena politika isteka.
2. Odvojeni kupčev identitet/Google prijava i plaćena digitalna prava.
3. Identitet trgovca, porezna pravila/B2B/OIB, računi i odabrana dokumentna integracija.
4. Ugovorene tarife, kurirske/paketomat integracije i predaja stvarne pošiljke.
5. Ciljni hosting/domena, stvarni web PHP/HTTPS/mail/cron, retencija/offsite obnova i RPO/RTO; opseg sadržaja, import/export i dodatni jezici.

[Mapa jutarnjeg nastavka](MORNING_REVIEW.md), [plan](PLAN.md), [review](CLAUDE_REVIEW.md), [hosting granice](DEPLOYMENT.md) i [browser postupak](BROWSER_TESTING.md). Otvorene odluke nisu predstavljene kao potvrđeni zahtjevi. Browser dokazi su Linux/CI: uski Firefox nije Android, Linux WebKit nije fizički Safari/iPhone, a axe nije potpuna provjera čitačem ekrana. Docker je izvršno provjeren u CI-u; ova VM nema Docker daemon.
