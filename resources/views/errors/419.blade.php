@include('errors.layout', [
    'code' => 419,
    'icon' => '⏱️',
    'title' => __('ui.error_419_title'),
    'message' => __('ui.error_419_message'),
    'hint' => __('ui.error_419_hint') !== 'ui.error_419_hint' ? __('ui.error_419_hint') : null,
])
