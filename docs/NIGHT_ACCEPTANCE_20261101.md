# Noć 31. listopada / 1. studenoga — javni katalog i prihvat

Funkcionalna revizija: `80e877bd2fef0bced258a0d7be9b52209fcccb72`. Objavljena je na postojećem lokalnom previewu 31. 10. u 22:44 Europe/Zagreb. Ovaj zapis opisuje izvršene provjere; nije neovisni review drugog agenta ni prihvat stvarne naplate.

## Što je dodano

- Sortiranje prije paginacije: zadano, najnovije i naziv uzlazno/silazno, uz stabilan ID kod jednakih vrijednosti. Naziv se uspoređuje bez razlike velikih/malih slova; to nije potvrda identične hrvatske jezične kolacije na oba enginea. Pretraga, dostupnost i odabrani redoslijed ostaju u URL-u, uključujući povratak s proizvoda. Neispravan izbor daje razumljiv 422 i može se ispraviti. Bez JS-a radi običan GET obrazac.
- Povećana fotografija u native dijalogu: tipkovnica, Escape, vraćen fokus, 320 px prikaz, odabrana SKU fotografija i oporavak kad slika ne može biti učitana. Bez JS-a ostaje poveznica na original. Regresija pokriva i brzo zatvaranje/ponovno otvaranje; zakašnjeli close događaj više ne briše novu sliku.
- Canonical, opis i Open Graph iz stvarnog javnog proizvoda. Konfigurirani origin štiti metadata URL od korisničkog Host/query unosa. Nema izmišljenih recenzija ili Product/Offer tvrdnji.
- Opt-in indeksiranje i dinamički, ograničeni sitemap iz postojećeg javnog scopea. Crawler rute nemaju sesiju ni cookieje. **Preview ostaje noindex; launch flag nije uključen.**
- Projektni odgovori ostaju private/no-store, uključujući personalizirani katalog, checkout, administraciju i greške. Stroži no-referrer na Stripe odgovorima je očuvan. Samo hashirani Docker Vite asseti dobivaju godišnji immutable cache i gzip; HTML s podacima sesije nije komprimiran tim pravilom.

[Politika i primarni izvori](STOREFRONT_SEO.md), [browser postupci](BROWSER_TESTING.md), [admin upute](ADMIN_GUIDE.md).

## CI za točan funkcionalni commit

| Provjera | Rezultat |
| --- | --- |
| [CI 41243589652](https://github.com/eniofrimer/oneshop/actions/runs/41243589652) | PostgreSQL i MariaDB: po **355 testova / 4.116 assertions**, Pint, Larastan, audit, konkurentni scenariji, native queue/scheduler i no-dev paket prolaze. |
| [Browser 41243593588](https://github.com/eniofrimer/oneshop/actions/runs/41243593588) | Svih šest poslova: četiri teme u Chromiumu te referentna u Firefoxu i WebKitu. Kupnje, košarica, tipkovnica/adresa, povijest, galerija i paginacija/sortiranje prolaze. |
| [Admin/MFA 41243595647](https://github.com/eniofrimer/oneshop/actions/runs/41243595647) | Obje baze: proizvodi/fotografije/zalihe, privatne datoteke, kategorije/dostava i obični administratorski TOTP/replay/recovery prolaze. Osjetljivi MFA prikazi nisu snimani. |
| [Docker 41243591355](https://github.com/eniofrimer/oneshop/actions/runs/41243591355) | Stvarni FPM/Nginx HTTP: zatvoreno stanje → sintetički javni opt-in → ponovno zatvaranje; XML, canonical, cookieji i privatni odgovori prolaze. |
| [Obnova 41243597878](https://github.com/eniofrimer/oneshop/actions/runs/41243597878) | Oba enginea obnavljaju sintetičkih 77 tablica / 392 retka / osam datoteka, ključ i provjerene financijske replay/identity zaštite. To nije obnova previewa niti offsite kopija. |
| [Tajne 41243589529](https://github.com/eniofrimer/oneshop/actions/runs/41243589529) | Prolazi. GitHub alert #1 ostaje resolved/false_positive; nema novih prijavljenih alarma u provjerenom API odgovoru. |

Jedanaest preuzetih browser/admin/Docker/recovery ZIP-ova ima provjeren GitHub SHA-256 i CRC. Ukupno sadrže **400 axe izvještaja, nula kršenja i 96 neodlučenih stavki**. Neodlučeno ostaje za ručni pregled; ovo nije potvrda svih WCAG zahtjeva ili fizičkog čitača ekrana.

Docker HTTP uspoređuje stvarne identity bajtove s dekomprimiranim gzip odgovorom. CSS: **38.939 → 9.264 B**; JS: **7.590 → 2.832 B**. Provjerava MIME, cache, Vary, jedan nosniff i PHP/hidden-file izuzetke. Reserved HTTPS origin služi samo za metapodatke: stvarni TLS i konkretan hosting panel nisu time provjereni.

## Svježi native paket bez razvojnih ovisnosti

CI artefakt `13058094576`, arhiv `oneshop-80e877bd2fef.tar.gz`, **23.298.926 B**. SHA-256: `47cb6e0e3d378a5e64066586e967697d790748d91dfd3bf6c2b9bc2558634985`. ZIP digest/CRC, tar sidecar, release revision i oba lock hasha odgovaraju commitu.

Svježa zasebna instalacija koristi vlastiti fizički odvojeni SQL daemon i loopback HTTP. PHP 8.5.12 radi s PDO-MySQL, bez Redis/PDO-PG/Horizon/Faker paketa, uz database cache/sessions/jobs. Runtime ciljevi cPanel/FastPanel i stvarni queue probe prolaze. To su runtime profili, ne prijava na stvaran hosting račun ili provjera web PHP/FPM-a na panelu.

Izvršeno na netaknutom paketu:

- Devet sintetičkih kupnji kroz Chromium, Firefox i WebKit, svaki na desktopu, mobilnoj emulaciji i bez JS-a.
- Galerija u sva tri enginea, na 1400/390/320 px i bez JS-a; slika varijante, fokus/Escape, brzi reopen i pogreška učitavanja.
- Trinaest naknadnih sintetičkih proizvoda za sortiranje/paginaciju. Stabilne stranice, reset na prvu pri novom redoslijedu, browser Back i ispravak neispravnog filtra rade; te provjere ne stvaraju narudžbe i blokiraju sve upisne/vanjske zahtjeve.
- **126 axe provjera: nula kršenja, 24 neodlučene stavke.**
- Cached production config/routes/views sa sintetičkim HTTPS originom: canonical ostaje konfiguriran i uz strani Host; sitemap je XML bez cookieja; filtrirane/prazne stranice imaju noindex; admin/checkout/Stripe rute ostaju privatne i simulator je zatvoren. Devet trgovinskih tablica je identično prije i poslije; env je vraćen byte-for-byte i zasebni QA worker ponovno pokrenut.

Prije pokretanja je provjereno **311 Git datoteka**. Nakon stvarnih kupnji i cache provjera svih **21.041 originalnih regularnih datoteka arhiva** ostaje byte-identično, uključujući vendor. Zasebno generirani env/storage/cache nisu izvorni sadržaj arhiva. Testni fixture je izvršen iz privatnog QA runtimea, bez dodavanja testova ili patchanja paketa. Prethodni potpuni native admin/MFA/500-proizvodni/4096-px prihvat i njegove točne granice ostaju u [ranijem zapisu](NATIVE_ACCEPTANCE_20261031.md); trenutačni admin/MFA dokaz je CI na obje baze.

## Objava i očuvanje postojećeg previewa

Git prijelaz je bio `d46b6e4` → `80e877b`, bez migracija i promjene lockova. Prije promjene stvorena je privatna snimka `20261031-214422` s env-om, SQL-om i javnim media arhivom. Direktorij/datoteke su provjereni na 0700/0600, veličine i SHA-256. **Svih 963 postojećih redaka u 77 tablica i env uspoređeni su prije/poslije i identični.** To uključuje vlasnikov račun i postojeće testne narudžbe. U ovom koraku nije izvršena obnova te nove snimke; ranije stvarne SQL obnove i CI recovery ostaju zasebni dokazi. Offsite i puni aplikacijski oporavak nisu time potvrđeni.

Tri build datoteke previewa identične su točno testiranom native arhivu. Projektni database/HTTP/worker/timer su aktivni i omogućeni, scheduler ima Result=success i ExecMainStatus=0. Nisu mijenjani zajednička mreža, firewall ili drugi projekti. Lokalni health monitor uzorkuje health, statičnu sliku i te četiri jedinice; uzorci nisu dokaz neprekidnog uptimea.

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Login: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Vlasnik: `admin@oneshop.local`, nepromijenjena zasebno predana lozinka, sve ovlasti i bez obaveznog 2FA; obični administratori zadržavaju MFA.

Poslije objave HTTP iz VM-a potvrđuje health/naslovnicu/login/robots 200, proizvode i zalihe → isti login, sitemap 404 te noindex/private/no-store. Stvarni preview u Chromiumu na 1400/390/320 px prolazi 12 axe prikaza bez kršenja ili neodlučenih stavki: katalog/sortiranje, proizvod, otvorena fotografija i prijava. Nema JS grešaka, neuspjelih resursa, vanjskih zahtjeva, vodoravnog prelijevanja ni testnih narudžbi. Probe blokiraju sve ne-GET/HEAD zahtjeve; tri automatska login Livewire POST pokušaja stvarno su blokirana, nisu poslana serveru. Produkt i 320 px dijalog vizualno su pregledani. Windows pristup nije neovisno potvrđen. Backend i frontend služe ručnim **testnim** narudžbama. Stvarna naplata, račun, slanje paketa, merchant/tax pravila i hosting/TLS prihvat ostaju otvoreni u [jutarnjem pregledu](MORNING_REVIEW.md).
