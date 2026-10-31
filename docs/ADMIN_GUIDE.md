# Kratke upute za razvojni OneShop

Ove upute opisuju isporučeni razvojni pregled. Stvarna naplata, račun i slanje paketa još nisu uključeni. Aktualni rezultati provjera i jutarnji nastavak su u [STATUS.md](STATUS.md).

## Prijava i početni pregled

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Administracija: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Razvojni vlasnički račun: `admin@oneshop.local`. Lozinka je predana zasebno. Taj izričito kreirani superadmin ima sve ovlasti i nema obavezan 2FA.

Adresa pripada stalnoj kartici ove VM. Dostupnost je provjerena iz VM-a; Windows pristup toj adresi nije neovisno potvrđen.

Običan administratorski račun pri prvom pristupu postavlja aplikaciju za autentifikaciju. Skeniraj prikazani QR ili ručno unesi ključ, upiši kod iz aplikacije i trenutačnu lozinku te spremi rezervne kodove. Nakon odjave prijava traži i kod. Već iskorišteni kod ne može se ponovno upotrijebiti; pričekaj novi kod iz aplikacije. Rezervni kod vrijedi jednom. Odabir rezervnog koda briše raniji pogrešan OTP; upiši rezervni kod i klikni potvrdu. Ako su kodovi potrošeni, i dalje možeš koristiti aplikaciju za autentifikaciju. Postavke su na **Profil → Dvofaktorska provjera (2FA)**. Ovaj postupak odnosi se na obične administratore; izričito kreirani razvojni vlasnik koristi gore opisanu iznimku bez obaveznog 2FA.

Nadzorna ploča vodi na proizvode, SKU-ove, narudžbe i dostavu. Brojevi narudžbi uključuju razvojne simulacije; ne predstavljaju prihod. Poveznica **Otvori trgovinu** vodi u javni katalog.

Pretraga u vrhu admina traži po nazivu proizvoda ili aktivnom SKU-u, uključujući nacrte. Upiši najmanje dva znaka; prikazuje se do 20 proizvoda. Strelicama odaberi rezultat, a Enter otvara editor. Za detaljniji popis koristi pretragu i filter statusa u **Proizvodi**.

U **Postavke → Aktivnosti** možeš pregledati objavu/povlačenje proizvoda, arhivu SKU-ova, promjene dostave i privatnih datoteka te rezerviranje, potrošnju i oslobađanje zalihe. Poveznica na broj narudžbe otvara njezin detalj. To je pregled podržanih zabilježenih događaja, ne potpuna povijest svih izmjena cijena ili korisničkih radnji. Sirovi sadržaj zapisa i privatne putanje nisu izloženi.

## Jednostavan fizički proizvod

1. Otvori **Proizvodi → Dodaj proizvod**. Odaberi vrstu kataloga, upiši naziv i opis u primarnom jezičnom tabu te spremi nacrt. Na razvojnom previewu postoji vrsta **Demo kolekcija**.
2. Na tabu **SKU-ovi, cijene i zaliha** izaberi **Dodaj SKU**. Unesi jedinstvenu oznaku, cijenu, poreznu kategoriju i zalihu. Za fizički proizvod ostavi uključeno **Zahtijeva fizičku dostavu**.
3. Čitaj oznaku cijene: obrazac i popis SKU-ova izričito navode je li osnovna EUR cijena s porezom ili bez njega. U početnim razvojnim podacima cijena 8 EUR bez poreza uz testnih 25% daje 10 EUR u trgovini. Ako popis traži provjeru cijene, valute ili pakiranja, pregledaj spremljene podatke prije objave. Porezne kategorije i testne stope nisu potvrđena pravila za stvarnog trgovca.
4. Na tabu **Fotografije** dodaj JPEG, PNG ili WebP, naziv i opis slike. Pričekaj **Prijenos završen** pa potvrdi spremanje. Limit je 5 MB i 4096 × 4096 piksela po slici, najviše deset fotografija.
5. Prva fotografija je naslovna. Redoslijed se mijenja povlačenjem u načinu za promjenu redoslijeda. Fotografije pripadaju galeriji proizvoda. Na SKU-u akcija **Fotografija SKU-a** bira jednu od njih za tu varijantu. Javne su i dok je proizvod nacrt, pa ovdje ne prenositi privatne dokumente.
6. Otvori **Objava u katalogu**. Pregled koristi spremljene podatke. Odaberi potrebne kanale i grupe kupaca te potvrdi objavu. Razvojni katalog koristi **Web trgovina** i **Maloprodaja**.

Na popisu proizvoda **Provjera** pokazuje što nedostaje za objavu. Mala slika koristi istu naslovnu kao trgovina. **Otvori u trgovini** na editoru dostupnog proizvoda otvara njegov javni detalj.

Detalj proizvoda koristi manje responsive fotografije kada su javne konverzije spremne. Izbor sličice mijenja fotografiju i njen opis, a **Otvori fotografiju u punoj veličini** otvara original u novoj kartici. Bez JavaScripta sličice ostaju izravne poveznice na originale. Dok worker još obrađuje prijenos, prikazuje se postojeći javni original.

## Proizvod s varijantama

Na tabu **SKU-ovi, cijene i zaliha** odaberi **Fotografija SKU-a** uz željeni redak i izaberi fotografiju iz njegove galerije. Za M i L iste boje odaberi istu fotografiju. Prazan odabir vraća naslovnicu; ne briše datoteku niti mijenja cijenu i zalihu. Prikaz boje mijenja sliku čim svi preostali odgovarajući SKU-ovi dijele istu fotografiju, a naručivanje i dalje zahtijeva potpuni odabir. Košarica prikazuje fotografiju odabranog SKU-a, uključujući kupnju bez JavaScripta.

1. U **Opcije** pripremi zajedničku opciju, primjerice **Veličina**, s vrijednostima **S** i **M**. Izaberi padajući izbornik ili radio gumbe kao željeni prikaz.
2. Na novom nacrtu proizvoda odaberi tu opciju u **Opcije varijanti** i spremi proizvod.
3. Dodaj jedan SKU za **S**, drugi za **M**. Svaki ima svoju oznaku, cijenu i zalihu. Uz dvije opcije svaki SKU mora izabrati po jednu vrijednost iz obje.
4. Prije objave provjeri da nema dvostrukih kombinacija i da svaki SKU ima cijenu. Ne moraju postojati sve moguće kombinacije; trgovina odbija kombinaciju koja nije pripremljena.

Početna demo majica već ima četiri kombinacije boje i veličine. Odabir u trgovini mijenja odgovarajući SKU i cijenu, a u košarici ostaje ljudski čitljiv naziv kombinacije. Fotografija u košarici služi prepoznavanju proizvoda.

## Kategorije i dostava

U **Kategorije** napravi grupu i kategorije, pa ih odaberi na proizvodu. Podkategorija može biti u istoj grupi; vlastiti potomak i ciklus se odbijaju. Administratorske kategorije još nisu uređivač javnog navigacijskog izbornika.

**Dostava** uređuje ručne tarife po državama, cijenu, poreznu kategoriju i dostupnost na checkoutu. U početnim demo podacima HR dostava iznosi 5 EUR s testnim porezom. Ugovoreni cjenici, kurirski API-ji, paketomati i slanje adresnica ostaju za zaseban nastavak.

Ako je drugi tab ili kupnja već promijenila zalihu/dostavnu tarifu, stari obrazac odbija spremanje. Ponovno otvori zapis i pregledaj novije podatke prije nove izmjene.

## Probna kupnja

1. U trgovini izaberi proizvod, konkretnu varijantu i količinu. Dodaj ga u košaricu; tamo možeš promijeniti količinu ili ukloniti stavku.
2. Otvori **Na završetak kupnje**, unesi izmišljene testne podatke i pregledaj spremljenu adresu, dostavu, porez i konačan iznos. **Promijeni adresu** vraća sačuvane unose, uključujući neobavezni dodatak adresi.
3. Potvrdi da je riječ o testu i predaj testnu narudžbu. Na simulatoru izaberi uspjeh ili odbijanje. Nakon odbijanja moguć je novi pokušaj dok testna narudžba vrijedi.
4. Uspješna simulacija jednom umanjuje razvojnu zalihu i sprema testni zapis plaćanja. Nema stvarnog terećenja niti slanja paketa. Nemoj unositi kartične podatke.

U početnoj ponudi majica od 25 EUR i HR dostava od 5 EUR daju ukupno 30 EUR za jedan komad. Nakon promjene cijena ili tarifa očekuj iznose spremljene u vlastitom pregledu narudžbe. Potvrda predane narudžbe čuva povijesne iznose i odabranu kombinaciju.

## Narudžbe i plaćanja

Navigacija je podijeljena na **Katalog**, **Prodaja** i **Postavke**, s ikonama uz ekrane. Nadzorna ploča prikazuje posljednje predane narudžbe; klik na redak otvara njezin detalj. U popisu narudžbi možeš tražiti i po e-pošti te odvojiti simulator, Stripe sandbox i standardne narudžbe. Testni načini ostaju jasno označeni u detalju i PDF-u.

**Moje narudžbe** u zaglavlju trgovine vraća na testne narudžbe predane u istom pregledniku. Moguće je nastaviti nedovršeni simulator, ponoviti odbijeni pokušaj ili ponovno otvoriti potvrdu nakon nove kupnje. Pristup dolazi iz postojeće sesije, ne iz e-pošte ili broja upisanog u URL. To nije korisnički račun: drugi preglednik ili brisanje sesije nema tu povijest. Broj uz košaricu prikazuje ukupnu količinu, a predana narudžba ne ulazi u novu košaricu.

Admin, potvrde, povijest i PDF koriste isti prikaz vremena, zadano `Europe/Zagreb`; baza i aplikacijski sat ostaju UTC. Druga instanca može postaviti `ONESHOP_DISPLAY_TIMEZONE` na valjanu IANA zonu. `oneshop:check-runtime` provjerava postavku.

Demo izlog koristi [AI ogledne fotografije](DEMO_ASSETS.md) šalice, torbe i majice, u lokalnim WebP veličinama. Uploadana galerija uvijek ima prednost; fotografije i proizvodi stvarnog trgovca nisu prepisani.

U **Narudžbe** pronađi broj s potvrde. Detalj prikazuje spremljene adrese, stavke, iznose i transakcije. **Preuzmi sažetak (PDF)** daje interni pregled, jasno označen kao test kada je riječ o simulaciji. Dugi nazivi, SKU-ovi i adrese prelamaju se unutar stupaca; veći dokument ponavlja zaglavlje tablice na sljedećim stranicama. To nije račun ni dokument za stvarnu uplatu.

U **Plaćanja** možeš pretražiti broj narudžbe i filtrirati uspješne/neuspješne pokušaje. **Testno potvrđeno** i **Testno odbijeno** su ishodi simulatora. Iz detalja zapisa poveznica na broj narudžbe otvara njezinu povijest. Ovi ekrani služe pregledu; ne nude stvarnu naplatu ni povrat novca.

**Pokušaji naplate** prikazuje zasebne Stripe sandbox pokušaje, uključujući **Nepoznat ishod** i **Potrebna provjera**. Detalj uspoređuje očekivani iznos s potvrdom procesora i zasebno prikazuje dodjelu zalihe. Druga valuta potvrde ne prikazuje se kao EUR narudžbe. Ekran nema ručno proglašavanje uspjeha, brisanje ili povrat novca.

Stripe sandbox opcija u košarici pojavljuje se samo uz izričito uključivanje i potpune privatne testne postavke. To je drugi testni put, odvojen od simulatora: pregled → priprema narudžbe → Stripeova testna stranica. Povratak na OneShop nije potvrda naplate; stanje dolazi iz provjere procesora. U tom putu koriste se samo testne kartice, a ne stvarni kartični podaci. Stvarna merchant proba još čeka ključeve i nije označena dovršenom. [Detalji i dokazi](PAYMENT_ATTEMPTS.md).

## Privatne datoteke i povlačenje ponude

**Digitalne datoteke** pripremaju privatne datoteke do 10 MB za digitalni SKU. Administrator ih može preuzeti, arhivirati i vratiti; arhiva čuva sadržaj. Sam upload ne daje kupcu plaćeno pravo preuzimanja. Digitalna kupnja još nije uključena u razvojni checkout.

**Vrati u nacrt** uklanja proizvod iz ponude i čuva povijest. SKU se arhivira ili vraća dok je proizvod nacrt. Brisanje cijelih proizvoda nije uvedeno.

Za pripremljene teme vidi [THEMES.md](THEMES.md), a za odgođene integracije i poslovne odluke [STATUS.md](STATUS.md).


## Rezervirano i raspoloživo

**Katalog → Zalihe** okuplja aktivne SKU-ove svih proizvoda, uključujući nacrte. Pretraži SKU ili primarni naziv proizvoda, zatim po potrebi filtriraj raspoloživost, praćenje, status proizvoda i fizičku isporuku. Stupci pokazuju ukupnu, rezerviranu i raspoloživu količinu; neograničeni SKU ima oznaku **Bez ograničenja**. Klik na redak otvara pripadajući proizvod za uređivanje. Ovaj pregled nema izravno mijenjanje zalihe i ne osvježava se automatski: nakon kupnje osvježi stranicu. Broj **Aktivni SKU-ovi** na nadzornoj ploči vodi na isti pregled.

SKU popis uz ukupnu zalihu prikazuje rezerviranu i raspoloživu količinu. Predana testna narudžba rezervira robu dok čeka simulator. Primjer: 20 na stanju, dva rezervirana → 18 raspoloživih. Uspjeh umanjuje fizičku zalihu, a otkazivanje vraća raspoloživost. Odbijanje čuva rezervaciju za ponovni pokušaj do postojećeg razvojnog roka od 30 minuta.

Ne možete spremiti zalihu manju od rezervirane niti promijeniti praćenje/fizičku isporuku ili arhivirati SKU s aktivnom rezervacijom. Stari obrazac i dalje traži ponovno otvaranje ako je kupnja u međuvremenu promijenila fizičku zalihu. Napuštene testne narudžbe obrađuje projektni scheduler. Detalji: [rezervacije](INVENTORY.md). Stvarna naplata i njeni rokovi ostaju zaseban korak.
