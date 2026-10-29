@extends('storefront.layout')
@section('title', 'Adresa i dostava — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.cart') }}">← Natrag na košaricu</a>
    <div class="checkout-steps"><strong>01 Adresa</strong><span>02 Pregled</span><span>03 Testno plaćanje</span></div>
    <div class="page-heading"><h1>Kamo šaljemo<span>?</span></h1><p>Jedna adresa za račun i dostavu u ovom razvojnom checkoutu.</p></div>
    <div class="checkout-layout"><form class="checkout-form" method="post" action="{{ route('store.checkout.address') }}">@csrf
        <h2>Tvoji podaci</h2>
        <div class="form-row">
            <x-storefront.input name="first_name" label="Ime" :value="$address['first_name'] ?? ''" autocomplete="given-name" maxlength="100" required />
            <x-storefront.input name="last_name" label="Prezime" :value="$address['last_name'] ?? ''" autocomplete="family-name" maxlength="100" required />
        </div>
        <div class="form-row">
            <x-storefront.input name="contact_email" label="E-pošta" type="email" :value="$address['contact_email'] ?? ''" autocomplete="email" maxlength="200" required />
            <x-storefront.input name="contact_phone" label="Telefon" type="tel" :value="$address['contact_phone'] ?? ''" autocomplete="tel" maxlength="40" required />
        </div>
        <h2>Adresa za račun i dostavu</h2>
        <x-storefront.input name="line_one" label="Ulica i kućni broj" :value="$address['line_one'] ?? ''" autocomplete="address-line1" maxlength="200" required />
        <x-storefront.input name="line_two" label="Stan, kat ili dodatak adresi" hint="(neobavezno)" :value="$address['line_two'] ?? ''" autocomplete="address-line2" maxlength="200" />
        <div class="form-row">
            <x-storefront.input name="postcode" label="Poštanski broj" :value="$address['postcode'] ?? ''" autocomplete="postal-code" maxlength="20" required />
            <x-storefront.input name="city" label="Grad" :value="$address['city'] ?? ''" autocomplete="address-level2" maxlength="100" required />
        </div>
        <div><label for="country_id">Država</label><select name="country_id" id="country_id" autocomplete="country" required @if($errors->has('country_id')) aria-invalid="true" aria-describedby="country_id-error" @endif>@foreach($countries as $country)<option value="{{ $country->id }}" @selected(\App\Storefront\FormValues::old('country_id', $address['country_id'] ?? $countries->firstWhere('iso2', 'HR')?->id) === (string)$country->id)>{{ $country->iso2 === 'HR' ? 'Hrvatska' : $country->name }}</option>@endforeach</select><x-storefront.error name="country_id" /></div>
        <button class="button">Pregledaj narudžbu <span aria-hidden="true">↗</span></button>
    </form><aside class="order-summary"><p class="eyebrow">RAZVOJNA KUPNJA</p><h2>Provjeri cijeli put.</h2><p>U sljedećem koraku vidiš dostavu, porez i konačan iznos. Tek zatim predaješ testnu narudžbu.</p><p>Ova instanca ne naplaćuje novac i ne šalje stvarne pakete. Koristi izmišljene podatke za probnu kupnju.</p></aside></div>
@endsection
