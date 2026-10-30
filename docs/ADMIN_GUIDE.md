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
3. Čitaj oznaku cijene: obrazac izričito navodi je li cijena s porezom ili bez njega. U početnim razvojnim podacima cijena 8 EUR bez poreza uz testnih 25% daje 10 EUR u trgovini. Porezne kategorije i testne stope nisu potvrđena pravila za stvarnog trgovca.
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
2. Otvori **Na završetak kupnje**, unesi izmišljene testne podatke i pregledaj spremljenu adresu, dostavu, porez i konačan iznos.
3. Potvrdi da je riječ o testu i predaj testnu narudžbu. Na simulatoru izaberi uspjeh ili odbijanje. Nakon odbijanja moguć je novi pokušaj dok testna narudžba vrijedi.
4. Uspješna simulacija jednom umanjuje razvojnu zalihu i sprema testni zapis plaćanja. Nema stvarnog terećenja niti slanja paketa. Nemoj unositi kartične podatke.

U početnoj ponudi majica od 25 EUR i HR dostava od 5 EUR daju ukupno 30 EUR za jedan komad. Nakon promjene cijena ili tarifa očekuj iznose spremljene u vlastitom pregledu narudžbe. Potvrda predane narudžbe čuva povijesne iznose i odabranu kombinaciju.

## Narudžbe i plaćanja

U **Narudžbe** pronađi broj s potvrde. Detalj prikazuje spremljene adrese, stavke, iznose i transakcije. **Preuzmi sažetak (PDF)** daje interni pregled, jasno označen kao test kada je riječ o simulaciji. To nije račun ni dokument za stvarnu uplatu.

U **Plaćanja** možeš pretražiti broj narudžbe i filtrirati uspješne/neuspješne pokušaje. **Testno potvrđeno** i **Testno odbijeno** su ishodi simulatora. Iz detalja zapisa poveznica na broj narudžbe otvara njezinu povijest. Ovi ekrani služe pregledu; ne nude stvarnu naplatu ni povrat novca.

## Privatne datoteke i povlačenje ponude

**Digitalne datoteke** pripremaju privatne datoteke do 10 MB za digitalni SKU. Administrator ih može preuzeti, arhivirati i vratiti; arhiva čuva sadržaj. Sam upload ne daje kupcu plaćeno pravo preuzimanja. Digitalna kupnja još nije uključena u razvojni checkout.

**Vrati u nacrt** uklanja proizvod iz ponude i čuva povijest. SKU se arhivira ili vraća dok je proizvod nacrt. Brisanje cijelih proizvoda nije uvedeno.

Za pripremljene teme vidi [THEMES.md](THEMES.md), a za odgođene integracije i poslovne odluke [STATUS.md](STATUS.md).
