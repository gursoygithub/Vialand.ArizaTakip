@include('errors.layout', [
    'code' => 500,
    'icon' => '⚠️',
    'title' => __('ui.error_500_title'),
    'message' => __('ui.error_500_message'),
    'hint' => __('ui.error_500_hint') !== 'ui.error_500_hint' ? __('ui.error_500_hint') : null,
])
