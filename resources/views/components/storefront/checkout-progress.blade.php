@props(['step' => 1, 'payment' => 'Testno plaćanje'])
<ol class="checkout-progress" aria-label="Koraci kupnje">
    @foreach([1 => 'Adresa', 2 => 'Pregled', 3 => $payment] as $number => $label)
        <li class="{{ $number < $step ? 'is-complete' : ($number === $step ? 'is-current' : '') }}" @if($number === $step) aria-current="step" @endif>
            <span class="step-number" aria-hidden="true">{{ $number < $step ? '✓' : '0'.$number }}</span><span>{{ $label }}</span>
        </li>
    @endforeach
</ol>
