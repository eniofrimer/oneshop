        <section class="confirmation-details" aria-label="Spremljena testna narudžba">
            <h2>Spremljena testna narudžba</h2>
            <ul class="payment-lines">@foreach($order->lines as $line)
                <li class="payment-line"><div><strong>{{ $line->description }}</strong>
                    @if(is_string($line->option) && $line->option !== '')<p>{{ $line->option }}</p>@endif
                    <small>{{ $line->quantity }} kom
                        @if($line->type !== 'shipping' && filled($line->identifier)) · {{ $line->identifier }}@endif
                    </small>
                </div><span>{{ \App\Orders\OrderMoney::format($line, 'total') }}</span></li>
            @endforeach</ul>
            <dl class="payment-totals">
                <div><dt>Dostava (uključena u ukupno)</dt><dd>{{ \App\Orders\OrderMoney::format($order, 'shipping_total') }}</dd></div>
                <div><dt>Od toga porez</dt><dd>{{ \App\Orders\OrderMoney::format($order, 'tax_total') }}</dd></div>
                <div class="summary-total"><dt>Ukupno</dt><dd>{{ \App\Orders\OrderMoney::format($order, 'total') }}</dd></div>
            </dl>
        </section>
