# OneShop: funkcionalnosti, ciljani stack i plan rada

Datum: 29. 10. 2026.
Status: faza 2 zavrsena; faza 3 zapoceta vlastitom administracijom nacrta kataloga. Otvorene poslovne odluke ostaju u odjeljku 6.

## Izvrsna provjera u Ubuntu VM-u

**Faza 2 prihvacena:** [CI 40910199122](https://github.com/eniofrimer/oneshop/actions/runs/40910199122), commit `51b5b99`, potpuno zelen: zakljucana instalacija, audit, frontend build, PostgreSQL 18 migracije, 29 testova / 155 assertions, Pint i Larastan. Lunar core ostaje temelj razvoja bez izmjena vendor koda.

- Repozitorij `/home/ubuntu/code/oneshop`, cisti pocetni HEAD `080c6a9`; HTTPS remote i `git push --dry-run` provjereni. Commit je pushan na `main`.
- [CI 40909337424](https://github.com/eniofrimer/oneshop/actions/runs/40909337424) za `080c6a9`: instalacija, Composer validacija/platforma/audit, frontend build, PostgreSQL 18 migracije i svih **29 testova / 155 assertions** prolaze. Larastan razina 6 prolazi. Jedini pad: Pint (24 datoteke); formatiranje je ispravljeno bez promjene ponasanja.
- `composer.lock` i `package-lock.json` preuzeti su iz CI artefakta `dependency-locks-080c6a9f569516f7d72b6969a5d4726c7c0be621`. Sadrzaj je identican artefaktu prethodnog runa `40894601776`. Zakljucani skup lokalno prolazi `composer install`, strogu validaciju, platform requirements, audit, Pint i frontend build uz PHP 8.5.12 / Node 24.21.0.
- VM je na pocetku imala PHP 8.4.26 i Node 24.21.0, bez Dockera i bez sudo pristupa bez lozinke. PHP 8.5.12 i potrebne ekstenzije izdvojeni su u `/tmp/oneshop-runtime`, bez sistemske instalacije. Korisnik odvojeno priprema azuriranje Ubuntua i instalaciju Dockera. Projektni Docker ostaje reproducibilna razvojna okolina.
- Sandbox wrapper prijavljuje `bwrap: loopback: Failed RTM_NEWADDR`; odobrene projektne naredbe izvrsene su u Ubuntu VM-u izvan tog wrappera. GitHub CLI i Git push rade. Ranije Windows/WSL zapreke nisu ogranicenja ove VM sesije; pristup Windows datotekama nije koristen.

Trgovinski proof potvrduje porez i dostavu, povijesne snapshotove narudzbe te ponovni pokusaj draft narudzbe s jednokratnim kuponom. Administratorski MFA, iskljucene Fortify rute, Horizon autorizacija i ponovljiva inicijalizacija trgovine imaju prolazne regresijske testove. To nije potvrda spremnosti produkcijskog checkouta: konkurentna zaliha, payment callbackovi, povrati i vanjske integracije ostaju zasebni uvjeti prihvata.

## Faza 3: prvi dio administracije kataloga

Implementirano na `/admin/products`:

- Stvaranje i uredjivanje proizvoda kao nacrta, odabir/stvaranje vrste kataloga i lokalizirani naziv/opis za jezike iz `oneshop.enabled_locales`. Hrvatski naziv je obvezan uz zadanu konfiguraciju; drugi jezici se ne ukljucuju automatski.
- Vise SKU-ova po proizvodu, osnovna EUR cijena, porezna kategorija, zaliha i dostupnost, oznaka fizicke dostave. Naziv cijene prati konfiguraciju ukljucenog/iskljucenog poreza; porezne stope i zone se ne pretpostavljaju.
- Transakcijsko spremanje varijante i cijene, cijeli centi bez float racunanja, cuvanje drugih cjenovnih razreda i jezika/atributa koji nisu u obrascu. Podrzane su jedinicne kolicine; uredjivanje postojecih pakiranja namjerno se zaustavlja.
- Jedinstveni SKU normaliziran na velika slova i bez okolnih razmaka, uz PostgreSQL unique indeks koji obuhvaca i arhivirane varijante. Migracija na postojecoj bazi s duplikatima staje, ne mijenja podatke samoinicijativno.
- Zakljucavanje zapisa i provjera zalihe koju je obrazac prikazao: zastarjela izmjena ne prepisuje noviju zalihu. To nije implementacija rezervacija pri kupnji.
- Eksplicitne politike pristupa: samo administratori s upisanim MFA-om, unutar postojeceg Filament login/MFA toka. Servisi provjeravaju autorizaciju; varijanta se ne moze premjestiti podmetnutim ID-em drugog proizvoda.

Lokalna provjera PHP 8.5.12 / PostgreSQL 18.6: **44 testa / 253 assertions**, Pint i Larastan prolaze. Ukljuceni su HTTP i Livewire testovi stvarnih admin stranica/modala te prikaza validacijskih gresaka. Nije provedena vizualna provjera u pregledniku. [CI 40912133067](https://github.com/eniofrimer/oneshop/actions/runs/40912133067) za commit `bbccd1f` takodjer je prosao instalaciju iz lock datoteka, audit, frontend build, migracije, sve testove, Pint i Larastan.

Faza 3 jos nije zavrsena. Slijede imenovani atributi/opcije i kombinacije varijanti, javni mediji i privatne digitalne datoteke, kategorije, kontrolirana objava, administracija narudzbi i detaljnije ovlasti. Digitalna oznaka trenutno samo iskljucuje fizicku dostavu; ne predstavlja isporucen download sustav. Ovaj editor ne brise proizvode i ne objavljuje nove nacrte. Opseg importa/exporta i referentni katalog ostaju poslovne odluke iz odjeljka 6.

## 1. Dogovorena osnova

- Vlastiti webshop proizvod za ponovljivu isporuku klijentima.
- Svaki klijent ima zasebnu instancu na vlastitoj domeni.
- Najnovije stabilne verzije Laravela i Filamenta; bez alpha/RC temelja za produkciju.
- Repozitorij: https://github.com/eniofrimer/oneshop
- Jedan zajednički izvorni kod i verzionirana izdanja predlažu se kao način održavanja; konfiguracija, podaci i pristupni podaci odvojeni su po instanci. Klijentske posebnosti ne razvijati kao nekontrolirane kopije cijelog projekta.
- Lunar core je prihvaćen nakon prolazne faze 2; dodatni trgovinski scenariji provjeravaju se uz implementaciju pripadnih faza.

## 2. Funkcionalnosti koje je korisnik zatražio

### Kupci

- Jednostavna registracija i prijava te kupnja bez registracije.
- Google prijava.
- Checkbox za kupnju preko firme / R1 podatke: OIB, naziv i adresa subjekta.
- Obvezna Sudreg integracija za dohvat poslovnih podataka po OIB-u.
- Vizualno dojmljiv, jednostavan korisnički panel: narudžbe, osobni podaci i povezane funkcije.

Predložena dopuna: ručni unos kad registar nema rezultat ili servis nije dostupan; preuzimanja i dokumenti povezani uz kupčeve narudžbe; promjena profila ne mijenja povijesne podatke narudžbe. Sudreg nije univerzalni registar svih poslovnih kupaca.

### Katalog

- Jednostavni proizvodi: standardni podaci, cijene, dostava i opcionalno praćenje zalihe.
- Varijabilni proizvodi: unaprijed pripremljeni atributi poput boje i veličine, odabir vrijednosti i kombinacija te odgovarajuće kontrole (dropdown, multi-select, checkbox, radio).
- Digitalni proizvodi za preuzimanje.

Predložena dopuna: razlikovati SKU varijante s vlastitom cijenom/zalihom od dodatnih opcija na jednoj stavci. Multi-select u adminu za dostupne vrijednosti nije isto što i kupčev odabir više dodataka. Plaćene digitalne datoteke čuvati privatno; gost dobiva sigurnu poveznicu nakon naplate, a korisnik preuzimanje i u svom panelu. Miješana košarica dostavlja samo fizičke stavke.

### Jezici

- Četiri do pet sistemskih jezika; hrvatski je primaran.
- Prijevodi proizvoda, kategorija, atributa i ostalog sadržaja kad trgovac aktivira više jezika.
- Jezične verzije checkouta, korisničkog panela, obavijesti i e-mailova.

Predložena dopuna: jedan proizvod i zajednička zaliha kroz jezike; odvojeni jezični nazivi, opisi, URL-ovi i SEO. Popis jezika, automatsko prevođenje i ponašanje kod nedostajućeg prijevoda još nisu dogovoreni. Jezik ne određuje automatski državu dostave, valutu ni porez.

### Naplata i dokumenti

- Stripe i PayPal.
- Plaćanje uplatom na transakcijski račun.
- Opcionalna e-racuni.com integracija: potvrđena naplata pokreće izdavanje računa.
- Opcionalna FIRA.finance integracija za istu svrhu.
- Sistemska ponuda s podacima za uplatu i HUB3/PDF417 barkodom, neovisno o vanjskoj integraciji. Dodatni QR format nije još definiran.

Predložena pravila: odabrana integracija izdaje dokument jednom po odgovarajućoj poslovnoj radnji; ponovljena obavijest o naplati ne smije duplicirati račun. Status naplate, dokumenta i isporuke odvojeni su. Zastoj računovodstvenog servisa ne poništava naplatu; ostavlja zadatak za ponovni pokušaj i vidljivu grešku. Definirati ručnu potvrdu virmana, povrate i korekcije računa. Izvor cijena i zalihe kod vanjske sinkronizacije mora biti izričito određen.

### Dostava i karte

- Odabir paketomata; BOX NOW i Hrvatska pošta su zasebne integracije.
- Izračun dostave prema aktiviranom prijevozniku, cjeniku i uvjetima.
- OpenStreetMap karte.

Predložena dopuna: zone, masa/dimenzije, besplatna dostava i doplate; dostupnost usluge prema sadržaju košarice. Izrada adresnica, tracking, pouzeće, osobno preuzimanje i međunarodna dostava još trebaju potvrdu. Dostavljačev ugovoreni trošak i cijena naplaćena kupcu nisu nužno jednaki. Ne pretpostaviti da svi prijevoznici nude API izračun cijene. OSM karta i autocomplete adresa su odvojene funkcije; pružatelj karata/geokodiranja ostaje otvoren.

### Storefront

- Vizualno kvalitetan, brz i prilagođen mobitelima.
- Četiri do šest tema.
- Filteri i live AJAX pretraga od početka.
- Zaštita od eksplozije URL kombinacija i masovnih zahtjeva prema pretrazi/filterima.

Predložena izvedba: zajedničke komponente i ista kupovna funkcionalnost kroz sve teme. Najprije jedna potpuna referentna tema, zatim ostale. Indeksirati proizvode, kategorije i odabrane SEO stranice; proizvoljne kombinacije filtera ne pretvarati automatski u indeksabilne stranice. Ograničiti dopuštene filtere, složenost zahtjeva i učestalost te koristiti indekse/cache. Opseg uređivanja sadržaja još nije odabran.

## 3. Ciljani stack

Verzije su provjereni ciljevi na datum dokumenta. Zajednički Composer skup razriješen je i testiran u CI-u. Instalirane verzije bilježe composer.lock i package-lock.json; nadogradnje zahtijevaju novu provjeru.

| Sloj | Cilj | Status |
| --- | --- | --- |
| PHP | 8.5.12 | Ciljani runtime |
| Laravel | 13.39.0 | Zahtjev za aktualni stabilni Laravel |
| Filament | 5.11.0 | Vlastiti administratorski panel |
| Livewire | 4.4.8 | Interaktivne komponente |
| Storefront | Blade + Livewire 4 + Alpine.js + Tailwind CSS 4 | Predloženo; Alpine kroz Livewire bez dvostrukog učitavanja |
| Commerce core | lunarphp/core 1.5.0 | Uvjetno: mora proći fazu 2 |
| Baza | PostgreSQL 18 | Predloženo; potvrditi ciljnu infrastrukturu |
| Pretraga | PostgreSQL FTS / pg_trgm i ciljano indeksiranje | Predloženi početni engine; izmjeriti na referentnom katalogu |
| Cache / queue | Redis 8 + Laravel queue; Horizon ako hosting dopušta | Predloženo; Linux workeri i nadzor procesa su preduvjet |
| Datoteke | Laravel Storage; odvojeni javni mediji i privatni downloadovi | Backend diska prema hostingu; S3 opcionalan |
| E-mail | Laravel Mail; SMTP2GO kao predloženi početni SMTP | Potvrditi konfiguraciju trgovca |
| Build | Vite + npm, Node aktivni LTS | Točne verzije zaključati pri instalaciji |
| Testovi i stil | PHPUnit/Pest kompatibilni s odabranim paketima; Laravel Pint | Razvojne ovisnosti zaključati u fazi 2 |

Produkcijski hosting nije dogovoren: Docker/VPS ili postojeći managed hosting. Zato web server, način deploya, queue supervision, backup i točne verzije servisa nisu konačno zaključani. Za Redis/Horizon trebaju odgovarajuće ekstenzije, radnik i nadzor; za PostgreSQL pdo_pgsql. Nije dovoljno odabrati pakete bez provjere hostinga.

Meilisearch nije početna obvezna usluga. Uvoditi ga ako zahtjevi referentnog kataloga i mjerenja opravdaju dodatnu uslugu po instanci ili pažljivo odvojenu zajedničku uslugu.

## 4. Paketi i vlastiti moduli

| Paket | Ciljana verzija | Svrha |
| --- | --- | --- |
| lunarphp/core | 1.5.0 | Trgovinski modeli, cijene, košarice, porezi, popusti i narudžbe |
| stripe/stripe-php | ^22.0.1 | Aktualni stabilni Stripe SDK; naš adapter prema trgovinskoj jezgri |
| paypal/paypal-server-sdk | ^2.4 | Aktualni stabilni PayPal SDK; naš adapter i zasebna provjera webhooka |
| laravel/fortify | 1.41.0 | Backend jednostavne prijave, registracije i oporavka računa |
| laravel/socialite | 5.31.0 | Google prijava |
| spatie/laravel-permission | 8.3.2 | Predložene administratorske uloge i ovlasti |
| spatie/laravel-activitylog | 4.12.3 | Audit; verzija unutar Lunarova zahtjeva ^4 |
| spatie/laravel-medialibrary | 11.23.11 | Slike i mediji |
| filament/spatie-laravel-media-library-plugin | 5.11.0 | Administratorska polja za medije |
| laravel/scout | 10.25.0 | Ovisnost Lunar corea unutar zahtjeva ^10; ne nameće Meilisearch |
| barryvdh/laravel-dompdf | 3.1.2 | Predloženi PDF renderer za ponude i interne dokumente |
| tecnickcom/tc-lib-barcode | 2.17.2 | Predloženi PDF417 renderer; HUB3 payload i validacija su naš modul |
| laravel/horizon | 5.50.1 | Uvjetno prema hostingu: rad i nadzor Redis queuea |

Zajednička Composer instalacija ovog skupa potvrđena je CI-jem. Lunar jezgra zahtijeva Activitylog 4 i Scout 10, iako postoje novije glavne verzije tih paketa. To ne zahtijeva stariji Laravel ili Filament, ali ograničava nadogradnje tih ovisnosti.

Lunarov paket lunarphp/lunar s gotovim adminom nije dio ciljanog stacka. lunarphp/table-rate-shipping 1.5.0 zahtijeva taj admin i zato ga zamjenjuju naša dostavna pravila. Plaćanja će koristiti naše adaptere uz aktualne stabilne SDK-ove, bez oslanjanja na Lunarov Stripe SDK 16.

Vlastiti moduli:

- Sudreg/OIB i poslovni podaci kupca, uz jasan fallback.
- e-racuni.com i FIRA adapteri, status izdavanja dokumenta i ponovni pokušaji bez duplikata.
- Virman, sistemska ponuda i HUB3/PDF417 podaci.
- BOX NOW, HP i budući prijevoznici; pravila cijene i dostupnosti dostave.
- Zaštićeni digitalni downloadovi.
- Korisnički panel, checkout i storefront teme.
- Prijevodi sadržaja kroz Lunarova jezična polja i vlastiti uređivač; sistemske poruke kroz Laravel language datoteke. Poseban translation plugin zasad nije potreban.
- SEO, jezični URL-ovi, kontrola filter URL-ova i zaštita pretrage.
- Postavke trgovine i integracija, s odvojenim ovlastima i šifriranjem tajni.

Kupovni browser tok koristi Laravel sesije. Zaseban OAuth server, API token paket i pretplatnički billing paket nisu zahtjev ovog početnog opsega. Administratorski MFA koristi nativne Filament mogućnosti; kupčev login ostaje jednostavan.

## 5. Faze rada

| Faza | Isporuka | Uvjet završetka |
| --- | --- | --- |
| 1. Zaključivanje opsega i pravila | Ovaj dokument, otvorene odluke, tokovi kupnje, naplate, dokumenta i isporuke; ciljna razvojna/produkcijska okolina | Kritične poslovne odluke dogovorene; potvrđeno što je dio prve verzije |
| 2. Provjera temelja | Čista instalacija stabilnog stacka, lock datoteke, migracije na odabranoj bazi, vlastiti Filament ekran i probna narudžba | Composer resolve i audit, migracije, boot i osnovni trgovinski scenariji prolaze bez uređivanja vendor koda; odluka o Lunaru |
| 3. Katalog i administracija | Jednostavni/varijabilni/digitalni proizvodi, atributi, cijene, zalihe, mediji, prijevodi, narudžbe i ovlasti | Administracija može pripremiti i voditi referentni katalog; import/export opseg potvrđen |
| 4. Cijeli tok kupnje | Referentni frontend, košarica, gost/prijava/Google, poslovni podaci, Sudreg, Stripe/PayPal/virman i digitalni pristup | Jedna narudžba može sigurno proći cijeli tok s ispravnom naplatom i zalihom |
| 5. Poslovne integracije | e-racuni.com/FIRA, ponude i HUB3, dostavna pravila i odabrani prijevoznici | Vanjski servisi imaju testirane greške, ponovne pokušaje i usklađene statuse; uključivanje/isključivanje po instanci |
| 6. UX, teme, pretraga i SEO | Dovršena prva tema i kupčev panel, filteri/live search, prijevodi, SEO; preostale teme na zajedničkim komponentama | Mobilni i desktop tokovi rade kroz aktivne jezike i teme; kontrolirani URL-ovi i izmjerene performanse |
| 7. Pilot i ponovljiva isporuka | Stvarni katalog, migracija po potrebi, staging, backup/obnova, deploy i rollback, prva klijentska instanca | Prihvatni testovi, provjerena obnova i kontrolirani postupak instalacije/nadogradnje druge instance |

Testiranje poslovnih tokova radi se u fazama njihove implementacije. Faza 7 nije prvi trenutak provjere naplate ili zalihe.

Obvezni scenariji:

- Dva kupca istodobno kupuju zadnji komad; istek neplaćene narudžbe oslobađa rezervaciju.
- Kupac nakon naplate zatvori preglednik; potvrda servisa i dalje ispravno ažurira narudžbu.
- Ponovljen ili zakašnjeli payment callback ne duplicira narudžbu, dokument niti izdavanje pristupa.
- Računovodstveni API ne radi nakon uspješne naplate; ponovni pokušaj ne izrađuje dva računa.
- Djelomičan povrat uz popust, porez i dostavu; povrat novca odvojen je od povrata robe na zalihu.
- Miješana digitalna/fizička košarica i gost s privatnim pristupom downloadu.
- Nedostupan Sudreg ili nedostupan paketomat; kupac ima razumljiv nastavak kupnje.
- Promjena profila, kataloga ili prijevoda ne prepisuje povijest narudžbe.
- Filtri i pretraga pod opterećenjem; bez neograničenih kombinacija URL-ova i neograničeno skupih upita.
- Obnova baze, medija i potrebnih aplikacijskih ključeva te povrat prethodne verzije aplikacije uz kompatibilnu bazu.

## 6. Otvorene odluke

1. Gdje će se razvijati i hostati prva instanca: Linux/Docker/VPS ili postojeći managed hosting? To utječe na bazu, queue, deploy i backup.
2. Bez e-racuni.com/FIRA integracije: izdaje li trgovac konačni račun izvan aplikacije ili tvoj.shop treba vlastito izdavanje računa? Vlastito izdavanje bitno proširuje funkcionalni opseg.
3. Sadržaj i teme: pripremljene sekcije s uređivanjem sadržaja/redoslijeda ili slobodan vizualni builder?
4. Točan popis sistemskih jezika; ručni prijevodi ili automatski prijevod uz pregled; ponašanje kod nedostajućeg prijevoda.
5. Dostava: Hrvatska ili odmah druge države; početni prijevoznici; pouzeće i osobno preuzimanje; adresnice/tracking/povrati u prvoj verziji.
6. Referentna trgovina, okvirni broj proizvoda/SKU-ova i potreban početni import iz WooCommercea.
7. Kod povezivanja s vanjskim poslovnim sustavom: tko je izvor istine za cijene, zalihe, proizvode i statuse uplata?
8. Osnovne dodatne funkcije za razvrstavanje u prvu/kasniju verziju: kuponi/akcije, recenzije, wishlist, newsletter/marketing i podatkovni feedovi. Nisu potvrđene samo zato što su uobičajene u drugim webshopovima.

Dok su odluke otvorene, ne započinjati nepovratno vezivanje teme, naplate ili produkcijskog hostinga uz pretpostavljeni odgovor. Neovisna priprema i tehnička provjera mogu napredovati.

## 7. Izvori verzija i ključnih ograničenja

- Laravel: https://github.com/laravel/framework/releases/latest
- Filament: https://github.com/filamentphp/filament/releases/latest
- Livewire: https://github.com/livewire/livewire/releases
- PHP: https://www.php.net/downloads
- Lunar stable core manifest: https://github.com/lunarphp/core/blob/1.5.0/composer.json
- Lunar Stripe: https://github.com/lunarphp/stripe/blob/1.5.0/composer.json
- Lunar PayPal: https://github.com/lunarphp/paypal/blob/1.5.0/composer.json
- Lunar dostavni paket: https://github.com/lunarphp/lunar/blob/1.5.0/packages/table-rate-shipping/composer.json
- Lunar roadmap: https://docs.lunarphp.com/logs/flight-plan
- Paketi: https://packagist.org/packages/laravel/fortify ; https://packagist.org/packages/laravel/socialite ; https://packagist.org/packages/laravel/horizon ; https://packagist.org/packages/laravel/scout
- Spatie paketi: https://packagist.org/packages/spatie/laravel-permission ; https://packagist.org/packages/spatie/laravel-activitylog ; https://packagist.org/packages/spatie/laravel-medialibrary
- Filament media: https://packagist.org/packages/filament/spatie-laravel-media-library-plugin
- PDF i barkod: https://packagist.org/packages/barryvdh/laravel-dompdf ; https://packagist.org/packages/tecnickcom/tc-lib-barcode
- Pretraga: https://www.postgresql.org/docs/18/textsearch.html ; https://www.postgresql.org/docs/18/pgtrgm.html
- Filtri i crawling: https://developers.google.com/crawling/docs/faceted-navigation
- Sudreg API: https://data.gov.hr/ckan/dataset/sudski-registar
- FIRA API: https://fira.finance/hr/funkcionalnosti/api/
- e-racuni.com: https://s2.e-racuni.com/hrracuni/za-web-trgovine/
- HP API: https://www.posta.hr/hp-shipping-service-api-tehnicka-dokumentacija
- BOX NOW API: https://www.boxnow.hr/diy/eshops/tailor-made
- HUB3/PDF417: https://www.hub.hr/hr/hub-3-obrasci
- OSM usluge: https://operations.osmfoundation.org/policies/tiles/ ; https://operations.osmfoundation.org/policies/nominatim/

## Tocka nastavka nakon dogovorenog prekida

Kod prvog dijela faze 3: `bbccd1f`. Svi rezultati i preostali opseg navedeni su iznad. Privremena lokalna PostgreSQL instanca u `/tmp/oneshop-runtime/pgdata` uredno je zaustavljena; sistemski servisi nisu mijenjani. Ubuntu prijavljuje potreban restart. Instalacija Dockera koju korisnik priprema jos nije potvrdjena u ovoj sesiji. Nakon restarta najprije provjeriti Git status i zadnji CI, dostupnost Dockera/Composea te nastaviti preostale dijelove faze 3. Privremeni PHP/PostgreSQL runtime nije trajna instalacija i direktorij `/tmp` ne treba smatrati trajnim.
