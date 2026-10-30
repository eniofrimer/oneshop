# OneShop — provjereno razvojno stanje

Ažurirano: 30. 10. 2026. u 02:52 UTC / 03:52 Europe/Zagreb. Noćni rad traje do dogovorenih 08:00 Europe/Zagreb.

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

- [Glavni CI za `15346da`](https://github.com/eniofrimer/oneshop/actions/runs/40992323143): **182 testa / 1.695 assertions na svakom engineu**, PostgreSQL i MariaDB, Pint, Larastan, audit, frontend i native paket. Stvarni scheduler worker obrađuje dva posla i poštuje overlap zaključavanje na oba enginea.
- [Browser CI za `cf0d71e`](https://github.com/eniofrimer/oneshop/actions/runs/40982177117): sve četiri teme u Chromiumu, referentna tema u Firefoxu i WebKitu; **18 simuliranih kupnji i 150 axe provjera**. Obuhvaća desktop, uski prikaz/dodir i rad bez JavaScripta, oporavak unosa i prazne stranice. Preuzeti izvještaji su provjereni.
- [Administratorski browser za `74c11f7`](https://github.com/eniofrimer/oneshop/actions/runs/40988075943): na oba SQL enginea iz sučelja su kreirani opcija, vrijednosti, varijabilni proizvod, dva SKU-a i dvije fotografije. Promjena redoslijeda ostaje nakon ponovnog otvaranja i mijenja javnu naslovnu; tipkovnica mijenja veliku fotografiju bez gubitka odabrane varijante. Zaseban gost kupuje proizvod; admin vidi narudžbu/transakciju/PDF. Stari obrazac odbija prepisati zalihu i cijenu nakon kupnje. Dodatni tokovi potvrđuju identična privatna preuzimanja nakon arhive/povrata, kategoričko stablo i odbijeni ciklus te zaštitu novije dostavne tarife.
- [Immutable Docker smoke za `c8a1042`](https://github.com/eniofrimer/oneshop/actions/runs/40981118131): stvarno pokrenuti app/web/worker/scheduler s read-only kodom i odvojenim javnim/privatnim volumenima. CLI, HTTP, queue i restart provjere prolaze.
- Native paket iz [CI `c8a1042`](https://github.com/eniofrimer/oneshop/actions/runs/40981118185), 22.477.757 bajtova: neovisno potvrđeni SHA-256, revizija i lock hashovi. Nema `.env`, testova, Node ovisnosti, Horizona ni Fakera. Čista zasebna instalacija u VM-u, na PHP-u bez Redis i PostgreSQL ekstenzija, prolazi cPanel/FastPanel runtime provjere i dev kupnju u Chromiumu, Firefoxu i WebKitu: **devet kupnji i 51 axe provjera**. Naknadni administratorski tok na istom paketu potvrdio je i varijabilni proizvod, stvarni upload/konverzije, desetu testnu narudžbu, odbijanje zastarjele zalihe i PDF od 879.172 bajta.
- [Sintetička obnova](https://github.com/eniofrimer/oneshop/actions/runs/40969791509): oba enginea, 72 tablice / 236 redaka / osam datoteka; sačuvani ključ, sesija, MFA, privatna datoteka, PDF i integritet baze. Pogrešan ključ odbijen.

Browser dokazi su iz Linux/CI okoline. Firefoxov uski prikaz nije Android, a Linux WebKit nije provjera stvarnog Safarija/iPhonea. Automatske axe provjere nisu certifikat potpune pristupačnosti. Stvarni cPanel/FastPanel račun, web PHP, HTTPS, mail i cron još nisu prihvaćeni na ciljnom hostu. Docker provjere izvršavaju se u CI-u; ova VM nema Docker daemon.

Naslovna fotografija u popisu proizvoda, filteri ishoda plaćanja i poveznica na narudžbu prošli su [admin CI `15346da`](https://github.com/eniofrimer/oneshop/actions/runs/40992441184) i [Docker smoke](https://github.com/eniofrimer/oneshop/actions/runs/40992323163). Naknadne fotografije u košarici prolaze deset vezanih aplikacijskih testova / 108 assertions te stvarne kupovne tokove u sva tri enginea, uključujući rad bez JavaScripta; CI za taj dodatak još slijedi. PHP testovi i nove browser kopije koriste zaseban MariaDB proces/socket izvan preview baze; sam naziv `oneshop_test` nije dokaz izolacije.

Za praktičan pregled isporučenih ekrana pogledaj [kratke administratorske upute](ADMIN_GUIDE.md).

## Za jutarnji nastavak s Astrom

1. Produkcijski tok naplate i zalihe: rezervacije/istek, zakašnjele i ponovljene obavijesti procesora, poravnanje, povrati i oporavak nepoznatog ishoda.
2. Stvarni Stripe/PayPal/virman, kupčev zasebni identitet i Google prijava te plaćena digitalna prava.
3. Porezna pravila i zaokruživanje za stvarnog trgovca, poslovni podaci/Sudreg, dokumenti i odabrana računovodstvena integracija.
4. Ugovorene dostavne tarife i odabrane kurirske/paketomat integracije; demo tarife nisu potvrđeni poslovni zahtjevi.
5. Ciljna instanca/domena i stvarni hosting prihvat, backup RPO/RTO i offsite obnova; popis jezika, uređivanje sadržaja i import/export opseg ostaju otvorene odluke.

Nijedna od ovih odluka nije pretvorena u potvrđen zahtjev bez korisnikova unosa. Detalji: [PLAN](PLAN.md), [pregled i odluke](CLAUDE_REVIEW.md), [checkout granice](DEVELOPMENT_CHECKOUT.md), [hosting](DEPLOYMENT.md), [browser postupci](BROWSER_TESTING.md).
