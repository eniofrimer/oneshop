@extends('errors.layout')
@section('code', '403')
@section('title', 'Pristup nije dopušten.')
@section('message', 'Za ovu stranicu nemate potrebne ovlasti. Ako trebate administraciju, prijavite se svojim računom.')
@section('secondary')<a class="secondary" href="/admin/login">Prijava u administraciju</a>@endsection
