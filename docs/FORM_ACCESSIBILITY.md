# Ispravljanje unosa u trgovini

Sažetak pogrešaka iznad sadržaja ima vidljiv naslov i `role="alert"`. JavaScript nakon serverske validacije fokusira taj sažetak. Poruke za poznata polja poveznice su na stvarni unos: kontakt/adresa, količina/odabir proizvoda, upit/redoslijed/dostupnost kataloga te dostava i postojeća testna potvrda narudžbe. Opće poruke ili polja bez jednoznačnog cilja ostaju običan tekst; ne generira se poveznica iz proizvoljnog imena polja.

Poveznica radi kao običan HTML fragment bez JavaScripta. S poboljšanjem klik ili Enter prenosi fokus na dostupno vidljivo polje. Ako je izvorni SKU izbornik skriven, bira prvu vidljivu opciju proizvoda; ne pokušava fokusirati skriveni select. Cilj izvornog HTML-a je imenovani obrazac s `tabindex="-1"`, bez dodatnog mjesta u redovnom Tab nizu. Poruke i href atributi ostaju escaped, a oznake/serverska pravila, spremljeni valjani unosi i konačni iznosi nisu promijenjeni.

Komponenta dobiva poruke izričito iz prikaza. To pokriva i lokalni error bag kod GET 422 filtra, koji nije spremljen u session error bag. Nakon valjane AJAX pretrage postojeći oporavak uklanja poruke i aria-invalid. U običnom POST toku nova serverska provjera odlučuje je li unos valjan.

Browser kupnja namjerno zaobilazi klijentsku required/min zaštitu prije slanja neispravnog proizvoda. Potom provjerava tipkovnički pristup vidljivim opcijama i količini, ispravlja unos i nastavlja istu kupnju. Neispravna adresa e-pošte provjerava se na desktopu, mobilnoj emulaciji i bez JS-a; ostali unosi ostaju sačuvani. Pagination proba također provjerava fragment do neispravnog redoslijeda prije nastavka pretrage. To ne dodaje testne narudžbe izvan postojećih sintetičkih kupnji. Izvršni brojevi/commit i ograničenja dokaza prate [STATUS](STATUS.md).

Ovo slijedi [W3C WAI upute za poruke nakon unosa](https://www.w3.org/WAI/tutorials/forms/notifications/): sažetak, poveznice na polja i povezane inline poruke. Automatizirane provjere nisu zamjena za ručnu provjeru čitačem ekrana.

## Dostava i preostala polja

Opcije dostave imaju native fieldset/legend i vezanu poruku. Poveznica iz sažetka s JavaScriptom fokusira trenutačno odabranu vidljivu radio opciju; izvorni HTML fokusira grupu, iz koje Tab nastavlja na radio. Postojeća testna potvrda ima stabilan cilj i vlastitu inline poruku u oba checkout načina. Pravila prihvaćanja, quote i iznosi ostaju u servisima. Testovi za odbijenu dostavu/potvrdu prate puni POST→redirect→GET te potvrđuju nula provider zahtjeva, narudžbi, pokušaja, potvrda naplate i transakcija. Tipkovnička proba blokira sva postavljanja narudžbe i vraća valjanu dostavu nakon negativnog pokušaja.

Dostupnost u katalogu dobiva vlastitu error vezu. Valjana AJAX promjena uklanja i njenu staru poruku/aria-invalid, uz već podržani upit i redoslijed; bez JS-a radi izvorni GET.

Imenovana native grupa slijedi [WAI smjernice za povezane kontrole](https://www.w3.org/WAI/tutorials/forms/grouping/).
