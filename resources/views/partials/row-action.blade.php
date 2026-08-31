@php
    /**
     * What one row-action button contains.
     *
     * An icon on its own is the default, because a column of them stays
     * narrow; the label is then the tooltip. ->withLabel() draws both, and an
     * action with no icon draws its label whatever it says.
     */
    $icon = $action['icon'] ?? null;
@endphp
@if ($icon)<span class="dt-row-action-icon" aria-hidden="true">{!! $icon !!}</span>@endif
@if (! $icon || ! empty($action['showLabel']))<span class="dt-row-action-label">{{ $action['label'] }}</span>@endif
