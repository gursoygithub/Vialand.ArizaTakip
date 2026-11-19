@include('errors.layout', [
    'code' => 403,
    'icon' => '⛔',
    'title' => __('ui.error_403_title'),
    'message' => __('ui.error_403_message'),
    'hint' => __('ui.error_403_hint') !== 'ui.error_403_hint' ? __('ui.error_403_hint') : null,
])
