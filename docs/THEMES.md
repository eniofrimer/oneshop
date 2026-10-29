# Pripremljene teme trgovine

Četiri početna rasporeda koriste iste Blade obrasce, javni scope kataloga, izbor SKU-a, gostujuću košaricu i razvojni checkout. Tema ne stvara zasebne proizvode, cijene, sesije ni narudžbe.

| Ključ | Izgled |
| --- | --- |
| `everyday` | Referentna topla zelena tema, uvodni tekst uz ilustraciju, tri kartice u desktop retku. |
| `atelier` | Serifni naslovi, topli zemljani tonovi, središnji uvod, široka ilustracija i dvije veće kartice. |
| `studio` | Naglašena tipografija, hladni plavi tonovi, ilustracija lijevo, uspravne kartice i izraženiji rubovi. |
| `market` | Zaobljene kartice i gumbi, uvodni panel, zelena i krem paleta. |

## Odabir pri instalaciji

U konfiguraciji konkretne instance postaviti npr. `ONESHOP_THEME=atelier`. Zadani izgled je `everyday`; postojeći VM pregled zadržava taj odabir. Nakon promjene osvježiti Laravelov config cache prema postupku deploya. U produkcijskom Docker profilu ponovno kreirati app/worker/scheduler servise kako bi preuzeli novu vrijednost okoline.

Nepoznata ili neispravna vrijednost u javnom prikazu sigurno pada na referentnu temu, a `oneshop:check-runtime` prijavljuje neispravnu konfiguraciju. Odabir ne dolazi iz URL-a, posted obrasca ili proizvoljne putanje datoteke.

Ovo su pripremljeni izgledi, a ne slobodan vizualni builder ili završen sustav sadržajnih blokova. Trgovčev branding, fotografije, tekstovi, odabir jezika i opseg uređivanja sadržaja ostaju zasebna konfiguracija/odluka. Sve teme trenutačno koriste istu izričito razvojnu ponudu.

## Provjera

Integracijski test prolazi katalog, proizvod, košaricu, adresu i pregled kroz sve teme nad istom košaricom, uz nepromijenjenu zalihu i bez predaje narudžbe. Manualni **Browser journey** workflow ima zaseban izolirani posao za svaku temu: desktop, mobilnu emulaciju i no-JS kupnju te po 14 axe provjera po temi. Upute i granice: [BROWSER_TESTING.md](BROWSER_TESTING.md).
