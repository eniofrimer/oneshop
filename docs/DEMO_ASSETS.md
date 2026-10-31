# Ogledne fotografije OneShopa

Datum: 30.–31. 10. 2026. Generirano ugrađenim alatom `image_gen` uz skill `imagegen`. Ovo su AI ilustracije demo proizvoda, ne fotografije stvarnog trgovčeva asortimana. Postojeće korisničke fotografije i izvorne SVG ilustracije nisu zamijenjene. Nove datoteke služe kao fallback samo označenim demo proizvodima; uploadana galerija zadržava prednost.

Isporučene datoteke su u `public/images/demo-v2/`: `cup`, `bag`, `shirt`, `shirt-sand` i `hero`, u WebP formatima širine 480, 800 i 1200 px. Izvorne generirane slike ostaju u Codexovoj mapi generiranih slika; aplikacija koristi samo verzionirane datoteke iz projekta. WebP kodiranje i smanjenje izvedeni su GD-om, bez promjene kompozicije.

## Konačni promptovi

### cup

Use case: product-mockup. Asset type: square ecommerce catalogue photograph for the OneShop demonstration shop. Primary request: one simple warm caramel ceramic coffee mug with a large round handle, empty, standing on an ivory studio sweep. Photorealistic matte glaze with tiny natural texture, believable proportions, complete object visible with generous margins, three-quarter front view. Soft daylight from upper left, subtle contact shadow, warm quiet editorial product photography. No saucer, props, text, logo or watermark. The product fills roughly 65 percent of the square frame. This is an illustrative demo product, not an existing branded product.

### bag

Use case: product-mockup. Asset type: square ecommerce catalogue photograph for the OneShop demonstration shop. Primary request: one natural beige canvas tote bag with two long cotton handles, standing upright on an ivory studio sweep. Photorealistic fabric weave, seams and softly structured natural folds, front three-quarter view, entire bag and handles visible with generous margins, understated premium everyday product photography. Soft daylight from upper left, subtle contact shadow, warm neutral cream palette matching a caramel ceramic mug catalogue. No printed words, logo, contents, props or watermark. Product fills roughly 70 percent of square.

### shirt

Use case: product-mockup. Asset type: square ecommerce catalogue photograph for the OneShop demonstration shop. Primary request: one plain dark forest-green short-sleeve cotton crew-neck T-shirt, neatly laid flat against a warm ivory background, top-down photograph. Complete silhouette, balanced sleeves, softly realistic fabric folds, visible cotton weave and carefully stitched collar, product centered with generous margins. Quiet premium studio catalogue photography, soft daylight from upper left, delicate shadow. No person, hanger, labels, printed words, logos, decorative props or watermark. Product fills roughly 75 percent of square.

### hero

Use case: product-mockup. Asset type: square editorial hero photograph for the OneShop demonstration storefront, copy will be outside the photograph. Primary request: a quiet still life of a natural beige canvas tote bag with two long handles and a warm caramel ceramic coffee mug with a round handle, arranged together on a light warm ivory tabletop against a pale sage-grey wall. Photorealistic tactile cotton weave and matte ceramic glaze, soft sunlight from the upper left and long gentle shadows, subtle paper-like plaster background, simple premium everyday essentials mood. Full products visible, tote in the back and mug in the foreground, composition centered in the lower two-thirds with calm breathing room above. No people, extra objects, printed words, logos or watermark.

### shirt-sand — uređivanje postojeće majice

Use case: precise-object-edit. The referenced square flat-lay t-shirt photograph is the edit target. Create the sand-beige colour variant of this exact generic cotton t-shirt for the OneShop demo catalogue. Change only the fabric colour from dark olive green to a warm natural sand beige. Preserve the exact shirt silhouette, collar, sleeve proportions, stitching, realistic cotton texture, small folds, framing, camera viewpoint, soft light, contact shadows, and opaque warm off-white background. Maintain a square catalogue photograph with the entire shirt visible. The result must look like the same shirt photographed in another colour, not a recoloured flat illustration. No text, logos, labels, hang tags, people, props or additional objects. Keep the background opaque; do not introduce transparency.

Ulaz za ugrađeni alat bio je postojeći `shirt-1200.webp`; fallback CLI/API nije korišten. Izlaz je neprozirni PNG 1254 × 1254, SHA-256 `996a7d14ec7b1ae2dd3bff4f1d434cc147e050bb79e88b3d097e6a077c503497`, zatim GD/WebP smanjenja uz kvalitetu 84. Stari asseti nisu prepisani.

| Datoteka | Bajtovi |
| --- | ---: |
| `public/images/demo-v2/shirt-sand-480.webp` | 13.444 |
| `public/images/demo-v2/shirt-sand-800.webp` | 29.990 |
| `public/images/demo-v2/shirt-sand-1200.webp` | 50.994 |

Cijeli set sada ima **15 WebP datoteka / 707.290 bajtova**. Označena demo majica bez vlastite galerije prikazuje obje boje; `DEMO-SHIRT-2-1` i `DEMO-SHIRT-2-2` koriste pješčanu varijantu. Vlastiti javni upload isključuje ovu demo dopunu i ima prednost; stvarne SKU fotografije bira administrator. To nije automatsko stvaranje fotografija za trgovčeve proizvode.
