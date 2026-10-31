# Jutarnja ručna proba i sljedeće odluke

Funkcionalna revizija `6a8426c` je objavljena. Backend i frontend spremni su za ručne **testne** narudžbe; stvarna naplata, račun i slanje paketa ostaju odvojeni. Posljednji izvršni dokazi su u [STATUS-u](STATUS.md), detaljni runtime/backup dokazi u [native prihvatu](NATIVE_ACCEPTANCE_20261031.md).

## Otvori i probaj

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Admin: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Vlasnik: `admin@oneshop.local`, ista zasebno predana lozinka, sve ovlasti, bez obaveznog 2FA.

IP i preusmjeravanja potvrđeni su iz Ubuntu VM-a. Windows preglednik nije neovisno provjeren. Mreža, firewall i drugi projekti nisu mijenjani. Stari promjenjivi localhost portovi nisu aktualni URL.

Slijedi [sedam koraka ručne probe](NIGHT_DEMO_20261030.md): majica/boja/veličina → košarica → spremljeni pregled dostave/iznosa → odbijanje i uspjeh simulatora → sesijska povijest → admin/PDF → zaliha. Kartica nije potrebna; koristi izmišljene podatke. U drugom tabu možeš otvoriti **Katalog → Zalihe**, potražiti SKU i osvježiti prije i poslije potvrde. Testna HR dostava 5 EUR i stopa 25% nisu potvrđena pravila stvarnog trgovca.

U backendu su proizvodi, opcije, kategorije, javne fotografije/SKU fotografije, cijene/zalihe, ručne dostavne tarife, narudžbe/plaćanja/pokušaji, podržane aktivnosti i privatne datoteke. [Admin upute](ADMIN_GUIDE.md). Nema automatskog reseedanja niti resetiranja korisnikovih podataka; postojeće testne narudžbe ostaju u adminu. Povijest u trgovini pripada samo trenutačnoj sesiji/hostu preglednika. Brisanje cookieja, drugi preglednik ili stari localhost ne vraća tu povijest po e-pošti.

Pri nalazu zapiši broj narudžbe, SKU/odabranu kombinaciju, korak i očekivani iznos. Nemoj u screenshot uključiti lozinku, QR ili recovery kod. Interni PDF nije račun. Za stvaran proizvod koristi nacrt → SKU/cijena/zaliha → slike → provjera → izričita objava. Stari obrazac nakon kupnje traži ponovno otvaranje.

## Što je tehnički provjereno

Obje baze, Docker, čisti native paket bez dev ovisnosti, stvarni worker/scheduler, tri browser enginea, kupnje bez JavaScripta, mobilne širine, obični administratorski MFA/replay/recovery, privatni download/arhiva, dugi PDF, konkurentna naplata/zaliha i izolirana obnova imaju izvršne dokaze. Proba 500 proizvoda pokazuje ograničene kartice/filtere i manje SQL upita; nije prihvat neodabranog velikog referentnog kataloga. Axe nema prijavljenih kršenja u tim tokovima, ali neodlučene stavke i fizički Windows/Safari/čitač ekrana ostaju granice dokaza. Nije dodan novi Filament paket gdje postojeći API već pokriva potrebu.

## Nastavak koji traži konkretne odluke ili pristup

| Područje | Već postoji | Najmanji sljedeći podatak/korak |
| --- | --- | --- |
| Merchant naplata | Simulator; Stripe sandbox adapter, pokušaji, ledger/inbox/outbox, SDK i konkurentni dokazi, isključen javni sandbox | Konkretan testni host/povratni URL i privatna konfiguracija vlasnikova testnog računa/pripadnog webhooka, pa jedna hosted kartična proba. Ključevi idu u env, ne chat ili Git. [PAYMENT_ATTEMPTS](PAYMENT_ATTEMPTS.md), [PAYMENT_REVIEW](PAYMENT_REVIEW.md). |
| Live/ostala plaćanja | Serverski iznosi i povijesna narudžba; odvojeni testni načini | Redoslijed Stripe/PayPal/virmana, produkcijska politika isteka, poravnanja i refund ledgera. Ne proglašavati nepoznat ishod uspjehom. |
| Kupac | Gostujuća košarica i privatna sesijska povijest | Opseg kupčeva računa/Google i vraćanja pristupa nakon gubitka sesije. Novi customer guard ne smije otvoriti administratorski MFA zaobilazak. |
| Digitalna prava | Privatni admin upload/download/arhiva | Dodjela/revokacija prava i gostujuća poveznica nakon stvarne potvrđene naplate; miješana košarica. Sam upload još ne daje kupcu pravo. |
| Porez i dokumenti | Testne EUR cijene, porez/dostava i interni PDF | Identitet trgovca, država/valuta prodaje, računovođina pravila, B2B/OIB/Sudreg, e-racuni/FIRA ili vlastiti dokumentni opseg. |
| Dostava | Uređivanje ručnih tarifa i izračun u testnom checkoutu | Ugovoreni partneri/usluge/cjenici i opseg država, paketomata, adresnica, trackinga/povrata. |
| Hosting/oporavak | Docker CI, no-dev MariaDB VM, oba SQL enginea i ograničena stvarna SQL obnova | Konkretna instanca/domena, web PHP/FPM, HTTPS, cron/worker, mail te lokacija/retencija offsite kopija i RPO/RTO. [DEPLOYMENT](DEPLOYMENT.md). |
| Katalog/sadržaj | Ručni editor, četiri pripremljene teme, prijevodi kataloga i osnovni filteri | Referentna trgovina/broj SKU-ova i import/export, točan popis dodatnih jezika i način uređivanja sadržaja. Slobodan builder, kuponi, recenzije i marketing nisu potvrđeni samom dostupnošću paketa. |

Ove točke ne blokiraju jutarnju ručnu demo kupnju. [PLAN](PLAN.md) čuva zahtjeve i otvoreni opseg; [CLAUDE_REVIEW](CLAUDE_REVIEW.md) čuva izvorni pregled i kasnije izvršne bilješke. Tehnički pregled s Astrom već je nastavljen; ne predstavlja novu potvrdu poslovnih pravila ili produkcijske naplate.
