@php
    $radioStyle = 'cl-seg';
@endphp
@foreach ($item->parameters as $parameter)
    <div class="cl-param" data-param-box>
        @if ($parameter->type === 'status' || $parameter->type === 'option')
            <div class="flex flex-wrap items-center justify-between gap-2">
                @if ($item->parameters->count() > 1)
                    <span class="text-sm font-medium text-slate-600">{{ $parameter->name }}</span>
                @endif
                <div class="{{ $radioStyle }}" role="radiogroup" aria-label="{{ $parameter->name }} · {{ __('Tugas :n', ['n' => $number]) }}">
                    @php
                        $choices = $parameter->type === 'status'
                            ? collect(\App\Enums\ItemStatus::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])
                            : collect(\App\Checklists\ParameterRules::options($parameter->options))->map(fn ($option) => ['value' => $option, 'label' => $option]);
                    @endphp
                    @foreach ($choices as $choice)
                        <label>
                            <input type="radio" name="param-{{ $parameter->id }}" value="{{ $choice['value'] }}" class="sr-only" data-param-id="{{ $parameter->id }}" data-param-radio @checked($parameter->value === $choice['value'])>
                            <span>{{ $choice['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($parameter->type === 'option' && $parameter->normal_option)
                <p class="mt-1 text-xs text-slate-500">{{ __('Nilai normal: :value', ['value' => $parameter->normal_option]) }}</p>
            @endif
        @elseif ($parameter->type === 'checkbox')
            <label class="flex cursor-pointer items-center gap-3 text-base text-slate-800">
                <input type="checkbox" class="h-5 w-5 accent-[#b08a3e]" data-param-id="{{ $parameter->id }}" data-param-check @checked($parameter->value === '1')>
                <span>{{ $parameter->name }}</span>
            </label>
        @else
            <div class="flex flex-wrap items-center gap-2">
                <span class="min-w-40 text-sm font-medium text-slate-600">{{ $parameter->name }}</span>
                <input type="text" inputmode="{{ in_array($parameter->type, ['number', 'meter'], true) ? 'decimal' : 'text' }}" autocomplete="off" maxlength="255"
                    value="{{ $parameter->value }}" data-param-id="{{ $parameter->id }}" data-param-input
                    class="input {{ in_array($parameter->type, ['number', 'meter'], true) ? '!w-40 tabular-nums' : 'min-w-0 flex-1' }}"
                    placeholder="{{ in_array($parameter->type, ['number', 'meter'], true) ? '0' : '' }}" aria-label="{{ $parameter->name }}">
                @if ($parameter->unit)
                    <span class="text-sm font-medium text-slate-500">{{ $parameter->unit }}</span>
                @endif
            </div>
            @if (in_array($parameter->type, ['number', 'meter'], true) && ($parameter->min_value !== null || $parameter->max_value !== null))
                <p class="mt-1 text-xs text-slate-500">
                    {{ __('Batas') }}
                    {{ $parameter->min_value !== null && $parameter->max_value !== null ? $parameter->min_value.' – '.$parameter->max_value : ($parameter->min_value !== null ? '≥ '.$parameter->min_value : '≤ '.$parameter->max_value) }}
                    {{ $parameter->unit }}
                </p>
            @endif
        @endif
    </div>
@endforeach
