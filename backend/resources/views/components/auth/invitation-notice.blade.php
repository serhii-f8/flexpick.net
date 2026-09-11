@props(['requiresCode' => false])

<div class="text-sm mb-2">
    @if ($requiresCode)
        <p class="m-0">{{ __('Registration is invitation-only. Enter the invitation code you were given to create an account.') }}</p>
    @else
        <p class="m-0">{{ __('Your invitation has been applied.') }}</p>
    @endif
</div>
