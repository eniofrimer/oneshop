# Putanja i drugi javni proizvodi

Detalj proizvoda prikazuje putanju **Kolekcija → naziv proizvoda** i, kad postoji drugi javni proizvod, odjeljak **Još iz kolekcije**. To je najviše četiri druga proizvoda u stabilnom ID redoslijedu. Nema tvrdnje o popularnosti, personalizaciji, akciji ili sličnosti koju podaci ne potvrđuju. Kad nema drugih proizvoda, odjeljak se izostavlja.

Izbor koristi isti javni scope kao katalog: status/soft-delete, zadani kanal i grupa, enabled/visible/purchasable prozori i aktivni SKU. Nacrti, povučeni artikli i privatni mediji ne izlaze kroz ovu novu putanju. Rasprodani javni artikl može ostati u kolekciji s postojećom oznakom nedostupnosti. Kartice ponovno koriste postojeću batch prezentaciju cijene s porezom, rezervacija i zalihe; ne uvode paralelni izračun. Fotografije ostaju javne, lazy i responsive, uz dimenzije i isti fallback. Desktop koristi dva do četiri stupca, a uski prikaz jedan ili dva prema broju kartica. Srcset hintovi prate širine i ograničenje kontejnera.

Isti partial renderira kartice naslovnice i detalja. Posebna provjera uspoređuje stvarni HTML kartica uz izostavljenu jedinu očekivanu razliku, sizes. Obuhvaća rasprodani proizvod, uklonjenu cijenu i fotografiju na privatnom disku. GET ne stvara košaricu/narudžbu ili mijenja zalihu. Dohvat kandidata ima SQL limit četiri; više kandidata ne uvodi upit po kartici. To je ograničen izbor, ne prihvat proizvoljno velikog SKU kataloga.

## BreadcrumbList

JSON-LD opisuje samo postojeće dvije razine iste vidljive navigacije. URLs dolaze iz provjerenog konfiguriranog origina i stvarnih imenovanih ruta; ne preuzima Host ili tracking query. Neispravan origin izostavlja JSON-LD, dok vidljiva lokalna navigacija ostaje. Blade JSON encoding štiti script granice, a naziv se podudara s prikazanim proizvodom. Nema izmišljenih javnih kategorijskih ruta ni Product/Offer, recenzija ili merchant pravila.

To slijedi [Schema.org BreadcrumbList](https://schema.org/BreadcrumbList) i [Google upute za breadcrumb](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb). Preview ostaje noindex; ova dorada ne uključuje launch flag. Search Console, Rich Results Test i stvarni Google crawl nisu izvršeni iz privatne VM adrese. [Politika indeksiranja i odgovora](STOREFRONT_SEO.md).

## Provjere

PHP regresije pokrivaju limit/redoslijed, sve navedene scopeove i prazan odjeljak, privatne fotografije, cijene/stock dosljednost, query budget, Host/tracking, script-boundary escaping i izostanak markupa na drugim rutama. Negativna photo-conversion provjera sada gleda glavni image element; dodatne javne kartice legitimno imaju vlastiti srcset, a privatni conversion URL i dalje ne smije biti nigdje u odgovoru.

`npm run test:browser:discovery` je read-only proba inicijalizirane izolirane ponude s tri demo proizvoda. Na desktopu, 390/320 px i bez JS-a provjerava putanju, dva druga artikla, stvarno učitane fotografije, tipkovnički prijelaz na drugi proizvod, browser Back i povratak u kolekciju. JSON-LD odgovara vidljivoj putanji. Dugi nazivi od 255 znakova dodaju se samo u DOM probnog browsera, bez spremanja. Svi ne-GET/HEAD i vanjski zahtjevi su blokirani; nema novih narudžbi. Stvarni izvršni rezultat/commit prati [STATUS](STATUS.md).
