@php($percentage = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0)
<div class="account-usage">
    <div><span id="{{ $id }}-label">{{ $label }}</span><strong>{{ number_format($used) }} / {{ number_format($limit) }}</strong></div>
    <div class="account-usage-track" role="progressbar" aria-labelledby="{{ $id }}-label" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}" aria-valuetext="{{ $used }} of {{ $limit }}"><span class="progress-bar" data-width="{{ $percentage }}"></span></div>
</div>
