# OneShop — provjereno razvojno stanje

Ažurirano: 30. 10. 2026. u 05:55 UTC / 06:55 Europe/Zagreb. Noćni rad traje do dogovorenih 08:00 Europe/Zagreb.

## Otvaranje trgovine

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Administracija: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Vlasnički račun: `admin@oneshop.local`, superadmin sa svim ovlastima i bez obaveznog 2FA. Lozinka je predana zasebno i nije u repozitoriju.

Hostname je `codex`, stalna adresa `192.168.250.2/24` na `eth1`, a internet ide preko `eth0`. Prijava i health endpoint vraćaju 200 iz VM-a. Windows preglednik na ovoj adresi nije neovisno provjeren. Pregled se poslužuje projektnim user servisima; ne ovisi o privremenom portu Codex pregleda.

## Funkcionalni razvojni MVP

| Dio | Što radi |
| --- | --- |
| Proizvodi | Nacrti, nazivi/opisi, zajedničke opcije i vrijednosti, jednostavni i varijabilni SKU-ovi, EUR cijene i zaliha, kategorije, javne fotografije i konverzije, provjera i izričita objava. |
| Administracija | Dashboard, katalog, opcije, kategorije, dostava, zaprimljene narudžbe, povijest plaćanja i interni PDF sažetak. Obični administratori zadržavaju obavezni MFA. |
| Trgovina | Tri demo proizvoda, uključujući majicu u četiri kombinacije; četiri pripremljene teme, izbor varijante, javna galerija, pretraga/dostupnost/paginacija i gostujuća košarica. |
| Checkout | Adresa, izbor spremljene tarife, pregled poreza/iznosa, testna narudžba, simulirano plaćanje i povijesna potvrda. Radi i bez JavaScripta. |
| Dostava | Ručne tarife po državama; cijena i porez računaju se na serveru. Demo HR dostava od 5 EUR i porez od 25% su isključivo testni podaci. |
| Privatne datoteke | Administratorski upload, provjera sadržaja, preuzimanje i arhiva digitalnih datoteka. Upload ne daje kupcu pravo preuzimanja. |

**Stvarna naplata još nije uključena.** Simulator je dopušten samo izričito u local/testing okolini; production ga odbija čak i uz uključenu zastavicu. Ne predstavlja Stripe/PayPal naplatu, rezervaciju zalihe za vanjskog procesora, račun ni stvarno slanje paketa.

## Izvršni dokazi

- [Glavni CI za `d573a9b`](https://github.com/eniofrimer/oneshop/actions/runs/41008620088): **189 testova / 1.923 assertions na svakom engineu**, PostgreSQL i MariaDB, Pint, Larastan, audit, frontend i native paket. Stvarni scheduler worker obrađuje dva posla i poštuje overlap zaključavanje na oba enginea.
- [Browser CI za `d573a9b`](https://github.com/eniofrimer/oneshop/actions/runs/41008619140): sve četiri teme u Chromiumu, referentna tema u Firefoxu i WebKitu; **18 simuliranih kupnji i 198 axe provjera**. Obuhvaća desktop, uski prikaz/dodir i rad bez JavaScripta, fotografije u košarici, oporavak unosa i prazne stranice. Preuzeta su i provjerena sva 24 izvještaja, uključujući 12 enhanced/no-JS checkout putanja samo tipkovnicom bez novih narudžbi.
- [Administratorski browser za `d573a9b`](https://github.com/eniofrimer/oneshop/actions/runs/41009162071): na oba SQL enginea iz sučelja su kreirani opcija, vrijednosti, varijabilni proizvod, dva SKU-a i dvije fotografije. Promjena redoslijeda ostaje nakon ponovnog otvaranja i mijenja javnu naslovnu; tipkovnica mijenja veliku fotografiju bez gubitka odabrane varijante. Zaseban gost kupuje proizvod; admin vidi narudžbu/transakciju/PDF. Stari obrazac odbija prepisati zalihu i cijenu nakon kupnje. Dodatni tokovi potvrđuju identična privatna preuzimanja nakon arhive/povrata, kategoričko stablo i odbijeni ciklus te zaštitu novije dostavne tarife.
- [Immutable Docker smoke za `d573a9b`](https://github.com/eniofrimer/oneshop/actions/runs/41008619440): stvarno pokrenuti app/web/worker/scheduler s read-only kodom i odvojenim javnim/privatnim volumenima. CLI, HTTP, queue i restart provjere prolaze.
- Native paket iz [CI `d573a9b`](https://github.com/eniofrimer/oneshop/actions/runs/41008620088), 22.494.825 bajtova: potvrđeni SHA-256, revizija i lock hashovi; nema `.env`, testova, Node ovisnosti, Horizona ni Fakera. Čista instalacija na zasebnom QA MariaDB procesu koristi PHP 8.5.12 bez Redis/PostgreSQL ekstenzija, database cache/šifrirane sesije/jobs te prolazi cPanel/FastPanel runtime i stvarni queue probe. Cached production profil odbija simulator i uz uključenu zastavicu. Local profil prolazi devet kupnji u Chromiumu/Firefoxu/WebKitu (51 axe provjera), zatim admin varijante/dvije fotografije/cart/stock/payment → order/PDF tok, privatne datoteke i kategorije/dostavu. Aplikacijski kod nije zakrpan. To je provjera paketa u VM-u, a ne prihvat stvarnog hosting računa.
- [Sintetička obnova](https://github.com/eniofrimer/oneshop/actions/runs/40969791509): oba enginea, 72 tablice / 236 redaka / osam datoteka; sačuvani ključ, sesija, MFA, privatna datoteka, PDF i integritet baze. Pogrešan ključ odbijen.

Browser dokazi su iz Linux/CI okoline. Firefoxov uski prikaz nije Android, a Linux WebKit nije provjera stvarnog Safarija/iPhonea. Automatske axe provjere nisu certifikat potpune pristupačnosti. Stvarni cPanel/FastPanel račun, web PHP, HTTPS, mail i cron još nisu prihvaćeni na ciljnom hostu. Docker provjere izvršavaju se u CI-u; ova VM nema Docker daemon.

Naslovna fotografija u popisu proizvoda i košarici, filteri ishoda plaćanja i poveznica na narudžbu prošli su [admin CI `e8a5a4f`](https://github.com/eniofrimer/oneshop/actions/runs/40994243642) i [Docker smoke](https://github.com/eniofrimer/oneshop/actions/runs/40994218907). Ispravak opisa slike `0` uključen je u potpuno zeleni CI za `6f4375a`. PHP testovi i nove browser kopije koriste zaseban MariaDB proces/socket izvan preview baze; sam naziv `oneshop_test` nije dokaz izolacije.

Za praktičan pregled isporučenih ekrana pogledaj [kratke administratorske upute](ADMIN_GUIDE.md).

Dodatno su pojašnjene pristupačne oznake količine/ažuriranja/uklanjanja za dvije varijante istog proizvoda u košarici. Jedanaest cart testova / 135 assertions i tri enginea prolaze neovisne promjene, uklanjanje i rad bez JavaScripta, uz 24 dodatne lokalne axe provjere. Prošireni CI za `300044f` je potpuno zelen: 198 provjera uz istih 18 simuliranih kupnji.

SKU tablica prikazuje osnovnu EUR cijenu uz zalihu, s jasnom oznakom poreza i porukama za nedostajuće/dvostruke cijene ili posebna pakiranja. Cijeli CI i oba admin browser toka za `4364596` su zeleni. Naknadno su usklađeni hrvatski nazivi odabira HR dostave i privatnih datoteka; lokalne stvarne UI provjere i cijeli CI za `46eee87` prolaze, uključujući šest preuzetih administratorskih izvještaja.

U 04:42 UTC ponovno je stvarno provjerena prijava postojećeg vlasnika na stalnom URL-u, bez MFA koraka, uz čitanje dashboarda i postojećeg proizvoda. Lozinka je ostala ista; podaci previewa nisu resetirani. Sva tri projektna servisa su aktivna. Dostupnost je potvrđena iz VM-a, ne iz Windowsa.

Na zasebnoj QA kopiji sve četiri teme prolaze naslovnicu, proizvod, košaricu, adresu, pregled i praznu košaricu na širini 320 px: **24 dodatne axe provjere**, dugi sintetički unosi, bez vodoravnog prelijevanja, JS grešaka ili novih narudžbi. Košarica u Chromiumu, Firefoxu i WebKitu dodatno prolazi Enter, Space i redoslijed Tab na desktopu te stvarne Playwright tap događaje u dodirnoj emulaciji; no-JS tok ostaje uspješan. To su ograničene provjere konkretnih kontrola, ne potpuna provjera čitačem ekrana.

Privatna lokalna razvojna snimka previewa od 04:57 UTC sadrži SQL izvoz, pripadni `.env` i javne medije. Provjereni su 0700/0600 pristupi, veličine, SHA-256 i gzip. Nije provedena obnova niti offsite kopiranje; snimka nije zamjena za dogovorenu produkcijsku backup politiku.

Ispravljen je i stvarni 429 nakon više cart/adresnih radnji: dodavanje, adresa, predaja narudžbe i simulator više ne troše isti brojač. Vlastiti limiti 60/30/15/30 po minuti ostaju uključeni. Tri regresijska testa prolaze na obje baze; prošireni browser tok je zelen. Nisu mijenjani naplata, zaliha ili poslovna pravila.

PDF i checkout pregled dodatno čuvaju valjani tekst `0` kao oznaku varijante/dodatak adresi. PDF prikazuje naziv dostave bez tehničkog identifikatora; SKU i spremljeni iznosi ostaju sačuvani. Lokalno prolazi 21 checkout/PDF test i neovisni pregled teksta/izgleda PDF-a na označenoj QA kopiji; novi CI slijedi.

## Za jutarnji nastavak s Astrom

1. Produkcijski tok naplate i zalihe: rezervacije/istek, zakašnjele i ponovljene obavijesti procesora, poravnanje, povrati i oporavak nepoznatog ishoda.
2. Stvarni Stripe/PayPal/virman, kupčev zasebni identitet i Google prijava te plaćena digitalna prava.
3. Porezna pravila i zaokruživanje za stvarnog trgovca, poslovni podaci/Sudreg, dokumenti i odabrana računovodstvena integracija.
4. Ugovorene dostavne tarife i odabrane kurirske/paketomat integracije; demo tarife nisu potvrđeni poslovni zahtjevi.
5. Ciljna instanca/domena i stvarni hosting prihvat, backup RPO/RTO i offsite obnova; popis jezika, uređivanje sadržaja i import/export opseg ostaju otvorene odluke.

Za sljedeći pregled pripremljena je [mapa koda, testova i najmanjih potrebnih odluka](MORNING_REVIEW.md). Nijedna otvorena odluka nije pretvorena u potvrđen zahtjev bez korisnikova unosa. Detalji: [PLAN](PLAN.md), [pregled i odluke](CLAUDE_REVIEW.md), [checkout granice](DEVELOPMENT_CHECKOUT.md), [hosting](DEPLOYMENT.md), [browser postupci](BROWSER_TESTING.md).
