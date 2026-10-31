@props(['targets' => [], 'messages' => []])
@if($messages !== [])
    <div class="notice notice-error" role="alert" tabindex="-1" data-validation-summary>
        <strong>Provjerite unos</strong>
        <ul>
            @foreach($messages as $field => $fieldMessages)
                @foreach($fieldMessages as $message)
                    <li>@if(isset($targets[$field]))<a href="#{{ $targets[$field] }}" data-validation-link>{{ $message }}</a>@else{{ $message }}@endif</li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
