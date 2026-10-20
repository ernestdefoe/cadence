<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-cadence.email.'.$blueprint->kind.'.body', [
            'count' => $blueprint->value,
            'url' => $url->to('forum')->route('cadence.streaks'),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
