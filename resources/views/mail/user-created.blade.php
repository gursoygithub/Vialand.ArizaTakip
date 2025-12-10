<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('ui.user_created') }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container {
                width: 100% !important;
            }
            .content-padding {
                padding: 15px !important;
            }
        }
    </style>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; margin: 0; padding: 0; background-color: #f4f4f4;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4;">
    <tr>
        <td align="center" style="padding: 20px 0;">
            <table class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border-collapse: collapse;">

                <tr>
                    <td align="center" style="background-color: #007bff; padding: 30px 20px; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 24px;">{{ __('ui.user_created') }}</h1>
                    </td>
                </tr>

                <tr>
                    <td class="content-padding" style="padding: 30px;">
                        <p style="margin-bottom: 20px; font-size: 16px;">
                            {{ __('ui.hello') }} <strong>{{ $user?->name }}</strong>,
                        </p>

                        <p style="margin-bottom: 25px; font-size: 16px;">
                            {{ __('ui.user_created_message') }}
                        </p>

                        <table width="100%" cellpadding="10" cellspacing="0" border="0" style="background-color: #e9ecef; border-radius: 6px; margin-bottom: 25px; border: 1px solid #ced4da;">
                            <tr>
                                <td style="font-size: 16px; color: #333333; padding-bottom: 5px;">
                                    <strong style="display: inline-block; width: 120px; color: #555;">{{ __('ui.email') }}:</strong>
                                    <span style="font-weight: bold; color: #007bff;">{{ $user?->email }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size: 16px; color: #333333; padding-top: 5px;">
                                    <strong style="display: block; margin-bottom: 8px; color: #555;">{{ __('ui.password') }}:</strong>

                                    <div style="background-color: #ffffff; border: 1px dashed #adb5bd; padding: 10px; border-radius: 4px; text-align: center; overflow-x: auto;">
                                        <code style="font-family: 'Courier New', Courier, monospace; font-size: 18px; font-weight: bold; color: #dc3545; white-space: nowrap; display: block;">
                                            {{$password}}
                                        </code>
                                    </div>
                                </td>
                            </tr>
                        </table>

{{--                        <table width="100%" cellpadding="10" cellspacing="0" border="0" style="border-radius: 6px; margin-bottom: 25px;">--}}
{{--                            <tr>--}}
{{--                                <td style="font-size: 16px; color: #333333; padding-bottom: 10px;">--}}
{{--                                    <strong style="display: inline-block; width: 120px;">{{ __('ui.email') }}:</strong>--}}
{{--                                    <span style="font-weight: bold; color: #007bff;">{{ $user?->email }}</span>--}}
{{--                                </td>--}}
{{--                            </tr>--}}
{{--                            <tr>--}}
{{--                                <td style="font-size: 16px; color: #333333; padding-top: 10px;">--}}
{{--                                    <strong style="display: block; margin-bottom: 8px;">{{ __('ui.password') }}:</strong>--}}

{{--                                    <div style="background-color: #f8f9fa; border: 1px dashed #ced4da; padding: 10px; border-radius: 4px; text-align: center; overflow-x: auto;">--}}
{{--                                        <code style="font-family: 'Courier New', Courier, monospace; font-size: 18px; font-weight: bold; color: #dc3545; white-space: nowrap; display: block;">--}}
{{--                                            {{$password}}--}}
{{--                                        </code>--}}
{{--                                    </div>--}}
{{--                                </td>--}}
{{--                            </tr>--}}
{{--                        </table>--}}

                        <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom: 25px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ config('app.url') }}" target="_blank" style="display: inline-block; padding: 12px 25px; font-size: 16px; color: #ffffff; background-color: #28a745; border-radius: 5px; text-decoration: none; font-weight: bold;">
                                        {{ __('ui.link_to_panel') }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <div style="background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 5px; font-size: 14px; margin-bottom: 20px;">
                            <p style="margin: 0;">
                                <strong>{{ __('ui.user_created_warning') }}</strong>
                            </p>
                        </div>

                        <p style="font-size: 16px;">
                            {{ __('ui.enjoy_your_work') }}
                        </p>

                        <p style="font-size: 16px; margin-top: 25px;">
                            {{__('ui.best_regards')}}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="background-color: #e9ecef; padding: 20px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                        <p style="margin: 0; font-size: 12px; color: #6c757d;">
                            {{ __('ui.footer_message') }}
                        </p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #6c757d;">
                            &copy; {{ date('Y') }} {{ __('ui.gursoy_group') }} - {{ config('app.name') }}. {{ __('ui.all_rights_reserved') }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>