<footer class="border-t border-white/5 mt-12">
    <div class="mx-auto w-full max-w-(--breakpoint-xl) p-4 py-10">
        <div class="md:flex md:justify-between md:items-start gap-8">
            <div class="mb-6 md:mb-0">
                <a href="{{ config('app.frontend_url') }}" class="flex items-center">
                    <x-wordmark />
                </a>
                <p class="font-mono text-[11px] tracking-[0.14em] text-cream-200/50 mt-4">{{ __('WE RESCUE AI-BUILT CODEBASES') }}</p>
            </div>
            <div class="mb-6 md:mb-0">
                <p class="font-mono text-[10px] tracking-[0.16em] text-cream-200/50 mb-4">{{ __('LEGAL') }}</p>
                <ul class="flex flex-col gap-2 text-sm">
                    <li>
                        <a href="{{route('privacy-policy')}}" class="text-cream-200/70 hover:text-cream-100">{{ __('Privacy Policy') }}</a>
                    </li>
                    <li>
                        <a href="{{route('terms-of-service')}}" class="text-cream-200/70 hover:text-cream-100">{{ __('Terms of Service') }}</a>
                    </li>
                </ul>
            </div>
            <div>
                <p class="font-mono text-[10px] tracking-[0.16em] text-cream-200/50 mb-4">{{ __('CONTACT') }}</p>
                <a href="mailto:info@flexpick.net" class="text-cream-200/70 hover:text-cream-100 text-sm">info@flexpick.net</a>
            </div>
        </div>
        <hr class="my-8 border-white/5" />
        <div class="sm:flex sm:items-center sm:justify-between">
          <span class="text-xs text-cream-200/50 sm:text-center">© {{ date('Y') }} <a href="{{ config('app.frontend_url') }}" class="hover:underline text-cream-200/70">{{ config('app.name') }}™</a>. {{ __('All rights reserved.') }}
          </span>
            <div class="flex gap-3 mt-4 sm:justify-center sm:mt-0">
                @if (!empty(config('app.social_links.facebook')))
                    <x-link.social-icon name="facebook" title="{{ __('Facebook page') }}" link="{{config('app.social_links.facebook')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.instagram')))
                    <x-link.social-icon name="instagram" title="{{ __('Instagram page') }}" link="{{config('app.social_links.instagram')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.youtube')))
                    <x-link.social-icon name="youtube" title="{{ __('YouTube channel') }}" link="{{config('app.social_links.youtube')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.x')))
                    <x-link.social-icon name="x" title="{{ __('Twitter page') }}" link="{{config('app.social_links.x')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.linkedin')))
                    <x-link.social-icon name="linkedin" title="{{ __('Linkedin page') }}" link="{{config('app.social_links.linkedin')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.github')))
                    <x-link.social-icon name="github" title="{{ __('Github page') }}" link="{{config('app.social_links.github')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.discord')))
                    <x-link.social-icon name="discord" title="{{ __('Discord community') }}" link="{{config('app.social_links.discord')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
            </div>
        </div>
    </div>
</footer>
