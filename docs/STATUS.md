# OneShop — provjereno razvojno stanje

Ažurirano: 30. 10. 2026. navečer, Europe/Zagreb — noćna dorada izgleda i ručne probne kupnje.

## Aktualni noćni pregled

Preview i GitHub `main` koriste `7dc5525`. Naslovnica i demo proizvodi imaju lokalne responsive WebP fotografije, checkout prikazuje korake, a broj uz košaricu prati količinu. **Moje narudžbe** čuva pristup testnim narudžbama iz iste sesije, uključujući povrat nakon nove košarice; drugi preglednik nema pristup. Admin ima jasne grupe, ikone i posljednje narudžbe. Stripe testni način dosljedno je označen i u PDF-u. Vrijeme se prikazuje u `Europe/Zagreb`, dok baza ostaje UTC. [Praktičan redoslijed ručne provjere](NIGHT_DEMO_20261030.md).

[Glavni CI](https://github.com/eniofrimer/oneshop/actions/runs/41135702919), [Docker](https://github.com/eniofrimer/oneshop/actions/runs/41135702729) i [provjera tajni](https://github.com/eniofrimer/oneshop/actions/runs/41135702589) za taj točan `main` su zeleni. Izvršni izvještaji razvojnog CI-a potvrđuju **287 testova / 3.204 assertions na svakom SQL engineu**. [Šest browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41134367383) i [oba admin posla](https://github.com/eniofrimer/oneshop/actions/runs/41134371554) također prolaze. Osam ZIP artefakata provjereno je SHA-256 hashom; neovisno je pregledano 282 JSON izvještaja: **36 javnih simuliranih kupnji, 246 axe provjera bez potvrđenih povreda, 12 checkout putanja tipkovnicom i šest admin tokova**. Axe je u 53 provjere zabilježio i nalaze za ručni pregled; to nije certifikat potpune pristupačnosti.

Nadogradnja je sačuvala svih **924 postojeća retka u 77 tablica**, `.env`, APP_URL i migracije. Nije bilo resetiranja, novih migracija ni promjene zaključanih ovisnosti. Privatna snimka od 23:03 ima provjerene pristupe, veličine, SHA-256 i gzip; obnova i offsite kopija nisu izvršeni. Projektni HTTP/DB/worker/timer su aktivni i omogućeni, scheduler tick završava uspješno. Naslovnica, prijava, health i povijest vraćaju 200; proizvodi traže postojeću prijavu, Stripe javni checkout ostaje isključen. GET-only Chromium provjera naslovnice i prijave na 1400/390 px prolazi bez JS grešaka, vanjskih zahtjeva ili prelijevanja. Sve je provjereno iz VM-a, ne neovisno iz Windowsa.

Daljnja admin dorada u zasebnom checkoutu dodaje globalnu pretragu naziva/SKU-ova i sigurni read-only pregled podržanih aktivnosti. Lokalno prolazi osam integracijskih testova / 62 assertions i stvarni 15-koračni Chromium admin tok, uključujući tipkovnicu i poveznicu aktivnosti na narudžbu. Objavljivanje slijedi tek nakon zasebnog punog CI-a.

## Prethodni prihvati i granice

## Stripe testni temelj i zaštita izvornog koda

Preview sada uključuje trajne pokušaje naplate, provjereni Stripe sandbox adapter, webhook inbox, financijske potvrde/outbox i read-only `/admin/payment-attempts`. Javni sandbox checkout ostaje isključen dok vlasnik privatno ne postavi merchant testnu konfiguraciju i ručno ne potvrdi hosted kartični tok. Simulator kupnje nastavlja raditi. Live naplata, PayPal, povrati i kupčeva digitalna prava ostaju otvoreni.

Revizija `17d7695` ima zeleni [CI na obje baze](https://github.com/eniofrimer/oneshop/actions/runs/41114547994): **273 testa / 3.066 assertions i 14 konkurentnih scenarija po engineu**, Pint, Larastan, audit i native provjere. [Docker smoke](https://github.com/eniofrimer/oneshop/actions/runs/41114552069) i [nova provjera tajni](https://github.com/eniofrimer/oneshop/actions/runs/41114512374) također prolaze. Prethodni javni tok `d8f20e8` ima zeleni browser/admin/recovery CI; osam preuzetih admin/recovery izvještaja provjereno je uz SHA-256 četiri ZIP-a. Detalji i granice: [PAYMENT_ATTEMPTS.md](PAYMENT_ATTEMPTS.md).

U 19:27 UTC napravljena je privatna snimka prije aditivne migracije. **Svih 785 postojećih redaka u 72 tablice, stari migration zapisi, `.env` i APP_URL ostali su jednaki**; dodane su četiri prazne payment tablice, bez resetiranja baze. Provjereni su hashovi i pristupi snimke; obnova i offsite kopiranje nisu izvršeni. Projektni HTTP/DB/worker/timer su aktivni, scheduler tick završava s exit 0, a read-only FastPanel runtime provjera prolazi. Prijava vraća 200, zaštićeni proizvodi i payment attempts vraćaju na isti login URL, a isključeni Stripe checkout vraća 404. Dostupnost je provjerena iz VM-a.

GitHub [upozorenje #1](https://github.com/eniofrimer/oneshop/security/secret-scanning/1) odnosilo se na sintetički testni webhook ključ. Nakon provjere zatvoreno je kao false positive; fiksni testni ključevi zamijenjeni su privremenim vrijednostima. U ovom checkoutu uključen je pre-push hook, uz zaseban CI i precizne povijesne iznimke. [Opseg skena, dokaz blokiranja pusha i upute](SECRET_HANDLING.md).

## Pregled naplate i istodobnih promjena

Ispravljeni su nepotpuni potpis potvrde kupnje i reproducirano čitanje stare cijene nakon čekanja na zaključavanje u MariaDB-u. OneShopove SQL sesije koriste READ COMMITTED; zajedničke opcije su zaključane tijekom predaje. Lokalno prolazi **197 testova / 2.058 assertions**, Pint i Larastan te pet stvarnih konkurentnih scenarija na zasebnom MariaDB daemonu. [CI za 644484c](https://github.com/eniofrimer/oneshop/actions/runs/41067565695) je potpuno zelen na oba enginea: **197 testova / 2.058 assertions i pet dodatnih konkurentnih scenarija po bazi**. [Docker smoke](https://github.com/eniofrimer/oneshop/actions/runs/41067565687), [šest browser poslova](https://github.com/eniofrimer/oneshop/actions/runs/41067564459) i [oba admin posla](https://github.com/eniofrimer/oneshop/actions/runs/41067568896) također prolaze. [Nalazi, izvedba i granice](PAYMENT_REVIEW.md).

Neovisno je pregledano svih 30 preuzetih izvještaja: 198 axe provjera, 18 simuliranih kupnji, 12 dodatnih keyboard putanja i šest prolaznih admin izvještaja. Preview runtime potvrđuje READ COMMITTED, stalni URL radi iz VM-a, privatni env je sačuvan i projektni worker uredno je obnovljen. Vlastita testna instanca i daemon uklonjeni su nakon provjere markera; preview servisi ostali su uključeni.

Ovaj raniji pregled nije uključivao PSP adaptere. Aktualni testni Stripe temelj opisan je iznad; stvarna naplata još nije prihvaćena.

## Rezervacije uključene u razvojni preview

Nove testne predaje rezerviraju praćene količine. Katalog, filter, košarica, SKU admin i dashboard prikazuju raspoloživu zalihu. Potvrda potroši jednom; otkazivanje ili siguran razvojni istek oslobode jednom. Stare pending narudžbe zadržavaju kompatibilan put i ne mogu potrošiti nova držanja. [Detalji i granice](INVENTORY.md).

[CI d463f0a](https://github.com/eniofrimer/oneshop/actions/runs/41095356986) potvrđuje **211 testova / 2.252 assertions i devet konkurentnih scenarija po bazi**. Docker, šest javnih browser poslova i prošireni admin na obje baze su zeleni. Obnova na oba enginea čuva 73 tablice / 296 redaka / osam datoteka i sva stanja rezervacije; nakon povrata potvrda i oslobađanje rade jednom. Svih 32 preuzeta izvještaja neovisno je pregledano.

Nedestruktivna preview migracija prošla je uz privatnu snimku i jednakost svih starih redaka u 71 tablici te postojećeg env-a. Projektni timer izvršava scheduler svake minute; stvarni tick vraća exit 0. HTTP i stalni IP ostaju dostupni iz VM-a. Stvarna naplata, PSP poravnanje i produkcijski rokovi i dalje su za implementaciju.

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

- [Glavni CI za `80e65a6`](https://github.com/eniofrimer/oneshop/actions/runs/41011110886): **190 testova / 1.936 assertions na svakom engineu**, PostgreSQL i MariaDB, Pint, Larastan, audit, frontend i native paket. Stvarni scheduler worker obrađuje dva posla i poštuje overlap zaključavanje na oba enginea.
- [Browser CI za `80e65a6`](https://github.com/eniofrimer/oneshop/actions/runs/41011208467): sve četiri teme u Chromiumu, referentna tema u Firefoxu i WebKitu; **18 simuliranih kupnji i 198 axe provjera**. Obuhvaća desktop, uski prikaz/dodir i rad bez JavaScripta, fotografije u košarici, oporavak unosa i prazne stranice. Preuzeta su i provjerena sva 24 izvještaja, uključujući 12 enhanced/no-JS checkout putanja samo tipkovnicom bez novih narudžbi.
- [Administratorski browser za `80e65a6`](https://github.com/eniofrimer/oneshop/actions/runs/41011210386): na oba SQL enginea iz sučelja su kreirani opcija, vrijednosti, varijabilni proizvod, dva SKU-a i dvije fotografije. Promjena redoslijeda ostaje nakon ponovnog otvaranja i mijenja javnu naslovnu; tipkovnica mijenja veliku fotografiju bez gubitka odabrane varijante. Zaseban gost kupuje proizvod; admin vidi narudžbu/transakciju/PDF. Stari obrazac odbija prepisati zalihu i cijenu nakon kupnje. Dodatni tokovi potvrđuju identična privatna preuzimanja nakon arhive/povrata, kategoričko stablo i odbijeni ciklus te zaštitu novije dostavne tarife.
- [Immutable Docker smoke za `80e65a6`](https://github.com/eniofrimer/oneshop/actions/runs/41011110905): stvarno pokrenuti app/web/worker/scheduler s read-only kodom i odvojenim javnim/privatnim volumenima. CLI, HTTP, queue i restart provjere prolaze.
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

PDF i checkout pregled dodatno čuvaju valjani tekst `0` kao oznaku varijante/dodatak adresi. PDF prikazuje naziv dostave bez tehničkog identifikatora; SKU i spremljeni iznosi ostaju sačuvani. Lokalno prolazi 21 checkout/PDF test i neovisni pregled teksta/izgleda PDF-a na označenoj QA kopiji. Glavni CI, Docker, šest browser i oba admin posla za `80e65a6` su zeleni; svih 30 novih izvještaja je preuzeto i provjereno.

Native arhiv `80e65a6` dodatno je potvrđen hashom/revizijom/lockovima. Svih 20.861 uspoređenih izvršnih datoteka odgovara ranijoj native instalaciji s dvije izričito zabilježene Blade zakrpe. To je provjera jednakosti izvršnog koda, a ne nova čista instalacija arhiva `80e65a6`; potpuni prethodni čisti dokaz odnosi se na `d573a9b`.

Od 05:43 do 06:45 UTC preview je praćen u 62 uzorka, približno jednom u minuti: **248 HTTP provjera** naslovnice, healtha, prijave i preusmjeravanja zaštićenih proizvoda, bez zabilježenog problema. U svim uzorcima tri projektna servisa su aktivna i `192.168.250.2/24` ostaje na `eth1`. Najsporiji izmjereni HTTP odgovor bio je 0,246 s; ovo su uzorci, ne jamstvo neprekinute dostupnosti između njih. Vlasnička prijava ponovno prolazi s istom lozinkom i bez MFA koraka. Javni pregled na 1400/390 px potvrđuje slike i spremljeni dropdown izbor varijante, bez izmjene ponude/zalihe. Provjere su iz VM-a; Windows i puni reboot nisu obuhvaćeni.

Sva tri preview servisa su i omogućena za pokretanje. Runtime provjera prolazi uz nula čekajućih/neuspjelih poslova. Uklonjeni su vlastiti potrošni QA direktoriji/baze i zasebni testni MariaDB proces; preview ostaje aktivan. Nova privatna razvojna snimka od 06:42 UTC ima provjerene pristupe, hashove i gzip, uz nepromijenjeni postojeći `.env`. Nije izvršena obnova ni offsite kopiranje.

## Preostali produkcijski razvoj

1. Merchant sandbox prihvat uvedenog Stripe adaptera i javne webhook putanje, zatim produkcijsko poravnanje, refund ledger i potvrđena politika isteka. Razvojni rok nije produkcijska politika.
2. Live Stripe, PayPal/virman, kupčev zasebni identitet i Google prijava te plaćena digitalna prava.
3. Porezna pravila i zaokruživanje za stvarnog trgovca, poslovni podaci/Sudreg, dokumenti i odabrana računovodstvena integracija.
4. Ugovorene dostavne tarife i odabrane kurirske/paketomat integracije; demo tarife nisu potvrđeni poslovni zahtjevi.
5. Ciljna instanca/domena i stvarni hosting prihvat, backup RPO/RTO i offsite obnova; popis jezika, uređivanje sadržaja i import/export opseg ostaju otvorene odluke.

Za sljedeći pregled pripremljena je [mapa koda, testova i najmanjih potrebnih odluka](MORNING_REVIEW.md). Nijedna otvorena odluka nije pretvorena u potvrđen zahtjev bez korisnikova unosa. Detalji: [PLAN](PLAN.md), [pregled i odluke](CLAUDE_REVIEW.md), [checkout granice](DEVELOPMENT_CHECKOUT.md), [hosting](DEPLOYMENT.md), [browser postupci](BROWSER_TESTING.md).
