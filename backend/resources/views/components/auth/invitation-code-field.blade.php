@props(['wireModel' => null])

{{-- Rendered only while invite-only registration is on and the visitor carries no code. --}}
<x-input.field label="{{ __('Invitation code') }}" type="text"
               name="referral_code"
               @if ($wireModel) wire:model="{{ $wireModel }}" @endif
               value="{{ old('referral_code') }}" required
               autocomplete="off" max-width="w-full"/>

@error('referral_code')
    <span class="text-xs text-error" role="alert">
        {{ $message }}
    </span>
@enderror
