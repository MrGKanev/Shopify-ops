{{--
    Report page shell: header, the run form (`form` slot), export / credentials /
    failure messages, then the default slot (normally an `@if ($result)` block with
    x-report.results). Message props take untranslated strings so a report
    definition can supply them. Reports needing several integrations can pass
    `:configuration-errors="[message => bool]"` instead of one flag + message.
--}}
@props([
    'eyebrow' => null,
    'title',
    'subtitle' => null,
    'configurationError' => false,
    'credentialsMessage' => 'Shopify credentials are incomplete for the active store.',
    'configurationErrors' => [],
    'reportFailed' => false,
    'failureMessage' => 'The report could not be completed. Check Shopify and try again.',
])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-6']) }}>
    <x-page-header :eyebrow="$eyebrow" :title="$title" :subtitle="$subtitle" />

    {{ $form ?? '' }}

    @error('export')
        <x-alert tone="error">{{ $message }}</x-alert>
    @enderror
    @foreach (($configurationError ? [$credentialsMessage => true] : []) + $configurationErrors as $credentialsWarning => $isMissing)
        @if ($isMissing)
            <x-alert tone="warn">{{ $credentialsWarning }}</x-alert>
        @endif
    @endforeach
    @if ($reportFailed)
        <x-alert tone="error">{{ request()->attributes->get('reportFailureReason', $failureMessage) }}</x-alert>
    @endif

    {{ $slot }}
</div>
