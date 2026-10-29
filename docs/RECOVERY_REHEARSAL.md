# Izolirana provjera obnove

Manualni GitHub Actions workflow **Recovery rehearsal** provjerava obnovu izmišljenih podataka na PostgreSQL-u 18 i MariaDB-u 10.11. To je provjera aplikacijskog formata podataka, a ne instaliran backup sustav za produkcijskog trgovca.

## Što se izvodi

1. U zasebnoj Compose instanci inicijalizira se prazan razvojni katalog, javna fotografija s GD konverzijama, privatna datoteka i jedan testni checkout od 35 EUR. Poseban testni operator ima šifriranu MFA tajnu; sesija kupnje spremljena je šifrirano u bazu.
2. Izvozi se samo baza te instance: PostgreSQL `pg_dump` u custom formatu ili MariaDB `mariadb-dump` s transakcijskim snapshotom. Kopiraju se javne/privatne datoteke i pripadni testni `APP_KEY`. Nema web servera ni drugih pisaca tijekom snimke.
3. Izvoz se vraća u novu bazu `oneshop_restore`, a datoteke u zasebno stablo. Izvorna baza ostaje sačuvana; originalne datoteke premještaju se u drugi privatni testni direktorij kako pogrešan fallback ne bi prošao provjeru.
4. Aplikacija s obnovljenim ključem i storageom uspoređuje broj i hash redaka svake tablice te SHA-256 svih javnih/privatnih datoteka. Zatim stvarno čita šifriranu sesiju/MFA, preuzima privatnu datoteku kroz autorizirani servis i generira interni PDF povijesne narudžbe. Provjerava se i očuvani unique SKU indeks te nastavak automatskog ID slijeda.
5. Zaseban proces s drugim ključem mora odbiti dešifriranje. U artefakt se sprema samo kratak JSON rezultat, bez ključa, SQL izvoza, lozinki, sesijskih kolačića i sadržaja datoteka. Privremene snimke i izolirani volumeni brišu se na kraju posla.

Testni entry point `tests/Recovery/fixture.php` zahtijeva eksplicitnu CI zastavicu, lokalnu/testnu okolinu, očekivani Compose host i točno ime izvorne/odredišne baze. Nije dio produkcijskog paketa. Ne pokretati ga nad previewem ili stvarnom instalacijom; ne služi općem backupu ni pretvaranju podataka između SQL enginea.

## Što ostaje za stvarni hosting

Prije produkcije treba potvrditi konkretan postupak snimanja konzistentne baze i medija, odvojeno čuvanje potrebnih instalacijskih ključeva/konfiguracije, zaštitu i lokaciju kopija, retenciju, nadzor neuspjeha i obnovu na drugom stvarnom hostu. Ciljni RPO/RTO i održavanje tijekom snimke nisu odabrani ovim testom. Nijedan cron za produkcijski backup nije instaliran.

Za novu instalaciju ne generirati drugi `APP_KEY` pri povratu postojeće baze: šifrirani podaci ovise o pripadnom ključu. Promjena SQL lozinke također je zasebna operacija na poslužitelju; nije dovoljna izmjena `.env` datoteke. U produkciji nikada ne koristiti CI cleanup `down --volumes`.

Izvori za konkretne izvozne alate: [PostgreSQL SQL dump/restore](https://www.postgresql.org/docs/18/backup-dump.html), [MariaDB mariadb-dump](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump). Rezultat ove nove provjere bilježi se tek nakon stvarnog prolaza workflowa.

## Izvršeni rezultat

[Recovery rehearsal 40969791509](https://github.com/eniofrimer/oneshop/actions/runs/40969791509), commit `6d48fa6`, prošao je oba posla. Na svakom engineu uspoređene su **72 tablice, 236 redaka i osam datoteka**. Prošli su dešifriranje MFA-a i gostujuće sesije spremljenim ključem, autorizirano privatno preuzimanje, povijesni PDF, unique SKU indeks, automatski ID slijed te odbijanje drugog ključa. JSON rezultati su preuzeti i pregledani. Izvorni i obnovljeni podaci bili su isključivo sintetički; nije obnovljen ni promijenjen postojeći VM preview ili stvarna hosting instalacija.
