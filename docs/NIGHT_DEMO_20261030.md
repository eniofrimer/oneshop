# Ručna proba OneShopa

Razvojna trgovina: [http://192.168.250.2:8080/](http://192.168.250.2:8080/). Admin: [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login). Vlasnički račun je `admin@oneshop.local`, s istom zasebno predanom lozinkom, svim ovlastima i bez obaveznog 2FA. Stalna adresa je provjerena iz VM-a; pristup iz Windowsa nije neovisno potvrđen.

## Prva narudžba

1. Otvori majicu, izaberi boju i veličinu te jedan komad. Dodaj i šalicu ako želiš provjeriti više stavki. Fotografije služe kao ogledni materijal; vlastite prenesene slike imaju prednost.
2. U košarici provjeri kombinaciju, količinu i iznose. Promijeni količinu samo jedne stavke; broj u zaglavlju prati ukupan broj komada.
3. Na checkoutu unesi izmišljeno ime, e-poštu i HR adresu. Pregledaj odabranu dostavu, porez i konačan iznos. Povrat na adresu čuva unose. Razvojna tarifa i porezi nisu potvrđena pravila stvarnog trgovca.
4. Potvrdi da predaješ test i nastavi na simulator. Najprije možeš izabrati odbijanje, pa ponoviti pokušaj i izabrati uspjeh. Kartični podaci nisu potrebni i nema stvarnog novca.
5. Zabilježi broj narudžbe. Otvori **Moje narudžbe**, zatim dodaj novi proizvod u novu košaricu i provjeri da se ranija potvrda još može otvoriti. Povijest pripada ovoj sesiji preglednika; nije korisnički račun.
6. U adminu otvori posljednju narudžbu s nadzorne ploče ili je pronađi u **Narudžbe**. Usporedi artikle i iznos s potvrdom, zatim preuzmi PDF sažetak. PDF je interni testni pregled, nije račun.
7. U **Plaćanja** provjeri testne pokušaje i poveznicu natrag na narudžbu. U **Katalog → Zalihe** potraži kupljeni SKU i osvježi stranicu: uspjeh jednom potroši fizičku zalihu, a rezervirana količina padne na nulu. Iste količine vidiš i na tabu SKU-ova proizvoda.

## Druga proba

Predaj novu narudžbu i otkaži je na simulatoru. Raspoloživa zaliha vraća se, fizička se ne umanjuje. Ako prozor samo zatvoriš, neriješena testna rezervacija ostaje do razvojnog roka; projektni scheduler obrađuje napuštene testne narudžbe. Postojeće podatke ne treba ponovno seedati ili resetirati.

Za vlastiti proizvod slijedi [administratorske upute](ADMIN_GUIDE.md): nacrt → SKU/cijena/zaliha → fotografije → provjera → izričita objava. Za varijabilan proizvod prvo pripremi opcije i vrijednosti. Ako stari editor odbije spremiti zalihu nakon kupnje, ponovno otvori zapis i pregledaj novije stanje.

## Granice ove probe

Ovo je funkcionalni razvojni ogledni primjerak s testnim checkoutom. Stvarna Stripe merchant proba čeka privatnu konfiguraciju; javni Stripe tok ostaje isključen. PayPal, virman, računi, stvarne pošiljke, korisnički računi i plaćena prava preuzimanja nisu potvrđeni ovom probom. Docker i native paket provjeravaju se u CI-u; stvarni cPanel/FastPanel račun, HTTPS, mail i cron traže zaseban hosting prihvat.

Aktualna revizija, provjere i otvorene odluke: [STATUS.md](STATUS.md). Puni opseg slikovnih materijala i promptovi: [DEMO_ASSETS.md](DEMO_ASSETS.md).
