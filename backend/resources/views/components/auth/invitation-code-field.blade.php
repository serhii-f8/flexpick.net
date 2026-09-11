{{-- Rendered only while invite-only registration is on and the visitor carries no code.
     Extra attributes (e.g. wire:model on the OTP form) are spread onto the input. --}}
<x-input.field label="{{ __('Invitation code') }}" type="text"
               name="referral_code"
               value="{{ old('referral_code') }}" required
               autocomplete="off" max-width="w-full"
               {{ $attributes }} />

@error('referral_code')
    <span class="text-xs text-error" role="alert">
        {{ $message }}
    </span>
@enderror
