# OneShop: funkcionalnosti, ciljani stack i plan rada

Datum: 29. 10. 2026.
Status: faza 2 zavrsena; katalog i vlastiti admin imaju razvojni storefront s varijantama, kosaricom, dostavom, checkoutom i simulatorom placanja. Faza 4 nije prihvacena za stvarnu naplatu; Astra pregled i poslovne odluke ostaju za jutro.

Najnoviji ponovljivi browser dokaz: `fd21334`, [Browser journey 40964061196](https://github.com/eniofrimer/oneshop/actions/runs/40964061196), desktop/mobilna emulacija/no-JS na svjezoj izoliranoj Docker instalaciji. Glavni CI `40964060782` i image smoke `40964060740` su zeleni. Upute: [BROWSER_TESTING.md](BROWSER_TESTING.md).

Administratorski popis sada pretrazuje primarni naziv i aktivne SKU-ove, ukljucujuci nacrte, bez SQL wildcard tumacenja korisnickih znakova. Objava/status i public scope nisu promijenjeni; javno dostupan proizvod ima poveznicu iz editora u trgovinu. Stvarni Chromium login superadmina i osam admin ekrana vratili su 200, bez JS gresaka/prelijevanja. Vanjski avatar zamijenjen je lokalnim SVG-om; ponovljena provjera nema zahtjeva drugim domenama. Lokalno 23 vezana search/catalog testa / 222 assertions i 12 publication testova / 86 assertions, Pint i Larastan prolaze.

`9cf9861` ima zelene glavni CI `40965305036`, immutable Docker `40965304968` i browser `40965305031` (14 axe provjera + tri kupovna scenarija). Lokalni puni skup je 167 testova / 1405 assertions. Stvarni PDF narudzbe `00000001` ponovno je generiran, pretvoren u sliku i vizualno pregledan: jedna A4 stranica, hrvatski znakovi/adrese i 45 EUR ukupno, bez rezanja teksta. Poppler je izdvojen samo u privatni projektni runtime; sistemski paketi nisu mijenjani.

Pripremljene su cetiri teme `everyday`, `atelier`, `studio`, `market` s razlicitim uvodom, tipografijom i karticama, uz zajednicke kupovne obrasce. Preview ostaje na referentnoj temi. Lokalni browser je prosao jos 30 axe provjera kroz tri nova izgleda na desktopu i mobilnoj emulaciji; screenshotovi su vizualno pregledani. Dva integracijska testa / 84 assertions potvrduju iste podatke/kosaricu i siguran fallback neispravne konfiguracije. Cijeli browser CI prosiren je na cetiri izolirana posla; nova puna provjera tek slijedi. Ovo nije zatvaranje odluke o slobodnom vizualnom builderu. Upute: [THEMES.md](THEMES.md).

Teme su potvrdjene za `2cba62d`: glavni CI `40966564278`, image smoke `40966564329` i sva cetiri browser posla `40966563807` su zeleni (12 kupovnih scenarija, 56 axe provjera). Katalog sada koristi postojece GD konverzije 500/800 px i responsive srcset, s manjim slicicama galerije i originalom na detalju. Pending konverzije sigurno koriste javni original; zasebni privatni conversion disk se ne iznosi. Lokalno 18 photo/storefront testova / 140 assertions te Pint/Larastan prolaze, ukljucujuci stvarno generirane 500/800 px datoteke i fallback.

`f89bd7c` ima zeleni glavni CI `40967678292`; prethodni image smoke `40967312319` potvrdjuje responsive fotografije. Lokalni hrvatski prijevodi pokrivaju sve kljuceve Laravel 13 validation skupa, auth/password/pagination i nazive javnih/admin polja; dodatni jezici nisu izmisljeni. Pogresan tip posted polja (array umjesto stringa/broja) reproducirao je 500 na ponovnom prikazu proizvoda/adrese. Novi scalar-only prikaz cuva valjane stare unose i pokazuje lokalnu poruku/ARIA vezu uz neispravno polje. Oba testa punog redirect toka sada prolaze; stvarni Chromium je prosao neispravnu e-postu, fokus sazetka i nastavak kupnje, uz 15 axe provjera. Novi browser CI to provjerava kroz sve teme; puna provjera slijedi.

`bc5d549` je potvrdjen zelenim CI-em `40969153252`, image smokeom `40969153167` i browserom `40969153497` (sve cetiri teme, 60 axe provjera, 12 kupovnih scenarija). Lokalno 21 vezan checkout/cart/theme test / 399 assertions. Sljedeca neovisna provjera je izolirana obnova sinteticke baze, medija i instalacijskog kljuca na oba enginea: [RECOVERY_REHEARSAL.md](RECOVERY_REHEARSAL.md). Ne dira preview ni stvarne trgovceve podatke; produkcijski backup/host i RPO/RTO ostaju otvoreni.

Recovery `40969791509` za `6d48fa6` je zelen na oba enginea: 72 tablice / 236 redaka / osam datoteka, ispravan kljuc/sesija/MFA, privatni download, PDF, unique indeks i ID slijed; pogresan kljuc odbijen. Artefakti su pregledani. Glavni CI `40969791820` prosao je MariaDB, a PostgreSQL otkrio nedeterministicki redoslijed u starom audit testu arhive (172/173 testova prosla). Dodan je ORDER BY id pri usporedbi kronoloskih audit nizova u testovima arhive, objave i datoteka; aplikacijsko ponasanje nije mijenjano. Puni ponovni CI slijedi.

## Potvrdjeni novi zahtjevi: hosting i vlasnicki pristup

- Projekt **mora raditi u Dockeru, cPanelu i FastPanelu** (korisnik, 29. 10. 2026.). Docker nije obvezan za instalaciju na hosting panelu. Upute, profili i stvarna razina provjere su u [DEPLOYMENT.md](DEPLOYMENT.md).
- Superadmin mora imati sve ovlasti i pristup bez obveznog 2FA-a. `oneshop:create-admin --super-admin` daje tu izricitu ulogu; ostali administratori zadrzavaju obvezni MFA. Flag nije dopusten kroz korisnikov mass assignment.
- Podrzan je native MariaDB 10.11+ profil uz postojeći PostgreSQL 18 profil. Queue, cache i sesije mogu koristiti bazu. Horizon je razvojna ovisnost i nije uvjet native produkcije. Napredna pretraga provjeravat ce se zasebno na oba enginea.
- [CI 40918117207](https://github.com/eniofrimer/oneshop/actions/runs/40918117207), commit `a25b208`, potpuno je zelen: **48 testova / 289 assertions na svakom engineu**, Pint, Larastan, audit, build, migracije i hosting readiness. Native release bez razvojnih ovisnosti/Horizona izgradjen je i provjeren te spremljen kao artefakt `native-release-a25b208ad5a8ce196b07104360d95b0f98f3a27a`. Zakljucane verzije paketa nisu nadogradjivane; Horizon/Sentinel pravilno su reklasificirani u razvojne ovisnosti.
- Ispravak identiteta okoline: agent radi na hostu `codex` (sada stalni `192.168.250.2`), a korisnikov Ubuntu terminal je `WEB-DEV` (172.23.228.190). Raniji nazivi "VM" nisu dokaz da je agent radio na WEB-DEV. Korisnik je potvrdio Docker CE 29.9.2 / Compose 5.6.1 na WEB-DEV, ali direktorij projekta ondje jos ne postoji. Agentov SSH pokusaj na WEB-DEV zavrsio je timeoutom. Korisnik je potom odabrao pregled u postojecoj razvojnoj okolini, pa za sada ne treba kloniranje/pokretanje na WEB-DEV. Ta VM se ne predstavlja kao instalirana aplikacijska instanca.

## Aktivni backend za pracenje razvoja

- Razvojna aplikacija radi na **http://192.168.250.2:8080/admin/login** u izvrsnoj okolini `codex`. Korisnik je potvrdio da se prijava otvara u poslanom Codex prikazu.
- Stvarno je izradjen **admin@oneshop.local**, uloga superadmin, sve aplikacijske ovlasti, bez obveznog 2FA-a. Lozinka je nasumicno generirana i predana samo u chatu, nije zapisana u dokumentaciju/Git; privremena datoteka s ispisom uklonjena je nakon provjere.
- Izvrsen je stvarni HTTP/Livewire password login, a zatim autentificirani GET `/admin` i `/admin/products`: oba HTTP 200, bez MFA preusmjeravanja. Nije rijec samo o `actingAs` testnoj sesiji.
- Odvojena MariaDB baza `oneshop_preview`; podaci su u ignoriranom `storage/app/private/preview-database`. Integracijski testovi koriste `oneshop_test` i ne resetiraju preview. Baza slusa samo privatni Unix socket; HTTP server slusa stalnu VM adresu `192.168.250.2:8080`. Prijava vraca 200, a admin putanje preusmjeravaju na tu adresu. Nakon promjene IP-a dostupnost je provjerena iz VM-a, ne neovisno iz Windowsa.
- Ovo je razvojni pregled. PHP 8.5 je obnovljen u ignoriranom projektnom `storage/app/private/runtime`, jer je `/tmp` runtime nestao nakon restarta. Podaci baze takodjer su izvan `/tmp`. Baza, HTTP pregled i queue worker sada su omoguceni korisnicki systemd servisi; kontrolirani restart cuva podatke i sesije. Puni restart VM-a nije izveden. Native produkcijski paket je u CI artefaktu; stvarna cPanel/FastPanel instalacija i deploy Docker paketa na odabrani host ostaju uvjeti zasebne provjere.

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

Dodane su ponovno upotrebljive opcije i prevedene vrijednosti na `/admin/options`, odabir/redoslijed opcija na proizvodu i jedna vrijednost po opciji pri spremanju SKU-a. Duple kombinacije, vrijednosti drugih opcija i uklanjanje opcija koje SKU koristi odbijaju se uz validacijsku gresku. Dodavanje opcija vec objavljenom proizvodu s varijantama nije dopusteno; nacrti mogu privremeno biti nepotpuni dok se pripremaju. Spremanje zakljucava roditeljski proizvod kako istodobne izmjene kroz editor ne bi napravile dvije iste kombinacije. Dropdown/radio je preferencija prikaza za buduci storefront; multi-select dodaci nisu SKU kombinacije i jos nisu implementirani.

Javna galerija dodana je na proizvod: JPEG/PNG/WebP, do 5 MB i 4096 × 4096 px, do 10 fotografija. Podrzani su prijevodi alternativnog teksta, naziv, redoslijed, naslovna slika i brisanje. Servis provjerava ovlasti i vlasnistvo pri svakoj izmjeni, zakljucava proizvod za limit/redoslijed i odbija zastarjele ili tudje ID-eve. Privatne kolekcije nisu dostupne kroz galeriju. GD konverzije koriste postojeci queue profil, uz prikaz originala dok thumbnail nije gotov. Docker setup sada izradjuje relativni javni storage link. Devet integracijskih testova / 67 assertions ukljucuje stvarni upload kroz modal i generiranje GD thumbnaila, ogranicenja tipa/velicine/dimenzija, prijevode, naslovnu sliku i zastitu drugih proizvoda/privatnih kolekcija.

[CI 40938704955](https://github.com/eniofrimer/oneshop/actions/runs/40938704955), commit `ea18ca4`, potvrdjuje opcije na obje baze, Pint, Larastan i native release.

Rucne kategorije dodane su na `/admin/categories`: imenovane grupe, prijevodi, nadredjene kategorije i premjestanje unutar grupe. Promjene stabla serijaliziraju se zakljucavanjem grupe prije njezinih cvorova; vlastiti potomak, druga grupa i nepostojeci roditelj daju validacijsku gresku. Veze proizvoda cuvaju automatske kolekcije i postojece pozicije proizvoda. Zajednicki editor prijevoda cuva neaktivne jezike i dodatne atribute za proizvode i kategorije. Deset integracijskih testova potvrduje stvarne Filament forme, stvaranje grupe u modalu, integritet stabla, URL-ove, ovlasti i pravila clanstva. Larastan dobiva ograniceni genericki stub za postojeci Lunar nested-set builder; vendor kod nije izmijenjen i greske nisu ignorirane.

[CI 40939734291](https://github.com/eniofrimer/oneshop/actions/runs/40939734291), commit `555c4d8`, potpuno je zelen na PostgreSQL-u i MariaDB-u za galeriju, uz audit, build i native release.

Pregled zaprimljenih narudzbi dodan je na `/admin/orders`: popis/filter statusa, povijesne adrese i iznosi, stavke i transakcije. Nacrti kosarica nisu prikazani. Iznosi se citaju iz sirovih spremljenih najmanjih jedinica i prikazuju tocno prema valuti narudzbe, bez float pretvorbe ili fallbacka na danasnju osnovnu valutu. Ne izracunavaju se danasnje cijene i ne prikazuje proizvoljan payment metadata. Nema akcija izmjene, naplate, povrata ili brisanja. Osam integracijskih testova potvrduje povijesne vrijednosti, opseg transakcija, ovlasti/MFA i ponovnu autorizaciju nakon ukidanja ovlasti.

[CI 40941110110](https://github.com/eniofrimer/oneshop/actions/runs/40941110110), commit `2c095d8`, potpuno je zelen na oba enginea za kategorije i native release. Lokalno nakon kategorija: 79 testova / 506 assertions, Pint i Larastan.

Privatne digitalne datoteke pripremaju se na `/admin/digital-assets` za SKU bez fizicke dostave. Do 10 MB, zasebni nepromjenjivi zapisi s SHA-256, nasumicne privatne putanje izvan javnog direktorija, arhiviranje/reaktivacija bez brisanja sadrzaja i provjera integriteta prije administratorskog preuzimanja. Novi upload ne zamjenjuje stare datoteke. Audit biljezi autora/operaciju bez privatnih putanja/checksumova. Kupceva prava pristupa nakon naplate nisu implementirana i upload ih ne dodjeljuje. Dvanaest testova pokriva stvarni Filament upload/download, arhivu, nedostajuci/promijenjeni sadrzaj, rollback datoteke, ovlasti, audit i arhivirane SKU-ove.

Stvarno pokretanje nove migracije na postojecem previewu otkrilo je da Lunarov opcionalni legacy brand import ocekuje `null` kad nema privremene datoteke. Samo `local` disk vracen je na Laravelov nenametljivi read fallback; zasebni `private` disk zadrzava `throw=true`. Tri nova testa potvrduju ponovljenu migraciju inicijalizirane trgovine, obradu stvarne legacy datoteke i strogi privatni disk. Nadogradnja preview baze nakon ispravka prolazi bez resetiranja podataka. Runtime check sada provjerava upload limite, upisive diskove i link iskljucivo na javne medije; CI izradjuje relativni link.

[CI 40942470845](https://github.com/eniofrimer/oneshop/actions/runs/40942470845), commit `9bfa272`, potpuno je zelen na oba SQL enginea za pregled narudzbi. Lokalno: 87 testova / 570 assertions, Pint i Larastan.

Kontrolirana objava dodana je na uredjivanje proizvoda: akcija provjerava spremljene podatke i trazi izricit odabir kanala i grupa kupaca. Obvezni su osnovni naziv, SKU, jednoznacna osnovna EUR cijena, podrzane jedinicne kolicine i potpune jedinstvene kombinacije. Vise SKU-ova zahtijeva opcije za razlikovanje. Digitalni SKU treba aktivnu datoteku koja postoji na privatnom disku. Odabrane veze postaju dostupne odmah; ostale se iskljucuju bez odspajanja i gubitka postojeceg pivot rasporeda. Povratak u nacrt cuva cijene, zalihu i povijest; oba postupka imaju audit. Dvanaest integracijskih testova / 84 assertions pokriva stvarne Filament akcije, poruke pogresaka, preduvjete, odabir publike i povlacenje.

Arhiviranje i vracanje SKU-ova dodano je na tablicu varijanti, uz filter arhive i uvjet da je proizvod nacrt. Soft-delete cuva cijene, opcije, slike, privatne datoteke i povijest narudzbe. Vracanje odbija duplu aktivnu kombinaciju. Nakon arhiviranja starih SKU-ova mogu se ukloniti njihove opcije s nacrta; arhivirane vrijednosti ostaju sacuvane. Sest integracijskih testova potvrdjuje puni Filament ciklus, povijest, opseg i duple kombinacije.

Faza 3 jos nije zavrsena. Slijede detaljnije ovlasti i potvrda referentnog kataloga/import opsega. Digitalna oznaka i pripremljene datoteke ne predstavljaju isporucen kupcev download sustav. Ovaj editor ne brise proizvode; novi nacrti objavljuju se samo kroz zasebnu provjerenu akciju. Opseg importa/exporta i referentni katalog ostaju poslovne odluke iz odjeljka 6.

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

Docker, cPanel i FastPanel su obvezni ciljevi isporuke. Konkretna prva instanca, domena i pristupni podaci jos nisu dostavljeni. Zato web server, način deploya, queue supervision, backup i točne verzije servisa nisu konačno zaključani. Za Redis/Horizon trebaju odgovarajuće ekstenzije, radnik i nadzor; za PostgreSQL pdo_pgsql. Nije dovoljno odabrati pakete bez provjere hostinga.

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

Kupovni browser tok koristi Laravel sesije. Zaseban OAuth server, API token paket i pretplatnički billing paket nisu zahtjev ovog početnog opsega. Administratorski MFA koristi nativne Filament mogućnosti uz izričitu superadmin iznimku bez obveznog 2FA-a; kupčev login ostaje jednostavan.

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

1. Koja je konkretna prva ciljna instanca i domena unutar obvezne podrske za Docker, cPanel i FastPanel? Za taj host treba provjeriti PHP, bazu, cron/worker i backup.
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

## Sljedeci nastavak

Pregled ostaje na `http://192.168.250.2:8080/admin/login`, PHP runtime i zasebna MariaDB baza nalaze se u ignoriranom `storage/app/private`. Aktivni i enabled su `oneshop-preview-database`, `oneshop-preview-http` i `oneshop-preview-worker` korisnicki servisi; provjeren je postojeci Linger=yes. Testovi koriste samo `oneshop_test`; ne resetirati preview bazu. Prije nastavka provjeriti Git status i CI zadnjeg commita. Detaljnije ovlasti, referentni katalog i import/export opseg jos nisu prihvaceni kao gotovi; kupceva prava na digitalni download i stvarna naplata pripadaju fazi 4. Stvarna cPanel/FastPanel instalacija i produkcijski Docker ostaju uvjeti provjere na ciljnom hostu.

## Nocni cilj do 30. 10. 2026. u 08:00 Europe/Zagreb (07:00 UTC)

Korisnik je zatrazio autonomni rad do jutra, uz preskakanje kriticnih otvorenih pitanja i redovite provjere/commite/push/dokumentaciju. Cilj je funkcionalan razvojni MVP: backend proizvoda, naplate i dostave; osnovni frontend s nekoliko demonstracijskih proizvoda (najmanje jednim varijabilnim), kosaricom i checkoutom.

- Postojeci katalog dovrsava se provjerenom objavom/arhivom, zatim slijede javni katalog i gostujuca kosarica. Javna kupnja ne koristi administratorski identitet za kupca.
- Produkcijski checkout mora imati atomske rezervacije zalihe, zamrznute iznose/adrese, ponovljivu obradu i jasne statuse. Korisnik je naknadno izricito odgodio dijelove za koje je preporucena Astra do jutra: sigurnosnu provjeru naplate/zaliha i ukljucivanje stvarnog placanja. Nocni razvojni checkout i simulacija nisu odobrenje za live trgovinu.
- Priprema se konfigurabilna dostava i testni tok naplate. Bez kljuceva stvarna karticna autorizacija ne moze se potvrditi; testni adapter i simulacija moraju biti jasno oznaceni i sigurnosno odvojeni od live nacina.
- Demonstracijske cijene, porez, proizvodi i dostava nisu potvrdjeni trgovcevi poslovni zahtjevi. Seed je izricit i razvojni; ne prepisivati postojece trgovceve podatke.
- Za jutro ostaju stvarni racuni/kljucevi naplate, odabir produkcijskog hosta/domene, trgovceva porezna i dostavna pravila, racuni i vanjske integracije. Ta pitanja ne blokiraju neovisni razvoj i testiranje.

### Javni katalog i kosarica — 29. 10. 2026. navecer

Implementiran je responzivni hrvatski katalog, detalj proizvoda s konkretnim kombinacijama SKU-ova i gostujuca kosarica. Sesija drzi ID kosarice; posted ID/cijena/korisnik se ne koriste. Provjeravaju se objava, kanal, dostupna grupa, raspored, osnovna EUR cijena, kolicine i zaliha. Uklanjanje ostaje moguce nakon povlacenja ili arhive artikla. Administratorska prijava ne povezuje gosta s administratorom. Objava sada izricito omogucava kupnju odabranim grupama (i iskljucuje je ostalima).

`oneshop:demo-catalog` izricito radi samo u local/testing okolini i praznom katalogu bez poreznih postavki: 3 demo proizvoda, 6 SKU-ova, Majica Osnova u dvije boje i dvije velicine. Ponovno pokretanje cuva trgovceve izmjene i zalihu. Testni porez 25% nije prihvacena trgovceva porezna politika. SVG ilustracije su vlastiti staticki demo resursi; upload trgovcevih SVG datoteka nije omogucen.

Preview baza popunjena je demo podacima. HTTP iz VM-a: naslovnica, detalj i admin login 200 na stalnom `192.168.250.2:8080`. Windows dostupnost i vizualna provjera u pregledniku u ovoj fazi nisu neovisno provjerene. Checkout slijedi u zasebnom koraku.

### Konfigurabilna dostava — 29. 10. 2026. navecer

Nova administracija Dostava: naziv/opis, dozvoljene drzave, EUR cijena, porezna klasa i ukljucivanje. Cijene su cijeli najmanji novcani iznosi; obrazac slijedi postavku ukljucenog/iskljucenog poreza trgovine. Zastarjeli otvoreni obrazac ne moze prepisati noviju tarifu. Lunar racuna porez i zapisuje naziv/iznos u povijesnu narudzbu. Promjena ili iskljucenje metode odmah mijenja nove ponude; vec zapisane narudzbe ostaju iste. Nema brisanja metoda iz admina.

`oneshop:demo-shipping` je izricit local/testing fixture: HR, EUR 4 osnovica + EUR 1 testnog poreza. Ponovno pokretanje cuva izmjene. Ne salje posiljke dostavljacu niti potvrduje trgovceve rokove/tarife. Testovi pokrivaju Filament obrazac, reviziju tarife, porez, povijesne vrijednosti, drzave/iskljucenje, ponovnu rezoluciju vise kosarica i ovlasti. CI javnog kataloga `0cd6a1a`, run `40950117088`, zelen je za PostgreSQL i MariaDB.

### Razvojni checkout i pregled placanja — 29. 10. 2026. navecer

Izveden je razvojni put adresa → pregled poreza/dostave/iznosa → narudzba → zaseban simulator uspjeha/odbijanja/otkazivanja. Iznos je izracunat na serveru, promjena cijene/tarife trazi novi pregled, narudzba cuva adrese i stavke. Ponovljena predaja i potvrda ne stvaraju dvostruke narudzbe/uspjesne transakcije. Testna zaliha zakljucava se i umanjuje jednom pri uspjehu; cekanje nema rezervaciju i ne predstavlja live model poravnanja.

U administraciji je uveden zaseban pregled Placanja i oznaka testnih narudzbi. Sirovi payloadi i pristupni podaci se ne prikazuju. Simulator je blokiran u production okolini bez obzira na zastavicu. Stvarni procesori, rezervacije za vanjsku naplatu i digitalna prava ostaju odgodjeni za Astra pregled po izricitoj korisnikovoj uputi. Detalji i ogranicenja: `docs/DEVELOPMENT_CHECKOUT.md`.

HTTP proba iz VM-a prosla je s pravim cookiejem i CSRF-om: jedna varijanta majice + jedna salica + dostava = 45 EUR, predaja, simulacija uspjeha i ponovni pregled potvrde. Windows preglednik nije neovisno provjeren. CI dostave `7a6071d`, run `40950935519`, zelen je na obje baze.

Lokalna provjera ovog inkrementa: **146 testova / 1161 assertions**, Pint, Larastan i frontend build prolaze. Native readiness za FastPanel prolazi; to nije provjera stvarnog panela. Preview HTTP kupnja zapisala je jednu oznacenu demo narudzbu i jednu demo transakciju, s pravilnim umanjenjem po jednog komada majice i salice.

### Samostalan Docker paket — priprema

Dodani su zakljucani `production-app` i `production-web` image targeti te zaseban Compose profil s bazom, workerom i schedulerom. Web dobiva samo javne resurse i javni media volume; privatne datoteke imaju zaseban volume. Nema izvornog bind mounta ni automatskog mijenjanja baze/kljuceva u entrypointu. Aplikacijski kod je read-only, tajne su pojedinacni Compose secret fileovi iz ignoriranog direktorija. Novi CI provjerava stvarno podizanje imagea i HTTP granice, ukljucujuci odbijanje PHP-a u uploadu i privatnih datoteka. Lokalno su provjereni YAML i shell sintaksa; Docker daemon u ovoj VM nije dostupan. Stvarni target host, TLS/proxy, mail i backup ostaju uvjeti prihvata. Detalji: `docs/DEPLOYMENT.md`.

Docker image smoke test `40953742866` za `36f5499` potpuno je prosao, ukljucujuci stvarno podignute app/web/worker/scheduler kontejnere, bazu i HTTP probe. Glavni CI `40953742964` takodjer je zelen na obje baze nakon ispravka deterministickog SKU mapiranja (146 testova / 1161 assertions na svakom engineu).

### Izbor opcija i javna galerija u trgovini

Detalj sada prikazuje odvojeni odabir boje/velicine prema admin preferenciji dropdown/radio, pronalazi tocnu SKU kombinaciju, mijenja prikaz cijene i odbija nepotpunu/nepostojecu/rasprodanu kombinaciju. Server i dalje sam validira SKU i cijenu. Bez JavaScripta ostaje funkcionalan izbor konkretne kombinacije. Opcije ne ukljucuju vrijednosti drugih proizvoda/arhiviranih/neocijenjenih SKU-ova. Galerija prikazuje redoslijed i prevedeni alt tekst javnih fotografija; privatni mediji su iskljuceni. JSON i tekst su escaped. Node testovi provjeravaju kombinacije, a integracijski testovi prikaz, opseg i privatne slike. Vizualna/browser-interaction provjera jos nije neovisno izvedena.

Prikaz proizvoda sada ucitava cijene, porezne klase i opcijske vrijednosti grupno. Regresijska provjera povecava proizvod s 4 na 24 SKU-a bez rasta broja upita po svakom SKU-u (ispod 30 ukupnih upita za prikaz/odabir u tom scenariju). Detalj ponovno koristi vec izracunate cijene pri gradnji kontrole opcija. Iskljucena EUR valuta daje nedostupnu ponudu i razumljivu gresku kosarice umjesto pada stranice. Lokalno 23 vezana integracijska testa / 345 assertions te Pint/Larastan prolaze; ovo nije benchmark referentnog produkcijskog kataloga.

### Pocetni pregled administracije

Dashboard sada pokazuje broj proizvoda i njihovih nacrta/objava, aktivne SKU-ove i one s pracenjem bez zalihe, zaprimljene narudzbe s brojem testnih te aktivne metode dostave. Kartice vode na pripadajuce module; izbornik dobiva poveznicu na trgovinu. Nema automatskog periodickog upita. Widget trazi sve pripadajuce read ovlasti i ponovno ih provjerava na Livewire zahtjevu nakon ukidanja pristupa. Lokalni testovi stvarnog dashboarda i autorizacije prolaze.

### Trajni razvojni procesi i dokaz queue izvrsavanja

Na VM-u `codex` provjereni su aktivan user systemd i vec ukljucen `Linger=yes`; linger postavka nije mijenjana. Instalirani su i enabled samo projektni `oneshop-preview-database`, `oneshop-preview-http` i `oneshop-preview-worker` servisi iz `deploy/systemd`. Stara projektna baza zaustavljena je uredno prije preuzimanja u servis; nisu dirani mreza, firewall, drugi servisi ili druge baze. HTTP ostaje na istoj stalnoj adresi.

Kontrolirani restart HTTP-a i baze zadrzao je istu sesiju kosarice, 1 demo narudzbu, 1 transakciju i zalihe (19/19 na kupljenim SKU-ovima); naslovnica, prijava i health vracaju 200. Puni restart VM-a nije izveden radi drugih projekata. `oneshop:probe-queue` poslao je bezazlen posao koji je zasebni stvarni worker izvrsio i potvrdio kroz zajednicki cache. Read-only `oneshop:check-runtime` ostaje bez pisanja; novi probe je izricita zasebna operacija. Docker smoke dobiva isti dokaz workera i provjeru podataka nakon kontroliranog restarta.

### Interni PDF sazetak narudzbe

Admin detalj narudzbe dobiva preuzimanje PDF sazetka iz povijesnih adresa, stavki i iznosa. Dokument je jasno oznacen kao interni informativni pregled, a demo narudzba kao test bez stvarne naplate/dostave; nije racun ili HUB3 ponuda. Ne ucitava aktualne cijene/profil niti prikazuje privatni payment metadata/napomene. Ovlasti se ponovno provjeravaju u servisu; draft narudzbe se ne izvoze. HTML je escaped, a remote/PHP/JavaScript u PDF engineu iskljuceni. Font/temp rad koristi upisivi framework cache i radi uz read-only source u Dockeru.

Lokalno prolazi **159 testova / 1281 assertions**, Pint i Larastan. Stvarni PDF demo narudzbe 00000001 generiran je privatno (PDF 1.7, oko 879 KB). Prolazi i stvarno Filament preuzimanje u integracijskom testu. Vizualni PDF pregled nije izveden jer renderer nije dostupan. Docker smoke sada dodatno generira autorizirani PDF u read-only imageu; rezultat tog prosirenja treba pratiti.

CI `40958016166` i Docker smoke `40958016174` za `7c27ee3` potpuno su zeleni. Prosireni Docker smoke dokazao je stvarno izvrsavanje queue posla prije i nakon kontroliranog restarta te cuvanje javnih/privatnih volumena. CLI exec koristi entrypoint za ucitavanje mounted secreta; sama docker exec naredba ne nasljedjuje tajne iz FPM procesa.

### Osnovna live pretraga i filter dostupnosti

Storefront ima pretragu naziva na primarnom jeziku i SKU-a, filter dostupnih artikala, paginaciju i HTML/JSON odgovore iz istog ogranicenog public upita. AJAX ceka kratku stanku u unosu, prekida stare zahtjeve i ne dopusta da zakasnjeli odgovor pregazi noviju pretragu. Bez JS-a radi obicna GET forma. Objavljivanje, kanal/grupa i arhiva vrijede i za rezultate/broj rezultata. `%`, `_` i escape znak tretiraju se doslovno.

Dopusteni su samo q/available/page; q je 2–60 znakova kada nije prazan, stranica 1–100, a pretrage imaju ogranicenje 45 zahtjeva/min po IP-u. Parametarske kombinacije nisu indeksabilne (razvojni noindex ostaje). Koristi se portabilni SQL LIKE nad nazivom/SKU-om, bez zahtjeva za PG ekstenzijama. To nije prihvat naprednog FTS-a niti opterecenja referentnog velikog kataloga; taj opseg/performance gate ostaje otvoren.

Naknadno je pronadjen Chromium u ovoj VM i pokrenuta stvarna browser provjera: desktop 1400px, mobilna emulacija 390px i browser bez JS-a. Sva tri puta zavrsila su testnom kupnjom (55/30/20 EUR); desktop je ukljucio odbijanje i ponovni pokusaj. Pretraga je mijenjala rezultate bez reload-a, varijante su se odabrale, kolicina je promijenjena, adrese/dostava/porez pregledani i placanje simulirano. Nema JS gresaka ni vodoravnog overflowa u provjerenim prikazima. Vizualno su pregledani desktop/mobilna naslovnica i mobilni checkout. Ovo je Chromium unutar Ubuntu VM-a, a ne provjera stvarnog Windows preglednika ili fizickog mobitela.

Nakon pretrage lokalno prolazi **165 PHP testova / 1386 assertions** i **8 JavaScript testova**, uz Pint, Larastan i Vite build. Preview sada ima 4 oznacene demo narudzbe, 4 uspjesne simulacije i 1 odbijeni pokusaj; nijedna gostujuca narudzba nije povezana s admin korisnikom.
