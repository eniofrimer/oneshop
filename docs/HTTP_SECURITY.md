# Zaštita prikaza u frameu

Dinamički aplikacijski odgovori dodaju `X-Frame-Options: SAMEORIGIN` i zasebnu `Content-Security-Policy: frame-ancestors 'self'` politiku. Izravno otvaranje URL-a i frame na istom originu ostaju dopušteni. Dokument iz drugog origina ne može ugraditi trgovinu ili administratorsku prijavu, ni preko posrednog framea na originu trgovine. Origin uključuje protokol, host i port; stari localhost i stalni VM URL nisu isti origin.

Postojeći aplikacijski CSP headeri ostaju zasebni i ne prepisuju se. Preglednik primjenjuje njihov presjek. Postojeći aplikacijski `X-Frame-Options: DENY` zadržava se, uz dodatni `frame-ancestors 'none'`, jer moderni CSP ima prednost pred XFO-om. Time se raniji DENY ne oslabljuje u modernom pregledniku. Ova politika ograničava pretke dokumenta; ne blokira učitavanje skripti, stilova ili budućeg provider iframea unutar vlastite stranice.

Politika je u Laravel middlewareu, pa je zajednička za Docker i native profil. Obuhvaća i login, redirect i aplikacijske 404/422 odgovore. Nginx statičke datoteke ne prolaze kroz taj middleware. Nema nove domene, iframe allowliste, aplikacijske testne rute, cookie promjene ili mrežne postavke. Stvarni panel/reverse proxy mora sačuvati headere; prihvat konkretnog hostinga ostaje zaseban korak.

`FramingPolicyTest` čuva javne/privatne/error odgovore, svaku postojeću CSP politiku i stroži DENY. `npm run test:browser:framing` provjerava izravni pristup, dopušteni isti origin te zabranu drugog origina, uključujući stranog pretka iznad domaćeg posrednika. Radi s JS-om i bez njega, u tri enginea CI matrice. Drugi origin je stvarni jednokratni Node HTTP listener vezan samo na loopback; dopuštena posredna HTML stranica odgovor je Playwright fixturea, nije produkcijska ruta. Svi aplikacijski zahtjevi osim GET/HEAD i svi nepoznati origini blokiraju se. Ne koristi račun/lozinku i ne predaje narudžbe. Izlaz sadrži samo broj provjera i ishode, bez screenshotova login podataka.

Dokazi se objavljuju u [STATUS-u](STATUS.md) nakon završene provjere; ovo opisuje implementaciju, ne zamjenjuje izvršni prihvat.

Primarni izvori: [MDN frame-ancestors](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors), [više CSP politika](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy#multiple_content_security_policies) i [X-Frame-Options](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Frame-Options).
