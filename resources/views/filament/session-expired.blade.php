{{-- Livewire's own 419 prompt is English; ask in Turkish instead. --}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    preventDefault();

                    if (confirm(@js(__('ui.session_expired_confirm')))) {
                        window.location.reload();
                    }
                }
            });
        });
    });
</script>
