@extends('storefront.layout')
@section('title', 'Adresa i dostava — OneShop')
@section('content')
    <a class="back-link" href="{{ route('store.cart') }}">← Natrag na košaricu</a>
    <div class="checkout-steps"><strong>01 Adresa</strong><span>02 Pregled</span><span>03 Testno plaćanje</span></div>
    <div class="page-heading"><h1>Kamo šaljemo<span>?</span></h1><p>Jedna adresa za račun i dostavu u ovom razvojnom checkoutu.</p></div>
    <div class="checkout-layout"><form class="checkout-form" method="post" action="{{ route('store.checkout.address') }}">@csrf
        <h2>Tvoji podaci</h2>
        <div class="form-row"><div><label for="first_name">Ime</label><input name="first_name" id="first_name" value="{{ old('first_name', $address['first_name'] ?? '') }}" autocomplete="given-name" maxlength="100" required></div><div><label for="last_name">Prezime</label><input name="last_name" id="last_name" value="{{ old('last_name', $address['last_name'] ?? '') }}" autocomplete="family-name" maxlength="100" required></div></div>
        <div class="form-row"><div><label for="contact_email">E-pošta</label><input type="email" name="contact_email" id="contact_email" value="{{ old('contact_email', $address['contact_email'] ?? '') }}" autocomplete="email" maxlength="200" required></div><div><label for="contact_phone">Telefon</label><input type="tel" name="contact_phone" id="contact_phone" value="{{ old('contact_phone', $address['contact_phone'] ?? '') }}" autocomplete="tel" maxlength="40" required></div></div>
        <h2>Adresa za račun i dostavu</h2>
        <div><label for="line_one">Ulica i kućni broj</label><input name="line_one" id="line_one" value="{{ old('line_one', $address['line_one'] ?? '') }}" autocomplete="address-line1" maxlength="200" required></div>
        <div><label for="line_two">Stan, kat ili dodatak adresi <span>(neobavezno)</span></label><input name="line_two" id="line_two" value="{{ old('line_two', $address['line_two'] ?? '') }}" autocomplete="address-line2" maxlength="200"></div>
        <div class="form-row"><div><label for="postcode">Poštanski broj</label><input name="postcode" id="postcode" value="{{ old('postcode', $address['postcode'] ?? '') }}" autocomplete="postal-code" maxlength="20" required></div><div><label for="city">Grad</label><input name="city" id="city" value="{{ old('city', $address['city'] ?? '') }}" autocomplete="address-level2" maxlength="100" required></div></div>
        <div><label for="country_id">Država</label><select name="country_id" id="country_id" autocomplete="country" required>@foreach($countries as $country)<option value="{{ $country->id }}" @selected((string)old('country_id', $address['country_id'] ?? $countries->firstWhere('iso2', 'HR')?->id) === (string)$country->id)>{{ $country->iso2 === 'HR' ? 'Hrvatska' : $country->name }}</option>@endforeach</select></div>
        <button class="button">Pregledaj narudžbu <span aria-hidden="true">↗</span></button>
    </form><aside class="order-summary"><p class="eyebrow">RAZVOJNA KUPNJA</p><h2>Provjeri cijeli put.</h2><p>U sljedećem koraku vidiš dostavu, porez i konačan iznos. Tek zatim predaješ testnu narudžbu.</p><p>Ova instanca ne naplaćuje novac i ne šalje stvarne pakete. Koristi izmišljene podatke za probnu kupnju.</p></aside></div>
@endsection
