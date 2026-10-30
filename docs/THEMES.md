# Pripremljene teme trgovine

Četiri početna rasporeda koriste iste Blade obrasce, javni scope kataloga, izbor SKU-a, gostujuću košaricu i razvojni checkout. Tema ne stvara zasebne proizvode, cijene, sesije ni narudžbe.

| Ključ | Izgled |
| --- | --- |
| `everyday` | Referentna topla zelena tema, uvodni tekst uz fotografiju, tri kartice u desktop retku. |
| `atelier` | Serifni naslovi, topli zemljani tonovi, središnji uvod, široka fotografija i dvije veće kartice. |
| `studio` | Naglašena tipografija, hladni plavi tonovi, fotografija lijevo, uspravne kartice i izraženiji rubovi. |
| `market` | Zaobljene kartice i gumbi, uvodni panel, zelena i krem paleta. |

## Odabir pri instalaciji

U konfiguraciji konkretne instance postaviti npr. `ONESHOP_THEME=atelier`. Zadani izgled je `everyday`; postojeći VM pregled zadržava taj odabir. Nakon promjene osvježiti Laravelov config cache prema postupku deploya. U produkcijskom Docker profilu ponovno kreirati app/worker/scheduler servise kako bi preuzeli novu vrijednost okoline.

Nepoznata ili neispravna vrijednost u javnom prikazu sigurno pada na referentnu temu, a `oneshop:check-runtime` prijavljuje neispravnu konfiguraciju. Odabir ne dolazi iz URL-a, posted obrasca ili proizvoljne putanje datoteke.

Ovo su pripremljeni izgledi, a ne slobodan vizualni builder ili završen sustav sadržajnih blokova. Trgovčev branding, fotografije, tekstovi, odabir jezika i opseg uređivanja sadržaja ostaju zasebna konfiguracija/odluka. Sve teme trenutačno koriste istu izričito razvojnu ponudu.

## Provjera

Integracijski test prolazi katalog, proizvod, košaricu, adresu i pregled kroz sve teme nad istom košaricom, uz nepromijenjenu zalihu i bez predaje narudžbe. Manualni **Browser journey** workflow ima zaseban izolirani posao za svaku temu: desktop, mobilnu emulaciju i no-JS kupnju, zasebnu košaricu s dvije varijante i paginaciju. Svaki posao ima 17 kupovnih, osam cart i osam paginacijskih axe provjera; šest poslova daje 198 provjera i 18 simuliranih kupnji. Zaseban keyboard tok provjerava adresu i povratak bez predaje nove narudžbe. Upute i granice: [BROWSER_TESTING.md](BROWSER_TESTING.md).

Aktualni workflow dodaje privatni pregled narudžbi u sva tri načina: još osam axe provjera i tri testne kupnje po poslu. [Prihvat za `7dc5525`](https://github.com/eniofrimer/oneshop/actions/runs/41134367383) ima ukupno 246 axe provjera bez potvrđenih povreda i 36 simuliranih kupnji. Generirane ogledne fotografije i njihovi izvori opisani su u [DEMO_ASSETS.md](DEMO_ASSETS.md); prenesena galerija ima prednost pred tim fallbackom.
