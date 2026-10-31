# SEO i privatnost odgovora trgovine

Indeksiranje je zadano isključeno. Preview zadržava isti URL, podatke i testni checkout. Ova dorada priprema HTML i sitemap za buduću javnu trgovinu; ne uključuje stvarnu naplatu ili potvrdu poslovnih podataka.

## Oznake javnog kataloga

Naslovnica i proizvod dobivaju canonical, opis i Open Graph naslov/opis/URL te postojeću javnu fotografiju kad postoji. Opis proizvoda dolazi iz spremljenog teksta, bez HTML tagova, skraćen na 180 Unicode znakova. Blade escapea vrijednosti u atributima. Nema izmišljenih recenzija, ocjena, poslovnih pravila, dostupnosti prijevoznika ili komercijalnog Product/Offer markupa.

Canonical koristi konfigurirani `APP_URL`, a ne HTTP Host ili query korisnika. Podržan je HTTP(S) origin s opcionalnim portom i završnim `/`, bez userinfo, queryja, fragmenta ili poddirektorija. Instalacija se izvodi na vlastitoj domeni s document rootom u `public`; ne mijenjati postojeću domenu radi ove dorade. Neispravan origin izostavlja canonical i zadržava noindex. Lokalno generirana slika za OG također koristi konfigurirani origin; zasebno konfigurirana javna CDN fotografija ostaje svoja.

Stranica kolekcije 2 ima vlastiti `?page=2` canonical; prva koristi `/`, bez `?page=1`. Interna pretraga, filtar dostupnosti i alternativno sortiranje nemaju canonical/OG URL koji bi objavio upit ili tvrdio da su isti sadržaj kao nefiltrirana stranica. Oni i prazne stranice ostaju `noindex`. JSON rezultati nisu HTML stranice za indeksiranje. URL proizvoda zadržava svoj canonical bez tracking queryja. Pohranjene cijene, porezi, rezervacije i upiti kartica nisu mijenjani.

## Izričita javna objava

`ONESHOP_ALLOW_INDEXING=false` ostaje zadana vrijednost, uključujući `.env.example`. Indeksiranje javnog nefiltriranog sadržaja moguće je tek kada vrijede sva četiri uvjeta:

1. `APP_ENV=production`.
2. `APP_DEBUG=false`.
3. `ONESHOP_ALLOW_INDEXING=true`.
4. Valjan `APP_URL` s `https://` originom.

Te uvjete treba zasebno prihvatiti na ciljnom hostu: domena/TLS, stvarni sadržaj i trgovac te spremnost naručivanja. Ova dorada ih ne uključuje na VM previewu. Pri promjeni env-a primjenjuje se postojeći postupak obnove config cachea; nije potrebno generirati novi ključ, seedati ili resetirati bazu.

## Sitemap bez crawlera i sesije

- `/robots.txt` dopušta čitanje stranica kako bi crawler mogao vidjeti noindex. Dodaje `Sitemap:` samo nakon izričite javne objave. Ne koristi se kao autorizacija.
- `/sitemap.xml` je indeks.
- `/sitemap-pages.xml` sadrži početnu stranicu.
- `/sitemap-products-1.xml`, `-2.xml` itd. sadrže najviše 1.000 proizvoda po odgovoru, sa stabilnim ID redoslijedom.

Sve sitemap datoteke su u korijenu domene. Generiranje koristi već instalirani Spatie sitemap renderer, bez HTTP crawlera ili vanjskih zahtjeva. Čita isti `StoreCatalog::products()` scope za objavu, kanal, grupu, vremenske prozore, arhivu i aktivni SKU. Rasprodan proizvod ostaje objavljen, pa njegov URL ne nestaje samo zbog zalihe. Ne učitavaju se cijene, galerije, privatni uploadi, adrese ili narudžbe. Nema izmišljenog `lastmod`, `changefreq` ili prioriteta. Maksimum indeksa je 49.999 produktnih stranica i jedna statična; prekoračenje ne reže katalog potiho.

Dok indeksiranje nije omogućeno, sitemap rute daju 404 bez kataloškog SQL-a. GET/HEAD crawler rute nemaju session/CSRF middleware i ne stvaraju cookieje; ta iznimka ne obuhvaća nijednu mutacijsku rutu. XML pozivi imaju vlastiti rate limit, 60/min po izvornoj IP adresi. Ovo je dinamički, bounded sitemap; ne jamči nepromjenjiv snapshot tijekom istodobne masovne objave/povlačenja. Search Console, vanjski Rich Results Test i stvarni Google crawl nisu izvršeni iz privatne VM adrese.

## Privatnost odgovora

Projektni HTTP odgovori dobivaju `Cache-Control: private, no-store`, zadani `Referrer-Policy: same-origin` i `X-Content-Type-Options: nosniff`. Postojeći stroži `no-referrer` na Stripe odgovorima ostaje sačuvan. Čak i javni storefront HTML sadrži broj artikala trenutne sesije i nije prikladan za dijeljeni full-page cache. Privatni checkout/admin, redirecti, JSON i greške imaju `X-Robots-Tag: noindex,nofollow`. Samo uspješni javni HTML može dobiti drugačiji robots signal nakon gore navedenog opt-ina. Statične datoteke koje izravno poslužuje web server ne prolaze taj Laravel middleware.

Noindex i no-store ne zamjenjuju postojeću autentikaciju, MFA, session capability provjere ili TLS. Financijski tokovi, serverski iznosi, quote i idempotency zaštite nisu mijenjani.

## Provjera i izvori

HTTP regresije pokrivaju launch guardove, otrovani Host, escaping i dugi opis, canonical paginacije, privatne/JSON/error odgovore, cookie-free crawler rute, objavu/povlačenje te razdvajanje 1.001 proizvoda bez duplikata i bez N+1 cijena. Aktualni izvršni rezultat prati [STATUS](STATUS.md).

Primarni izvori: [Google — URL i canonical u webshopu](https://developers.google.com/search/docs/specialty/ecommerce/designing-a-url-structure-for-ecommerce-sites), [Google — noindex](https://developers.google.com/search/docs/crawling-indexing/block-indexing), [sitemaps.org protokol](https://www.sitemaps.org/protocol.html) i [Open Graph protokol](https://ogp.me/). Structured Product/Offer i merchant pravila nisu automatski dodani: [Google ograničava AggregateOffer za varijante](https://developers.google.com/search/docs/appearance/structured-data/product-snippet).
