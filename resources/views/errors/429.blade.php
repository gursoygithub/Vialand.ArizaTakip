@include('errors.layout', [
    'code' => 429,
    'icon' => '✋',
    'title' => __('ui.error_429_title'),
    'message' => __('ui.error_429_message'),
    'hint' => __('ui.error_429_hint') !== 'ui.error_429_hint' ? __('ui.error_429_hint') : null,
])
