<x-filament-panels::page>
    <div class="space-y-4">
        {{ $this->form }}

        {{-- Tamamla button. Wizard::submitAction() doesn't wire requiresConfirmation()
             on Action objects, so we mount the page action manually here. --}}
        <div class="flex justify-end pt-2">
            <x-filament::button
                wire:click="mountAction('submit')"
                icon="heroicon-o-check-circle"
                size="lg"
                color="primary"
            >
                Tamamla
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
