# Kratke upute za razvojni OneShop

Ove upute opisuju isporučeni razvojni pregled. Stvarna naplata, račun i slanje paketa još nisu uključeni. Aktualni rezultati provjera i jutarnji nastavak su u [STATUS.md](STATUS.md).

## Prijava i početni pregled

- Trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/)
- Administracija: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login)
- Razvojni vlasnički račun: `admin@oneshop.local`. Lozinka je predana zasebno. Taj izričito kreirani superadmin ima sve ovlasti i nema obavezan 2FA.

Adresa pripada stalnoj kartici ove VM. Dostupnost je provjerena iz VM-a; Windows pristup toj adresi nije neovisno potvrđen.

Nadzorna ploča vodi na proizvode, SKU-ove, narudžbe i dostavu. Brojevi narudžbi uključuju razvojne simulacije; ne predstavljaju prihod. Poveznica **Otvori trgovinu** vodi u javni katalog.

## Jednostavan fizički proizvod

1. Otvori **Proizvodi → Dodaj proizvod**. Odaberi vrstu kataloga, upiši naziv i opis u primarnom jezičnom tabu te spremi nacrt. Na razvojnom previewu postoji vrsta **Demo kolekcija**.
2. Na tabu **SKU-ovi, cijene i zaliha** izaberi **Dodaj SKU**. Unesi jedinstvenu oznaku, cijenu, poreznu kategoriju i zalihu. Za fizički proizvod ostavi uključeno **Zahtijeva fizičku dostavu**.
3. Čitaj oznaku cijene: obrazac i popis SKU-ova izričito navode je li osnovna EUR cijena s porezom ili bez njega. U početnim razvojnim podacima cijena 8 EUR bez poreza uz testnih 25% daje 10 EUR u trgovini. Ako popis traži provjeru cijene, valute ili pakiranja, pregledaj spremljene podatke prije objave. Porezne kategorije i testne stope nisu potvrđena pravila za stvarnog trgovca.
4. Na tabu **Fotografije** dodaj JPEG, PNG ili WebP, naziv i opis slike. Pričekaj **Prijenos završen** pa potvrdi spremanje. Limit je 5 MB i 4096 × 4096 piksela po slici, najviše deset fotografija.
5. Prva fotografija je naslovna. Redoslijed se mijenja povlačenjem u načinu za promjenu redoslijeda. Fotografije pripadaju proizvodu; nije uvedena zasebna galerija po SKU-u. Javne su i dok je proizvod nacrt, pa ovdje ne prenositi privatne dokumente.
6. Otvori **Objava u katalogu**. Pregled koristi spremljene podatke. Odaberi potrebne kanale i grupe kupaca te potvrdi objavu. Razvojni katalog koristi **Web trgovina** i **Maloprodaja**.

Na popisu proizvoda **Provjera** pokazuje što nedostaje za objavu. Mala slika koristi istu naslovnu kao trgovina. **Otvori u trgovini** na editoru dostupnog proizvoda otvara njegov javni detalj.

## Proizvod s varijantama

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

U **Narudžbe** pronađi broj s potvrde. Detalj prikazuje spremljene adrese, stavke, iznose i transakcije. **Preuzmi sažetak (PDF)** daje interni pregled, jasno označen kao test kada je riječ o simulaciji. To nije račun ni dokument za stvarnu uplatu.

U **Plaćanja** možeš pretražiti broj narudžbe i filtrirati uspješne/neuspješne pokušaje. **Testno potvrđeno** i **Testno odbijeno** su ishodi simulatora. Iz detalja zapisa poveznica na broj narudžbe otvara njezinu povijest. Ovi ekrani služe pregledu; ne nude stvarnu naplatu ni povrat novca.

**Pokušaji naplate** prikazuje zasebne Stripe sandbox pokušaje, uključujući **Nepoznat ishod** i **Potrebna provjera**. Detalj uspoređuje očekivani iznos s potvrdom procesora i zasebno prikazuje dodjelu zalihe. Druga valuta potvrde ne prikazuje se kao EUR narudžbe. Ekran nema ručno proglašavanje uspjeha, brisanje ili povrat novca.

Stripe sandbox opcija u košarici pojavljuje se samo uz izričito uključivanje i potpune privatne testne postavke. To je drugi testni put, odvojen od simulatora: pregled → priprema narudžbe → Stripeova testna stranica. Povratak na OneShop nije potvrda naplate; stanje dolazi iz provjere procesora. U tom putu koriste se samo testne kartice, a ne stvarni kartični podaci. Stvarna merchant proba još čeka ključeve i nije označena dovršenom. [Detalji i dokazi](PAYMENT_ATTEMPTS.md).

## Privatne datoteke i povlačenje ponude

**Digitalne datoteke** pripremaju privatne datoteke do 10 MB za digitalni SKU. Administrator ih može preuzeti, arhivirati i vratiti; arhiva čuva sadržaj. Sam upload ne daje kupcu plaćeno pravo preuzimanja. Digitalna kupnja još nije uključena u razvojni checkout.

**Vrati u nacrt** uklanja proizvod iz ponude i čuva povijest. SKU se arhivira ili vraća dok je proizvod nacrt. Brisanje cijelih proizvoda nije uvedeno.

Za pripremljene teme vidi [THEMES.md](THEMES.md), a za odgođene integracije i poslovne odluke [STATUS.md](STATUS.md).


## Rezervirano i raspoloživo

SKU popis uz ukupnu zalihu prikazuje rezerviranu i raspoloživu količinu. Predana testna narudžba rezervira robu dok čeka simulator. Primjer: 20 na stanju, dva rezervirana → 18 raspoloživih. Uspjeh umanjuje fizičku zalihu, a otkazivanje vraća raspoloživost. Odbijanje čuva rezervaciju za ponovni pokušaj do postojećeg razvojnog roka od 30 minuta.

Ne možete spremiti zalihu manju od rezervirane niti promijeniti praćenje/fizičku isporuku ili arhivirati SKU s aktivnom rezervacijom. Stari obrazac i dalje traži ponovno otvaranje ako je kupnja u međuvremenu promijenila fizičku zalihu. Napuštene testne narudžbe obrađuje projektni scheduler. Detalji: [rezervacije](INVENTORY.md). Stvarna naplata i njeni rokovi ostaju zaseban korak.
