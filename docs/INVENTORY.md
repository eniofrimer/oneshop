# Rezervacije i raspoloživa zaliha

Rezervacije su povezane s razvojnim checkoutom, javnim katalogom/košaricom i administratorskim izmjenama. Predaja drži količinu; potvrda simulatora jednom umanjuje fizičku zalihu. Temelj još nije povezan s vanjskim procesorom. Stvarna naplata i njeno usklađivanje ostaju zaseban korak iz [pregleda naplate](PAYMENT_REVIEW.md).

## Količine i stanja

Zaliha je fizička količina na stanju. Rezervirano je zbroj redaka held, a raspoloživo je zaliha umanjena za ta držanja, najmanje nula. SKU s izričitim načinom Bez ograničenja zalihom ne dobiva rezervaciju i ne umanjuje fizičku zalihu.

Primjer: stanje 20, narudžba za dva komada → fizički 20, rezervirano 2, raspoloživo 18. Uspjeh → fizički 18, rezervirano 0. Otkazivanje → fizički 20, rezervirano 0. Greška zapisa plaćanja vraća cijelu transakciju, uključujući rezervaciju i fizičku zalihu.

Kartice, izbor varijante, filter dostupnosti, košarica, pregled i predaja koriste isti izračun. Košarica sama ne rezervira. Predaja ponovno provjerava sve količine pod zaključavanjem.

| Prijelaz | Posljedica |
| --- | --- |
| Nova rezervacija → held | Količina postaje neraspoloživa ostalim narudžbama; fizička se ne mijenja. |
| held → consumed | Fizička se umanjuje jednom; ponovljeni consume istog sadržaja ne umanjuje ponovno. |
| held → released | Količina opet postaje raspoloživa; fizička se ne mijenja. |
| Ponovljeni release | Nema dodatnog oslobađanja. |
| released → consumed ili consumed → released | Servis odbija prijelaz. Povrat novca i povrat robe zahtijevaju zasebne radnje. |

Svaki redak veže nepredvidivi reservation_key uz narudžbu, SKU i nepromjenjivu pozitivnu količinu. Jedinstven ključ sprečava dva retka za isti ključ/SKU. Ključ se ne može ponoviti s drugim sadržajem ili narudžbom. Drugi ključ iste narudžbe odbija se dok postoji aktivna ili već potrošena rezervacija. Sadržaj mora odgovarati spremljenim praćenim stavkama. Povijest ostaje sačuvana.

Sam posjed ključa ne daje javnu operaciju. Razvojni controller i dalje zahtijeva vlastitu sesiju i CSRF; nema javnog API-ja za proizvoljno rezerviranje, oslobađanje ili potrošnju.

## Zaključavanja i administracija

Servis zaključava narudžbu, zatim sve SKU-ove po ID-u i retke rezervacije. Predaja prije toga zaključava svoju košaricu i katalog u zajedničkom redoslijedu. Otkazivanje, potvrda i razvojni istek prvo zaključavaju isti checkout. SQL sesije koriste READ COMMITTED.

Uređivač pod istim SKU zaključavanjem odbija zalihu manju od rezervirane količine, promjenu praćenja zalihe/fizičke isporuke te arhiviranje dok postoje aktivna držanja. Povećanje zalihe je dopušteno. Postojeća provjera zastarjelog obrasca ostaje uključena.

SKU popis prikazuje rezervirano i raspoloživo uz ukupnu zalihu; dashboard računa SKU-ove bez raspoložive količine. Promjena cijene nakon predaje ne mijenja povijesnu narudžbu.

## Postojeće narudžbe i migracija

Migracija dodaje uses_stock_reservations=false postojećim razvojnim checkoutima. Ključevi, iznosi, statusi i rokovi nisu preračunati niti retroaktivno rezervirani. Nove predaje izričito spremaju true.

Stari pending simulator pod istim zaključavanjima može potrošiti samo raspoloživu količinu. Ne može potrošiti novu rezervaciju druge narudžbe. Ako robe nema, ostaje pending s payment greškom i bez uspješne transakcije. Nova rezervirana narudžba ne može koristiti stari put umanjenja. Stara meta oznaka oneshop_demo_track_stock ostaje podržana, a nove snimke koriste oneshop_track_stock.

Migracija ne briše podatke. Down odbija uklanjanje sheme ako postoji povijest rezervacija; rollback zahtijeva izričito razrješenje/obnovu. Potpuni testni reset ostaje dopušten samo na fizički izoliranoj potrošnoj instanci.

## Razvojni istek

Postojećih 30 minuta ostaje razvojno pravilo. Odbijeni pokušaj drži rezervaciju za ponovni pokušaj do tog roka. Otkazivanje oslobađa odmah; potvrda isteklog simulatora oslobađa i ne provodi naplatu.

php artisan oneshop:expire-demo-checkouts obrađuje najviše 100 isteklih checkouta po pozivu; --limit prihvaća 1–1000. Scheduler ga poziva svake minute uz zaštitu preklapanja. Potreban je schedule:run cron/scheduler. Production ili isključen simulator ne obrađuju ništa.

Naredba obrađuje samo pending/declined razvojne checkoute i njihove ključeve. Ne oslobađa proizvoljne stare rezervacije. Budući PSP timeout ili lokalni rok ne dokazuju odbijenu naplatu: nepoznati ishod mora zadržati količinu do usklađivanja. Produkcijski rokovi i uvjeti isteka još nisu odabrani.

## Izvršne provjere

Lokalno prolazi **211 testova / 2.252 assertions**, uz Pint i Larastan, na fizički odvojenom MariaDB daemonu bez mreže. Preview baza nije korištena za reset ili testne kupnje.

Zasebni PHP procesi prolaze devet scenarija: dvostruku predaju, dvostruku potvrdu, rezervaciju zadnjeg komada, otkazivanje nasuprot potvrdi, obrnuti redoslijed SKU-ova, istek nasuprot potvrdi, legacy zadnji komad te promijenjenu cijenu/opciju nakon čekanja.

Recovery provjera proširena je na held/consumed/released povijest, šifriranu sesiju i nastavak kupnje. Nakon usporedbe tablica/datoteka potvrđuje staru rezervaciju jednom, stvara novu s većim ID-em i oslobađa je jednom. Novi CI rezultati tek slijede; opis scenarija sam nije dokaz izvršenja.

Ovo ne potvrđuje Stripe/PayPal webhookove, stvarnu naplatu/povrate, vanjske sinkronizacije zalihe ili porezne/računovodstvene odluke.
