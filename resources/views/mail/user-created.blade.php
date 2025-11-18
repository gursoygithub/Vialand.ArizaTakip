<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('ui.user_created') }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .content {
            margin-bottom: 30px;
        }
        .button {
            display: inline-block;
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 14px;
            color: #666;
        }
        .info {
            margin: 15px 0;
        }
        .info strong {
            display: inline-block;
            width: 150px;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ __('ui.fault_tracking_portal') }}</h1>
</div>

<div class="content">
    <p>{{ __('ui.hello') }} {{ $user?->name, }}</p>

    <p>{{ __('ui.user_created_message') }}</p>

    <div class="info">
        {{ __('ui.email') }}: <strong>{{ $user?->email }}</strong><br>
        {{ __('ui.password') }}: <strong>{{ $password }}</strong>
    </div>

    <div class="danger" style="color: red;">
        <strong>{{ __('ui.user_created_warning') }}</strong>
    </div>
</div>

<p>{{ __('ui.enjoy_your_work') }}</p>

{{--    <div class="footer">--}}
{{--        <p>{{ "Gürsoy Grup" }}<br>{{ __('ui.vialand_theme_parc') }}<br>{{ __('ui.guest_office')}}</p>--}}
{{--    </div>--}}
</body>
</html>
