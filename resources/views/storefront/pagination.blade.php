@if($paginator->hasPages())
    <nav class="store-pagination" aria-label="Navigacija stranicama">
        <p class="page-summary">
            @if($paginator->firstItem())
                Prikazuje se {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} od {{ $paginator->total() }} proizvoda.
            @else
                Na ovoj stranici nema proizvoda.
            @endif
        </p>
        <div class="page-controls">
            @if($paginator->onFirstPage())<span class="page-step page-unavailable" aria-disabled="true">← Prethodna</span>
            @else<a class="page-step" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Prethodna</a>@endif
            <ol class="page-numbers">
                @foreach($elements as $element)
                    @if(is_string($element))<li aria-hidden="true"><span class="page-gap">{{ $element }}</span></li>
                    @else
                        @foreach($element as $page => $url)
                            <li>@if($page === $paginator->currentPage())<span class="page-current" aria-current="page"><span class="sr-only">Stranica </span>{{ $page }}</span>
                                @else<a href="{{ $url }}" aria-label="Idi na stranicu {{ $page }}">{{ $page }}</a>@endif</li>
                        @endforeach
                    @endif
                @endforeach
            </ol>
            @if($paginator->hasMorePages())<a class="page-step" href="{{ $paginator->nextPageUrl() }}" rel="next">Sljedeća →</a>
            @else<span class="page-step page-unavailable" aria-disabled="true">Sljedeća →</span>@endif
        </div>
    </nav>
@endif
