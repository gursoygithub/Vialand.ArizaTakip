<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $eventTitle }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .content-padding { padding: 15px !important; }
        }
    </style>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #444; margin: 0; padding: 0; background-color: #f8f9fa;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa;">
    <tr>
        <td align="center" style="padding: 10px 10px;">
            <table class="container" width="600" cellpadding="0" cellspacing="0" border="0"
                   style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-collapse: collapse; overflow: hidden;">

                {{-- Header --}}
                <tr>
                    <td align="center" style="background: linear-gradient(135deg, {{ $headerColor }} 0%, {{ $headerColorDark }} 100%); padding: 40px 20px;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 28px; letter-spacing: 1px; font-weight: 700;">
                            {{ config('app.name') }}
                        </h1>
                        <div style="margin: 15px auto; height: 3px; width: 50px; background-color: #ffffff; border-radius: 2px; opacity: 0.5;"></div>

                        <div style="display: inline-block; padding: 6px 15px; background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 20px; color: #ffffff; font-size: 14px; font-weight: 600; margin-bottom: 10px;">
                            {{ $ticket->ticket_no }}
                        </div>

                        <p style="margin: 5px 0 0 0; color: #ffffff; font-size: 18px; font-weight: 500; opacity: 0.95;">
                            {{ $eventTitle }}
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td class="content-padding" style="padding: 40px;">
                        <p style="margin-top: 0; margin-bottom: 20px; font-size: 18px; color: #333;">
                            Merhaba <strong>{{ $notifiableName }}</strong>,
                        </p>

                        <p style="margin-bottom: 30px; font-size: 15px; color: #666;">
                            {{ $eventDescription }}
                        </p>

                        {{-- Ticket Details --}}
                        <div style="background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 10px; overflow: hidden; margin-bottom: 25px;">
                            <div style="padding: 15px 20px; background-color: #fcfcfc; border-bottom: 1px solid #e9ecef;">
                                <h3 style="margin: 0; color: #333; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Talep Bilgileri</h3>
                            </div>
                            <div style="padding: 20px;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px;">

                                    <tr>
                                        <td width="150" style="padding-bottom: 10px; color: #888;">Talep No</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">{{ $ticket->ticket_no }}</td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">Durum</td>
                                        <td style="padding-bottom: 10px;">
                                            <span style="display: inline-block; padding: 3px 10px; border-radius: 4px; background-color: {{ $headerColor }}22; color: {{ $headerColor }}; font-size: 12px; font-weight: bold; border: 1px solid {{ $headerColor }}44;">
                                                {{ $eventTitle }}
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">Bölge / Lokasyon</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">
                                            {{ $ticket->area?->name ?? '-' }}
                                            @if($ticket->subArea)
                                                / <span style="color: #007bff;">{{ $ticket->subArea->name }}</span>
                                            @endif
                                        </td>
                                    </tr>

                                    @if($ticket->unit)
                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">Birim</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">{{ $ticket->unit->name }}</td>
                                    </tr>
                                    @endif

                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">Öncelik</td>
                                        <td style="padding-bottom: 10px;">
                                            @php
                                                $priorityStyles = match($ticket->priority) {
                                                    \App\Enums\TaskPriorityEnum::Low    => ['bg' => '#d4edda', 'text' => '#155724'],
                                                    \App\Enums\TaskPriorityEnum::Medium  => ['bg' => '#e7f3ff', 'text' => '#007bff'],
                                                    \App\Enums\TaskPriorityEnum::High    => ['bg' => '#fff3cd', 'text' => '#856404'],
                                                    \App\Enums\TaskPriorityEnum::Urgent  => ['bg' => '#f8d7da', 'text' => '#721c24'],
                                                    default                             => ['bg' => '#f1f3f5', 'text' => '#6c757d'],
                                                };
                                            @endphp
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: {{ $priorityStyles['bg'] }}; color: {{ $priorityStyles['text'] }}; font-size: 12px; font-weight: bold;">
                                                {{ $ticket->priority?->getLabel() ?? '-' }}
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">Atanan Personel</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">{{ $ticket->employee?->name ?? '-' }}</td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">Talep Sahibi</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">{{ $ticket->createdBy?->name ?? '-' }}</td>
                                    </tr>

                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">SLA Son Tarihi</td>
                                        <td style="padding-bottom: 10px; color: #333; font-weight: 600;">
                                            @if($ticket->sla_deadline)
                                                {{ $ticket->sla_deadline->translatedFormat('d F Y H:i') }}
                                            @else
                                                <span style="color: #aaa;">SLA tanımlanmamış</span>
                                            @endif
                                        </td>
                                    </tr>

                                    @if($ticket->sla_deadline)
                                    <tr>
                                        <td style="padding-bottom: 10px; color: #888;">SLA Durumu</td>
                                        <td style="padding-bottom: 10px;">
                                            @if($ticket->sla_breached)
                                                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: #f8d7da; color: #721c24; font-size: 12px; font-weight: bold;">
                                                    ⚠️ SLA İhlali
                                                </span>
                                            @else
                                                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; background-color: #d4edda; color: #155724; font-size: 12px; font-weight: bold;">
                                                    ✓ SLA Dahilinde
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                    @endif

                                </table>
                            </div>
                        </div>

                        {{-- Optional note box --}}
                        @if(!empty($note))
                        <div style="margin-bottom: 25px; padding: 15px 20px; background-color: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px;">
                            <strong style="display: block; margin-bottom: 5px; font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px;">Not</strong>
                            <div style="font-size: 14px; color: #333;">{{ $note }}</div>
                        </div>
                        @endif

                        {{-- CTA button --}}
                        <div style="text-align: center; margin-top: 40px; margin-bottom: 20px;">
                            <a href="{{ url('/tickets/' . $ticket->id) }}" target="_blank"
                               style="display: inline-block; padding: 14px 35px; font-size: 16px; color: #ffffff; background-color: {{ $headerColor }}; border-radius: 8px; text-decoration: none; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                                Talebi Görüntüle →
                            </a>
                        </div>

                        <p style="font-size: 14px; text-align: center; color: #888; margin-top: 30px;">
                            İyi çalışmalar dileriz,<br>
                            <strong>{{ config('app.name') }}</strong>
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td align="center" style="background-color: #f1f3f5; padding: 25px; border-top: 1px solid #e9ecef;">
                        <p style="margin: 0; font-size: 12px; color: #999; line-height: 1.5;">
                            Bu bildirim <strong>{{ config('app.name') }}</strong> tarafından otomatik olarak gönderilmiştir. Lütfen yanıtlamayınız.<br>
                            &copy; {{ date('Y') }} Tüm hakları saklıdır.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
