@include('errors.layout', [
    'code' => 404,
    'icon' => '🔎',
    'title' => __('ui.error_404_title'),
    'message' => __('ui.error_404_message'),
    'hint' => __('ui.error_404_hint') !== 'ui.error_404_hint' ? __('ui.error_404_hint') : null,
])
