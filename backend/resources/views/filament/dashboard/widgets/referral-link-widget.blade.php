<x-filament-widgets::widget class="fi-wi-referral-link">
    <x-filament::section>
        @php($stats = $this->getReferralStats())
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between" x-data="{
            referralLink: '{{ $this->getReferralLink() }}',
            copyToClipboard() {
                const input = this.$refs.referralInput;
                input.select();
                input.setSelectionRange(0, 99999);

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(this.referralLink).then(() => {
                            this.showNotification();
                        });
                    } else {
                        document.execCommand('copy');
                        this.showNotification();
                    }
                } catch (err) {
                    document.execCommand('copy');
                    this.showNotification();
                }
            },
            showNotification() {
                new FilamentNotification()
                    .title('{{ __('Copied') }}')
                    .success()
                    .body('{{ __('Referral link copied to clipboard') }}')
                    .send();
            }
        }">
            <div class="min-w-0 flex-1">
                <x-fp.label>{{ __('Your referral link') }}</x-fp.label>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Share it and earn rewards when someone signs up.') }}
                </p>
                <div class="mt-3 flex gap-2">
                    <x-filament::input.wrapper class="flex-1">
                        <x-filament::input type="text" x-model="referralLink" readonly x-ref="referralInput" class="font-mono text-xs" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="button" color="gray" x-on:click="copyToClipboard()" icon="heroicon-o-clipboard">
                        {{ __('Copy') }}
                    </x-filament::button>
                </div>
            </div>

            <dl class="flex gap-8 text-sm">
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Referred') }}</dt>
                    <dd class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $stats['total_referrals'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Rewarded') }}</dt>
                    <dd class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $stats['rewarded_referrals'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Rewards') }}</dt>
                    <dd class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $stats['total_rewards'] }}</dd>
                </div>
            </dl>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
