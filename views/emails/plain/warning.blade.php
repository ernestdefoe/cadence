<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-cadence.email.'.$blueprint->kind.'.body', [
'count' => $blueprint->value,
'url' => $url->to('forum')->route('cadence.streaks'),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
