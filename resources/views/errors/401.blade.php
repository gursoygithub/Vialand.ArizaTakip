@include('errors.layout', [
    'code' => 401,
    'icon' => '🔒',
    'title' => __('ui.error_401_title'),
    'message' => __('ui.error_401_message'),
    'hint' => __('ui.error_401_hint') !== 'ui.error_401_hint' ? __('ui.error_401_hint') : null,
])
