# Provjera trgovine u pregledniku

`npm run test:browser` pokreće stvarni Chromium kroz Playwright. Test prolazi desktop (1400 × 1000), mobilnu emulaciju (390 × 844) i desktop s isključenim JavaScriptom. Ne koristi administratorski račun ni spremljeni profil preglednika.

## Izolirana CI provjera

U GitHub Actions ručno pokrenuti **Browser journey** (`browser.yml`). Workflow gradi razvojni Docker profil, zaseban PostgreSQL/Redis i prazan demo katalog s demo dostavom. Simulator je izričito uključen samo u toj lokalnoj instanci. Nakon testa brišu se samo servisi i volumeni tog CI projekta.

Provjeravaju se živa pretraga, prazan rezultat, povrat cijelog kataloga, konkretna kombinacija boje/veličine, promjena količine, unos adrese, obračun dostave/poreza, odbijanje i ponovni pokušaj te tri uspješne simulacije. Očekivani ukupni iznosi u svježem demo katalogu su 55, 30 i 20 EUR. Nova demo instalacija koristi radio gumbe za boju i dropdown za veličinu. Bez JavaScripta ostaje izvorni izbor SKU-a i cijeli checkout.

Test odbija zahtjeve prema drugim domenama, bilježi JavaScript/console greške i provjerava vodoravno prelijevanje na naslovnici i pregledu narudžbe. Screenshotovi i JSON rezultat sadrže samo izmišljene testne podatke; artefakt se zadržava sedam dana. Ne spremaju se sesijski kolačići ni administratorske lozinke.

Na desktopu i mobilnoj emulaciji axe-core provjerava WCAG 2 A/AA i 2.1 AA pravila na sedam koraka: naslovnica, proizvod, košarica, adresa, pregled, simulator i uspjeh. Rezultat svakog koraka sprema potvrđene greške i nalaze koji traže ručnu provjeru. Potvrđena greška prekida test; pravila se ne isključuju. U sva tri scenarija provjerava se i preskakanje navigacije tipkovnicom. No-JS scenarij nema axe provjeru jer alat zahtijeva izvršavanje JavaScripta.

## Lokalno ponavljanje

Koristiti samo vlastitu, inicijaliziranu razvojnu demo instancu. **Test predaje tri narudžbe, zapisuje simulirane transakcije i umanjuje demo zalihu.** Ne pokretati ga prema trgovčevoj radnoj instanci ili produkciji. Izmijenjen katalog, cijene, tarife ili potrošena zaliha namjerno mogu uzrokovati pad očekivanja; seed ne resetira podatke.

Nakon `npm ci --ignore-scripts` osigurati Chromium za zaključanu verziju Playwrighta ili navesti postojeći lokalni izvršni program:

```bash
ONESHOP_BROWSER_URL=http://192.168.250.2:8080 \
ONESHOP_BROWSER_EXECUTABLE=/putanja/do/chromium \
npm run test:browser
```

`ONESHOP_BROWSER_URL` je obvezan i označava korijenski URL. `ONESHOP_BROWSER_EXECUTABLE` je neobvezan ako je instaliran odgovarajući Playwright Chromium. `ONESHOP_BROWSER_OUTPUT` može promijeniti izlazni direktorij; zadani je ignorirani privatni `storage/app/private/browser-qa`.

## Granice dokaza

Lokalna provjera izvedena je u Chromiumu unutar Ubuntu VM-a. Mobilni scenarij je emulacija, a ne fizički telefon. Nije neovisno provjeren Windows preglednik, Firefox/Safari, stvarni procesor naplate ni slanje paketa. PDF se zasebno provjerava aplikacijskim i Docker testovima; ova provjera ne pregledava izgled PDF-a.

Automatski prolaz nije potvrda potpune pristupačnosti: ručni pregled sa čitačem ekrana, razumljivost sadržaja, cjelovita navigacija tipkovnicom i stvarni uređaji ostaju zasebne provjere.
