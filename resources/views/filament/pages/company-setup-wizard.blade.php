<x-filament-panels::page>
    <div class="space-y-4">
        {{ $this->form }}

        {{-- Tamamla button is rendered by the wizard's submitAction slot
             (see CompanySetupWizard::form). The wizard auto-hides it on
             non-final steps via its built-in isLastStep() Alpine gate. --}}
    </div>
</x-filament-panels::page>
