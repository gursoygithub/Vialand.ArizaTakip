@include('errors.layout', [
    'code' => 503,
    'icon' => '🛠️',
    'title' => __('ui.error_503_title'),
    'message' => __('ui.error_503_message'),
    'hint' => __('ui.error_503_hint') !== 'ui.error_503_hint' ? __('ui.error_503_hint') : null,
])
