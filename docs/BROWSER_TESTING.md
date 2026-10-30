# Provjera trgovine u pregledniku

`npm run test:browser` pokreće stvarni Chromium kroz Playwright. Test prolazi desktop (1400 × 1000), mobilnu emulaciju (390 × 844) i desktop s isključenim JavaScriptom. Ne koristi administratorski račun ni spremljeni profil preglednika.

## Izolirana CI provjera

U GitHub Actions ručno pokrenuti **Browser journey** (`browser.yml`). Za svaku od četiri teme zaseban Chromium posao gradi razvojni Docker profil, zaseban PostgreSQL/Redis i prazan demo katalog s demo dostavom. Simulator je izričito uključen samo u toj lokalnoj instanci. Nakon testa brišu se samo servisi i volumeni tog CI projekta.

Provjeravaju se živa pretraga, prazan rezultat, povrat cijelog kataloga, konkretna kombinacija boje/veličine, promjena količine, unos adrese, obračun dostave/poreza, odbijanje i ponovni pokušaj te tri uspješne simulacije. Očekivani ukupni iznosi u svježem demo katalogu su 55, 30 i 20 EUR. Nova demo instalacija koristi radio gumbe za boju i dropdown za veličinu. Bez JavaScripta ostaje izvorni izbor SKU-a i cijeli checkout.

Test odbija zahtjeve prema drugim domenama, bilježi JavaScript/console greške i provjerava vodoravno prelijevanje na svim kupovnim koracima. U košarici i pregledu provjerava i ljudski čitljiv odabir boje/veličine. Screenshotovi i JSON rezultat sadrže samo izmišljene testne podatke; artefakt se zadržava sedam dana. Ne spremaju se sesijski kolačići ni administratorske lozinke.

Košarica mora učitati malu fotografiju proizvoda u sva tri načina rada. Fotografija je dekorativna uz postojeći naziv/odabir. Test je pomiče u prikaz radi izvornog lazy učitavanja i vremenski ograničeno provjerava stvarno učitane dimenzije; ne čeka neograničen decode Promise u pregledniku bez JavaScripta. Screenshotovi uključuju i košaricu.

Na desktopu i mobilnoj emulaciji axe-core provjerava WCAG 2 A/AA i 2.1 AA pravila na sedam koraka: naslovnica, proizvod, košarica, adresa, pregled, simulator i uspjeh. Desktop dodatno provjerava serversko odbijanje neispravne e-pošte, hrvatsku poruku uz polje, očuvanje ostalih podataka i fokus na sažetku grešaka. Izravni neispravni upit pretrage mora vratiti 422 bez preusmjeravanja; ispravak mora ukloniti stare poruke, kroz AJAX na desktopu ili izvorni GET bez JS-a. Desktop provjerava axe i prije/nakon ispravka, ukupno 17 provjera po temi. Rezultat svakog koraka sprema potvrđene greške i nalaze koji traže ručnu provjeru. Potvrđena greška prekida test; pravila se ne isključuju. U sva tri scenarija provjerava se i preskakanje navigacije tipkovnicom. No-JS scenarij nema axe provjeru jer alat zahtijeva izvršavanje JavaScripta.

## Lokalno ponavljanje

`npm run test:browser:orders` zasebno prolazi praznu i privatnu listu **Moje narudžbe**, broj artikala, predaju, povrat iz trgovine, odbijanje pa uspjeh, nastavak nakon nove košarice te zabranu pristupa iz drugog browser konteksta. Desktop, uski prikaz i no-JS predaju ukupno tri testne narudžbe. Potrebni su izričit `ONESHOP_BROWSER_ALLOW_WRITES=true` i potrošna instanca. Dodaje osam axe provjera i dvanaest provjera širine; no-JS nema axe. Workflow čuva i mapu `orders-browser-qa` uz postojeće izvještaje. Povijest ni pri čitanju ne šalje provider zahtjeve.

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

Lokalne gostujuće provjere izvedene su u Chromiumu, Firefoxu i WebKitu unutar Ubuntu VM-a; zaseban CI pokriva iste enginee. Mobilni scenarij je emulacija, a ne fizički telefon. Nisu neovisno provjereni Windows preglednik, stvarni Safari/iPhone, procesor naplate ni slanje paketa. PDF bajtovi i download provjeravaju se administratorskim, aplikacijskim i Docker testovima. Odvojeni lokalni prikaz PDF-a potvrđuje izgled jednog sintetičkog dokumenta, hrvatske znakove i iznos; browser tok sam po sebi ne pregledava PDF izgled.

Automatski prolaz nije potvrda potpune pristupačnosti: ručni pregled sa čitačem ekrana, razumljivost sadržaja, cjelovita navigacija tipkovnicom i stvarni uređaji ostaju zasebne provjere.


## Administratorski tok s novim proizvodom

`npm run test:browser:admin` namijenjen je iskljucivo zasebnoj, potrosnoj local/testing demo instalaciji. Zahtijeva izriciti `ONESHOP_ADMIN_BROWSER_ALLOW_WRITES=true`, URL te e-postu/lozinku posebno kreiranog testnog superadmina kroz `ONESHOP_ADMIN_BROWSER_EMAIL` i `ONESHOP_ADMIN_BROWSER_PASSWORD`. Lozinku ne upisivati u repozitorij. Test nema zadane pristupne podatke i ne kreira korisnika sam.

Rucni workflow **Administrator browser journey** koristi vlastite PostgreSQL i MariaDB poslove s odvojenim volumenima. Svaki izricito kreira nasumicnu privremenu lozinku, skriva je u Actions ispisu i uklanja instalaciju nakon provjere. Koristi database sesije/cache/queue i stvarnog workera za fotografije. Screenshotovi i PDF imaju samo sinteticke podatke; sesije i lozinke nisu u artefaktu. Dokazi se cuvaju sedam dana.

Stvarnim klikovima kreira se zajednicka radio opcija s vrijednostima Mat/Sjaj, zatim nacrt proizvoda i dva SKU-a s razlicitim cijenama/zalihama. Sprema se porezna klasa, potvrduje objava i prenose dvije vlastite PNG testne ilustracije. Drag promjena redoslijeda mora prezivjeti reload, promijeniti naslovnu fotografiju i javnu karticu. U trgovini tipkovnicki odabir slicice mora promijeniti glavnu sliku/alt/aria-current i zadrzati odabir SKU-a. Gost mora prebaciti opciju s 15 na 10 EUR prije kupnje. Test ceka vidljivi zavrsetak prijenosa, a zatim javne 500/800 px konverzije iz workera. Zaseban gost kupuje taj proizvod za 15 EUR (10 EUR s testnim porezom + 5 EUR demo dostava). Otvoren stari SKU obrazac mora odbiti prepisivanje nakon kupnje; ponovni prikaz mora imati zalihu 9 i izvornu cijenu 8 EUR bez poreza. Narudzba, testna transakcija i stvarni PDF download provjeravaju se u adminu. Zavrsna pretraga istog SKU-a na popisu proizvoda mora pokazati novu naslovnu malu fotografiju sa spremljenim alt tekstom; preglednik je mora stvarno dekodirati. Nema stvarne naplate ni slanja paketa.

Ovaj tok dodaje proizvod, dvije javne fotografije, narudzbu i transakciju. Provjerava i hrvatski testni ishod u pregledu placanja te poveznicu iz detalja placanja na istu narudzbu. Pretraga na kraju ceka stvarno filtrirani popis s jednim redom prije provjere naslovne. Ne pokretati ga nad korisnikovim radnim previewom ili trgovcevom instalacijom. Objavljena fotografija je javna i za nacrt. Zadani privatni izlaz je `storage/app/private/admin-browser-qa`; podrzani su isti `ONESHOP_BROWSER_EXECUTABLE` i `ONESHOP_BROWSER_OUTPUT` kao u gostujucem testu. PNG fixture je sinteticka ilustracija bez vanjskih izvora/licenci.


## Stranice kataloga

Nakon tri kupovna scenarija workflow dodaje 13 sintetickih proizvoda s oznakom `QA Stranica`; postojeci katalog i zalihe ne resetira. Fixture je dopusten samo izricito u izoliranom CI poslu. `npm run test:browser:pagination` provjerava prvu/drugu/praznu stranicu i povratak, uz iste filtre (12/1/0/12 kartica), desktop tipkovnicu, mobilni dodir i no-JS linkove. AJAX promjena stranice pomice fokus na naslov rezultata, a unos pretrage zadrzava fokus. Kontrole su zaseban storefront Blade predlozak; admin/vendor paginator nije mijenjan.

Osam dodatnih axe provjera po temi pokriva sve cetiri paginacijske faze na desktopu i mobilnoj emulaciji. Sama kupnja i paginacija daju 25 provjera po poslu uz postojecih 17 kupovnih/search provjera, odnosno 100 u cetiri Chromium posla. Dodatni Firefox/WebKit poslovi na referentnoj temi podizu taj osnovni skup na 150 provjera i 18 simuliranih kupnji; taj je skup prosao i u CI-u `40994241329`. Izvjestaj paginacije je `pagination-report.json`, odvojen od izvjestaja kupnje. Ovo je funkcionalni fixture od 13 proizvoda, ne benchmark velikog kataloga.


## Dodatni preglednici

`ONESHOP_BROWSER_ENGINE` bira `chromium` (zadano), `firefox` ili `webkit` za gostujucu kupnju i paginaciju. Izvjestaji biljeze engine. `ONESHOP_BROWSER_EXECUTABLE`, ako se zada, mora pripadati odabranom engineu; inace Playwright koristi svoj odgovarajuci instalirani preglednik.

CI ima cetiri Chromium teme i dodatne Firefox/WebKit poslove za referentni `everyday`. Svaki ima vlastitu bazu, projekt i artefakt, ukljucujuci engine u nazivu. Firefox uski prikaz koristi viewport i dodir, jer ne podrzava Playwrightov `isMobile`; to nije Android preglednik. WebKit na Linuxu nije neovisna provjera Apple Safarija ili fizickog iPhonea. Administratorski browser tok ostaje zasebna Chromium provjera na dva SQL enginea.


## Stvarna administratorska MFA prijava

`npm run test:browser:admin-mfa` zahtijeva potrošan obični admin račun bez ranijeg MFA upisa, `ONESHOP_MFA_BROWSER_EMAIL`, `ONESHOP_MFA_BROWSER_PASSWORD` i isti izričiti `ONESHOP_ADMIN_BROWSER_ALLOW_WRITES=true`. U CI-u se račun stvara zasebno generiranom lozinkom, bez `--super-admin`. Tok slijedi tri postojeće admin probe na oba SQL enginea.

Browser prolazi obavezni upis, lokalni QR, kod i potvrdu lozinke, odbijanje pristupa prije MFA-a, pogrešan pa valjan TOTP, odjavu i odbijanje istog koda dok je vremenski prozor još važeći. Zatim koristi rezervni kod jednom, odbija njegovo ponavljanje i prihvaća svježi TOTP. Ne mijenja autentifikacijske politike ni rate limite. Dva namjerna prozora od 62 sekunde poštuju native limiter; ne prazni se cache. Neovisni testni TOTP generator slijedi [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238.html), a ključevi nastaju tek tijekom upisa.

Devet faza bez vidljivih tajni ima axe/overflow provjeru i screenshot. Prije snimanja provjeravaju se skriveni QR, odsutnost ključa/rezervnih kodova u vidljivom tekstu i prazni osjetljivi unosi. Upisni modal i lista kodova ne snimaju se; nema tracea, kolačića, mrežnih payloadova ili preuzetih kodova u artefaktima. I pogreške maskiraju poznate vrijednosti i prepoznatljive OTP/recovery obrasce. Nalazi za ručni pregled ostaju u sažetim axe izvještajima; nije provedena fizička proba korisnikova autentifikatora.

## Proizvodi, globalna pretraga i aktivnosti

Glavni administratorski tok dodatno traži upravo kreirani proizvod njegovim SKU-om u globalnoj pretrazi, prolazi rezultat strelicom i Enterom te otvara **Aktivnosti**. Potrošena rezervacija mora voditi na ispravnu narudžbu, a njen privatni checkout identifikator ne smije biti u HTML-u aktivnosti. Screenshotovi `global-product-search` i `safe-activity-history` te odgovarajuće zastavice u izvještaju čuvaju taj dokaz. Hrvatske statusne/ARIA poruke pretrage dolaze iz lokalnog prijevoda, bez izmjene vendora.

Isti tok provjerava `srcset` i stvarno odabrani konvertirani izvor velike fotografije, promjenu izvora/opisa tipkovnicom te pripadnu poveznicu na original. Zaseban kontekst bez JavaScripta mora klikom sličice dobiti stvarni `image/*` odgovor originala. To ne predaje dodatnu narudžbu. Provjera fokusa čeka Filamentov prijenos u sljedećem Alpine ticku; ne postavlja fokus umjesto korisničke tipke.

## Privatne digitalne datoteke u administraciji

`npm run test:browser:admin-files` koristi iste izricite write/URL/QA owner varijable kao administratorski tok. Dodan je istom workflowu na oba SQL enginea. Iz preglednika kreira zaseban digitalni nacrt/SKU, provjerava nedostajuci file u objavi, prenosi sinteticki TXT te ga preuzima u aktivnom, arhiviranom i vracenom stanju. Bajtovi i izvorni naziv moraju ostati jednaki. Nakon povrata tehnicki preduvjeti objave su popunjeni, proizvod i dalje ostaje nacrt i anonimni admin pristup je odbijen.

Ovo priprema i provjerava samo administratorski rad s datotekama. Ne objavljuje digitalni proizvod, ne kupuje ga i ne provjerava niti dodjeljuje kupceva placena prava. Zadani ignorirani izlaz `storage/app/private/admin-files-browser-qa` sadrzi screenshotove, izvjestaj i poznati sinteticki TXT, bez stvarnih privatnih trgovcevih datoteka ili lozinki.


## Kategorije i dostavne tarife

`npm run test:browser:admin-catalog` koristi iste izricite QA pristupne varijable i radi na oba SQL enginea u administratorskom workflowu. Kreira grupu, korijen i podkategoriju, pokusava nedopusten ciklus, dodjeljuje podkategoriju novom nacrtu i provjerava sacuvanu vezu/broj proizvoda. Zatim kreira **iskljucenu** testnu HR dostavu; dva otvorena editora moraju odbiti stariji unos nakon spremanja novije cijene/opisa. Test ne ukljucuje tarifu na checkout niti salje posiljku.

Na kraju provjerava i popis s proizvodima bez fotografije na 1440/390 px. Lokalna neutralna slika mora dati foto poveznici naziv proizvoda i oznaku da fotografija nije dodana. Oba prikaza prolaze puni zadani WCAG 2 A/AA i 2.1 AA axe skup, bez isključenih pravila; spremaju se i nalazi za ručni pregled. To su dvije dodatne provjere po SQL poslu i četiri ukupno, odvojene od javnih 246 provjera.

Fotografije i privatne datoteke biraju se kroz vidljivi FilePond picker i pravi browser `filechooser` dogadjaj. Samo pronalazenje izvornog skrivenog file inputa nije dokaz da je widget inicijaliziran. Slijedi cekanje stvarnog zavrsetka prijenosa; nema prisilnog uspjeha niti zaobilazenja upload validacije. Prazan widget bez upload zahtjeva u PG poslu `40984441261` potaknuo je ovu ispravku testa.

## Dvije varijante u istoj košarici

`npm run test:browser:cart` koristi isti URL/engine/output kao gostujući tok. Namijenjen je vlastitoj potrošnoj demo instalaciji s početnom majicom i raspoloživim kombinacijama Maslinasta/M i Pijesak/L. Stvara vlastitu gostujuću košaricu, mijenja njezine količine i uklanja obje stavke; ne predaje narudžbu niti poziva simulator. Ne pokretati ga nad korisnikovim radnim previewom.

Desktop, uski prikaz i no-JS moraju razlikovati količine i gumbe prema nazivu/kombinaciji. Početne količine 1 i 2 daju 75 EUR; promjena samo prve na 3 daje 125 EUR; uklanjanje druge ostavlja prvu s 3 i 75 EUR. Posljednje uklanjanje vraća praznu košaricu. Slike se moraju stvarno učitati, nema prelijevanja ni JS/vanjskih zahtjeva. Četiri stanja na desktopu i uskom prikazu daju osam dodatnih axe provjera po engineu. `cart-report.json` i `cart-<način>-<korak>` datoteke odvojeni su od kupnje i paginacije.

Workflow izvodi ovaj tok nakon kupnji, prije dodavanja paginacijskih proizvoda, u svih šest poslova. Prošireni puni zbroj **198 axe provjera i 18 simuliranih kupnji** potvrđen je u [CI `300044f`](https://github.com/eniofrimer/oneshop/actions/runs/40999559461); preuzeto je i agregirano svih 18 izvještaja kupnje/košarice/paginacije. Cart tok dodaje provjere, ne dodatne kupnje. Integracijski test odvojeno potvrđuje nepromijenjene zalihe i odsutnost narudžbi/transakcija nakon promjena košarice.


Košarica dodatno provjerava izvorne tipkovničke radnje: Enter u količini šalje ažuriranje, Space aktivira uklanjanje, a dva Tab koraka iz količine vode na ažuriranje pa uklanjanje iste stavke. U mobilnoj emulaciji te gumbe aktivira `tap()`. Izvještaj razlikuje `keyboardControls` i `touchControls`; no-JS koristi obične HTML obrasce. Sva tri enginea lokalno prolaze sva tri načina (24 axe provjere ukupno), bez stvaranja narudžbi. Ovo pokriva te kontrole; ne tvrdi da je cijeli checkout ručno provjeren samo tipkovnicom.


## Checkout tipkovnicom i povratak na adresu

`npm run test:browser:keyboard` koristi iste URL/engine/output varijable i zasebnu potrošnu demo instalaciju. Od naslovnice sve radnje obavlja Tab/Shift+Tab, Enter/Space, strelicama i tipkanjem: skip link, radio boje, veličina, košarica, adresa, izvorna HTML provjera neispravne e-pošte, pregled, sačuvani unosi pri povratku, izmjena dodatka adresi i ponovni pregled. Provjerava odabir dostave te uključivanje/isključivanje testne potvrde. Završava uklanjanjem iz vlastite košarice.

Tok **ne predaje narudžbu**; svaki pokušaj POST-a na predaju/simulator dodatno se blokira i smatra greškom. Provjerava enhanced i no-JS način na desktopu, bez `click()`, `fill()`, `focus()` ili izravnog POST-a za upravljanje sučeljem. Screenshoti i `keyboard-report.json` sadrže samo sintetičke podatke. Ne dodaje axe provjere već pokrivenih stanja ni dodatne kupnje. Sva tri enginea lokalno prolaze oba načina. Firefox pri povratku s donjeg dijela koristi Shift+Tab prema ranijoj poveznici; test ne ovisi o kruženju kroz alatnu traku preglednika. Ovo je dokaz navedenog puta, ne certifikat potpune pristupačnosti ili čitača ekrana.

Workflow ovaj korak izvodi nakon košarice, prije paginacijskih fixturea, na svim četirima Chromium temama i referentnom Firefoxu/WebKitu.


Potpuni novi [browser CI `d573a9b`](https://github.com/eniofrimer/oneshop/actions/runs/41008619140) je zelen. Neovisno su preuzeta sva 24 izvještaja: 198 axe provjera, 18 simuliranih kupnji i 12 keyboard checkout tokova bez novih narudžbi. Svih šest poslova ima potvrđene tipkovničke i dodirne kontrole košarice. Nakon primjene dostave test čeka novu navigaciju glavnog okvira jer odredišni URL ostaje isti; samo usporedba URL-a nije jamčila učitan dokument.


Ponovljeni [browser CI `80e65a6`](https://github.com/eniofrimer/oneshop/actions/runs/41011208467) nakon izmjene checkout/PDF predložaka prolazi svih šest poslova s istih 198 axe / 18 kupnji / 12 keyboard putanja. [Administratorski CI](https://github.com/eniofrimer/oneshop/actions/runs/41011210386) na oba enginea prolazi i novi PDF prikaz. Svih 30 izvještaja preuzeto je i provjereno.
