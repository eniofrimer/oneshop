# Čisti native prihvat — 31. 10. 2026.

Ovaj zapis pripada reviziji `95a9dc895c501a548d60a908696f41aaff8483ee`. Novije lokalne zakrpe i aktualni preview uvijek su odvojeno označeni u [STATUS-u](STATUS.md). Prihvat koristi vlastitu potrošnu VM instalaciju i fizički odvojeni MariaDB daemon; ne koristi korisnikove preview narudžbe ili račun.

## Podrijetlo i integritet

| Provjera | Rezultat |
| --- | --- |
| Izvor | [CI 41159408466](https://github.com/eniofrimer/oneshop/actions/runs/41159408466), native artifact `13026826462` |
| GitHub ZIP digest | `386e68e37abeaa8ff956b8902600eaab46a56afe22f787e7ba0b67d96c2975d8` |
| Arhiv | `oneshop-95a9dc895c50.tar.gz`, 23.275.220 bajtova |
| Tar SHA-256 | `7e567547ce7fc43e2252135391d4f3bb9227746c23e4ed748589a687e7d64a98` |
| Izvor i lockovi | Puni revision u `release.json`, oba lock hasha i 303 isporučene Git datoteke odgovaraju nepromjenjivom Git objektu. |
| Isključeno | Instalacijski `.env`, testovi, Node modules, Horizon i Faker nisu u paketu. |
| Nakon prihvata | Svih 21.033 originalnih regularnih datoteka / 97.866.128 bajtova, uključujući vendor i source, ostalo je identično arhivu. |

Provjerene su GitHub SHA vrijednosti, ZIP CRC, tar `.sha256`, metadata i pojedinačni sadržaji. Nije se uspoređivao samo trenutačni promjenjivi checkout. Nova instalacijska konfiguracija i generirani storage/cache nisu originalne datoteke arhiva.

## Stvarni runtime i kupnje

PHP 8.5.12 ima `pdo_mysql`, bez Redis/PDO-PG ekstenzija, Horizon ili Faker paketa. Odabrani su database cache, šifrirane database sessions i database queue. Oba CLI profila `cpanel` i `fastpanel`, stvarni odvojeni worker i queue/shared-cache probe prolaze.

- Chromium, Firefox i Linux WebKit svaki prolaze desktop, uski prikaz i kupnju bez JavaScripta: devet testnih kupnji.
- Sva tri prolaze neovisno mijenjanje varijanti u košarici i navigaciju tipkovnicom; ti tokovi ne predaju narudžbu, a keyboard proba dodatno blokira završne upisne zahtjeve.
- Chromium prolazi tri sesijske povijesti: čekanje → odbijanje → ponovni pokušaj → potvrda → nova košarica. Tuđa sesija dobiva 404 za potvrdu.
- Admin kroz vidljive kontrole stvara vlastiti varijabilni proizvod, dva SKU-a i dvije fotografije; mijenja naslovnicu i SKU fotografije, kupuje kao zaseban gost, odbija uređivanje rezervirane/zastarjele zalihe te preuzima PDF. Zalihe prije/poslije iste kupnje su 10 / 1 / 9 i 9 / 0 / 9.
- Privatni upload/download/arhiva/restore, ciklus kategorija i zastarjela dostavna tarifa prolaze u zasebnim admin tokovima.
- Običan administrator prolazi deset MFA faza na 1280/390 px, uključujući upis, TOTP/replay, recovery jednim klikom uz latenciju, raniji pogrešni OTP i ponovno korištenje koda. Izvorni rate limit i cooldown ostaju uključeni. QR, ključevi i kodovi nisu spremljeni u dokaze.

Ukupno je 109 axe provjera bez prijavljenih kršenja. Preostalih 48 automatski neodlučenih rezultata čine 26 `color-contrast`, šest `aria-prohibited-attr` i 16 `aria-valid-attr-value`; to nije potpuna WCAG potvrda niti fizička mobilna/čitačka proba. Imenovanje javne galerije i proizvodi bez slike dodatno se rješavaju u kasnijem inkrementu.

## Cached production i stvarni cron posao

Na istoj čistoj instalaciji privremeno su cacheani production config/routes/views, uz sintetički HTTPS URL, secure cookie postavku i namjerno uključen demo flag. Simulator, sesijska demo povijest i Stripe sandbox svejedno su isključeni. Stvarni loopback HTTP daje 200 za naslovnicu/health/login, preusmjerava zaštićene admin ekrane na isti login i vraća 404 za stari login, Horizon i checkout putove. Ovo ne dokazuje HTTPS transport.

Za cron prihvat zaustavljen je samo vlastiti QA worker. Postojeći autorizirani editori dodaju novu sintetičku fotografiju u nacrt. Konverzije zaista ostaju u database queueu, a prikaz dotad koristi javni original. Stvarni `schedule:run` izvršava zasebni worker i proizvodi WebP datoteke:

| Konverzija | Dimenzije | Bajtovi |
| --- | --- | --- |
| small | 300 × 300 | 6.458 |
| medium | 500 × 500 | 8.134 |
| large | 800 × 800 | 16.600 |
| zoom | 500 × 500 | 8.134 |

Provjereni su stvarni GD sadržaj/dimenzije, disk/putanja, generirane oznake, responsive srcset i HTTP WebP odgovor za svaku datoteku. Queue i failed-jobs ostaju prazni. Zasebni queue/shared-cache probe također prolazi kroz isti scheduler. Originalni env vraćen je byte-for-byte, cache očišćen i vlastiti worker ponovno pokrenut.

Dugi PDF iz sintetičke rollback transakcije ima 12 stavki, četiri A4 stranice i 882.466 bajtova. Poppler nalazi nula okvira izvan stranice, svih 3.095 Č i 1.281 Ž, konačnih 287 EUR i napomenu da nije račun. Izvorni QA redci zadržani su nakon rollbacka.

## Objava i granice

Nakon svih šest zelenih workflowa isti izvor objavljen je na main/preview uz privatnu snimku i usporedbu svih tablica. Sačuvan je 921 redak / 77 tablica, env i stalni URL; tri frontend datoteke preuzete su iz upravo testiranog arhiva. Snimka ima provjerene dozvole, veličine, SHA i gzip, ali nije vraćena niti kopirana offsite.

Poslije ovog prihvata QA je izričito preimenovan u zakrpanu okolinu za probu 500 sintetičkih proizvoda. Ta kasnija proba nije nova čista instalacija ovog arhiva. Privremene lozinke i SQL ovlasti pripadaju samo potrošnom QA-u, ne korisnikovu računu.

Docker je izvršno provjeren u CI-u. Stvarni cPanel/FastPanel račun, web PHP/FPM, domena/TLS, panel cron, mail, offsite kopije i RPO/RTO i dalje traže prihvat ciljnog hosta. Stvarna naplata, porezni identitet trgovca, računi i slanje paketa nisu potvrđeni ovim tehničkim dokazom. [Postupak deploya](DEPLOYMENT.md), [ručna proba](NIGHT_DEMO_20261030.md).
