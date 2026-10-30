# Provjera trgovine u pregledniku

`npm run test:browser` pokreće stvarni Chromium kroz Playwright. Test prolazi desktop (1400 × 1000), mobilnu emulaciju (390 × 844) i desktop s isključenim JavaScriptom. Ne koristi administratorski račun ni spremljeni profil preglednika.

## Izolirana CI provjera

U GitHub Actions ručno pokrenuti **Browser journey** (`browser.yml`). Za svaku od četiri teme zaseban Chromium posao gradi razvojni Docker profil, zaseban PostgreSQL/Redis i prazan demo katalog s demo dostavom. Simulator je izričito uključen samo u toj lokalnoj instanci. Nakon testa brišu se samo servisi i volumeni tog CI projekta.

Provjeravaju se živa pretraga, prazan rezultat, povrat cijelog kataloga, konkretna kombinacija boje/veličine, promjena količine, unos adrese, obračun dostave/poreza, odbijanje i ponovni pokušaj te tri uspješne simulacije. Očekivani ukupni iznosi u svježem demo katalogu su 55, 30 i 20 EUR. Nova demo instalacija koristi radio gumbe za boju i dropdown za veličinu. Bez JavaScripta ostaje izvorni izbor SKU-a i cijeli checkout.

Test odbija zahtjeve prema drugim domenama, bilježi JavaScript/console greške i provjerava vodoravno prelijevanje na svim kupovnim koracima. U košarici i pregledu provjerava i ljudski čitljiv odabir boje/veličine. Screenshotovi i JSON rezultat sadrže samo izmišljene testne podatke; artefakt se zadržava sedam dana. Ne spremaju se sesijski kolačići ni administratorske lozinke.

Na desktopu i mobilnoj emulaciji axe-core provjerava WCAG 2 A/AA i 2.1 AA pravila na sedam koraka: naslovnica, proizvod, košarica, adresa, pregled, simulator i uspjeh. Desktop dodatno provjerava serversko odbijanje neispravne e-pošte, hrvatsku poruku uz polje, očuvanje ostalih podataka i fokus na sažetku grešaka. Izravni neispravni upit pretrage mora vratiti 422 bez preusmjeravanja; ispravak mora ukloniti stare poruke, kroz AJAX na desktopu ili izvorni GET bez JS-a. Desktop provjerava axe i prije/nakon ispravka, ukupno 17 provjera po temi. Rezultat svakog koraka sprema potvrđene greške i nalaze koji traže ručnu provjeru. Potvrđena greška prekida test; pravila se ne isključuju. U sva tri scenarija provjerava se i preskakanje navigacije tipkovnicom. No-JS scenarij nema axe provjeru jer alat zahtijeva izvršavanje JavaScripta.

## Lokalno ponavljanje

Koristiti samo vlastitu, inicijaliziranu razvojnu demo instancu. **Test predaje tri narudžbe, zapisuje simulirane transakcije i umanjuje demo zalihu.** Ne pokretati ga prema trgovčevoj radnoj instanci ili produkciji. Izmijenjen katalog, cijene, tarife ili potrošena zaliha namjerno mogu uzrokovati pad očekivanja; seed ne resetira podatke.

Nakon `npm ci --ignore-scripts` osigurati Chromium za zaključanu verziju Playwrighta ili navesti postojeći lokalni izvršni program:

```bash
ONESHOP_BROWSER_URL=http://127.0.0.1:18181 \
ONESHOP_BROWSER_EXECUTABLE=/putanja/do/chromium \
npm run test:browser
```

`ONESHOP_BROWSER_URL` je obvezan i označava korijenski URL. `ONESHOP_BROWSER_EXECUTABLE` je neobvezan ako je instaliran odgovarajući Playwright Chromium. `ONESHOP_BROWSER_OUTPUT` može promijeniti izlazni direktorij; zadani je ignorirani privatni `storage/app/private/browser-qa`.

`ONESHOP_BROWSER_THEME` može dodatno zadati očekivani ključ teme; CI ga postavlja kako bi provjerio da je ispravan izgled stvarno uključen. Ne mijenja konfiguraciju ciljne aplikacije.

## Granice dokaza

Lokalna provjera izvedena je u Chromiumu unutar Ubuntu VM-a. Mobilni scenarij je emulacija, a ne fizički telefon. Nije neovisno provjeren Windows preglednik, Firefox/Safari, stvarni procesor naplate ni slanje paketa. PDF se zasebno provjerava aplikacijskim i Docker testovima; ova provjera ne pregledava izgled PDF-a.

Automatski prolaz nije potvrda potpune pristupačnosti: ručni pregled sa čitačem ekrana, razumljivost sadržaja, cjelovita navigacija tipkovnicom i stvarni uređaji ostaju zasebne provjere.


## Administratorski tok s novim proizvodom

`npm run test:browser:admin` namijenjen je iskljucivo zasebnoj, potrosnoj local/testing demo instalaciji. Zahtijeva izriciti `ONESHOP_ADMIN_BROWSER_ALLOW_WRITES=true`, URL te e-postu/lozinku posebno kreiranog testnog superadmina kroz `ONESHOP_ADMIN_BROWSER_EMAIL` i `ONESHOP_ADMIN_BROWSER_PASSWORD`. Lozinku ne upisivati u repozitorij. Test nema zadane pristupne podatke i ne kreira korisnika sam.

Rucni workflow **Administrator browser journey** koristi vlastite PostgreSQL i MariaDB poslove s odvojenim volumenima. Svaki izricito kreira nasumicnu privremenu lozinku, skriva je u Actions ispisu i uklanja instalaciju nakon provjere. Koristi database sesije/cache/queue i stvarnog workera za fotografije. Screenshotovi i PDF imaju samo sinteticke podatke; sesije i lozinke nisu u artefaktu. Dokazi se cuvaju sedam dana.

Stvarnim klikovima kreira se zajednicka radio opcija s vrijednostima Mat/Sjaj, zatim nacrt proizvoda i dva SKU-a s razlicitim cijenama/zalihama. Sprema se porezna klasa, potvrduje objava i prenosi vlastita PNG testna ilustracija. Gost mora prebaciti opciju s 15 na 10 EUR prije kupnje. Test ceka vidljivi zavrsetak prijenosa, a zatim javne 500/800 px konverzije iz workera. Zaseban gost kupuje taj proizvod za 15 EUR (10 EUR s testnim porezom + 5 EUR demo dostava). Otvoren stari SKU obrazac mora odbiti prepisivanje nakon kupnje; ponovni prikaz mora imati zalihu 9 i izvornu cijenu 8 EUR bez poreza. Narudzba, testna transakcija i stvarni PDF download provjeravaju se u adminu. Nema stvarne naplate ni slanja paketa.

Ovaj tok dodaje proizvod, javnu fotografiju, narudzbu i transakciju. Ne pokretati ga nad korisnikovim radnim previewom ili trgovcevom instalacijom. Objavljena fotografija je javna i za nacrt. Zadani privatni izlaz je `storage/app/private/admin-browser-qa`; podrzani su isti `ONESHOP_BROWSER_EXECUTABLE` i `ONESHOP_BROWSER_OUTPUT` kao u gostujucem testu. PNG fixture je sinteticka ilustracija bez vanjskih izvora/licenci.


## Stranice kataloga

Nakon tri kupovna scenarija workflow dodaje 13 sintetickih proizvoda s oznakom `QA Stranica`; postojeci katalog i zalihe ne resetira. Fixture je dopusten samo izricito u izoliranom CI poslu. `npm run test:browser:pagination` provjerava prvu/drugu/praznu stranicu i povratak, uz iste filtre (12/1/0/12 kartica), desktop tipkovnicu, mobilni dodir i no-JS linkove. AJAX promjena stranice pomice fokus na naslov rezultata, a unos pretrage zadrzava fokus. Kontrole su zaseban storefront Blade predlozak; admin/vendor paginator nije mijenjan.

Osam dodatnih axe provjera po temi pokriva sve cetiri paginacijske faze na desktopu i mobilnoj emulaciji. Ukupno je sada 25 provjera po temi uz postojecih 17 kupovnih/search provjera, odnosno 100 u cetiri Chromium posla. Dodatni Firefox/WebKit poslovi na referentnoj temi podizu planirani puni skup na 150 provjera i 18 simuliranih kupnji. Izvjestaj paginacije je `pagination-report.json`, odvojen od izvjestaja kupnje. Ovo je funkcionalni fixture od 13 proizvoda, ne benchmark velikog kataloga.


## Dodatni preglednici

`ONESHOP_BROWSER_ENGINE` bira `chromium` (zadano), `firefox` ili `webkit` za gostujucu kupnju i paginaciju. Izvjestaji biljeze engine. `ONESHOP_BROWSER_EXECUTABLE`, ako se zada, mora pripadati odabranom engineu; inace Playwright koristi svoj odgovarajuci instalirani preglednik.

CI ima cetiri Chromium teme i dodatne Firefox/WebKit poslove za referentni `everyday`. Svaki ima vlastitu bazu, projekt i artefakt, ukljucujuci engine u nazivu. Firefox uski prikaz koristi viewport i dodir, jer ne podrzava Playwrightov `isMobile`; to nije Android preglednik. WebKit na Linuxu nije neovisna provjera Apple Safarija ili fizickog iPhonea. Administratorski browser tok ostaje zasebna Chromium provjera na dva SQL enginea.


## Privatne digitalne datoteke u administraciji

`npm run test:browser:admin-files` koristi iste izricite write/URL/QA owner varijable kao administratorski tok. Dodan je istom workflowu na oba SQL enginea. Iz preglednika kreira zaseban digitalni nacrt/SKU, provjerava nedostajuci file u objavi, prenosi sinteticki TXT te ga preuzima u aktivnom, arhiviranom i vracenom stanju. Bajtovi i izvorni naziv moraju ostati jednaki. Nakon povrata tehnicki preduvjeti objave su popunjeni, proizvod i dalje ostaje nacrt i anonimni admin pristup je odbijen.

Ovo priprema i provjerava samo administratorski rad s datotekama. Ne objavljuje digitalni proizvod, ne kupuje ga i ne provjerava niti dodjeljuje kupceva placena prava. Zadani ignorirani izlaz `storage/app/private/admin-files-browser-qa` sadrzi screenshotove, izvjestaj i poznati sinteticki TXT, bez stvarnih privatnih trgovcevih datoteka ili lozinki.
