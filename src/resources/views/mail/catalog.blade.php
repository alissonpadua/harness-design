@component('mail::message')
# {{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionText !== null && $actionUrl !== null)
@component('mail::button', ['url' => $actionUrl])
{{ $actionText }}
@endcomponent
@endif

<sub style="color:#94a3b8;font-size:12px">{{ $appName }} — notifications you can manage under Settings → Notifications.</sub>
@endcomponent
