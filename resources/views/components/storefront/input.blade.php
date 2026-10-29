@props(['name', 'label', 'value' => '', 'type' => 'text', 'hint' => null])
<div>
    <label for="{{ $name }}">{{ $label }}@if($hint) <span>{{ $hint }}</span>@endif</label>
    <input {{ $attributes->merge(['type' => $type, 'name' => $name, 'id' => $name, 'value' => \App\Storefront\FormValues::old($name, $value)]) }}
        @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
    <x-storefront.error :name="$name" />
</div>
