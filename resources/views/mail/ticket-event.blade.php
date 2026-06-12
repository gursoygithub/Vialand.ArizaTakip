<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $eventTitle }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f5f5f5;">
    <tr>
        <td align="center" style="padding: 24px 12px;">
            <table width="560" cellpadding="0" cellspacing="0" border="0"
                   style="background-color: #ffffff; border-radius: 8px; border: 1px solid #e0e0e0;">

                {{-- Header --}}
                <tr>
                    <td style="border-top: 4px solid {{ $headerColor }}; padding: 24px 28px 18px;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px;">
                                    {{ config('app.name') }}
                                </td>
                                <td align="right" style="font-size: 11px; color: #aaa;">
                                    {{ $ticket->ticket_no }}
                                </td>
                            </tr>
                        </table>
                        <h1 style="margin: 10px 0 0; font-size: 20px; font-weight: 700; color: #111;">
                            {{ $eventTitle }}
                        </h1>
                    </td>
                </tr>

                {{-- Divider --}}
                <tr>
                    <td style="height: 1px; background-color: #e0e0e0; font-size: 0; line-height: 0;">&nbsp;</td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding: 24px 28px;">
                        <p style="margin: 0 0 4px; font-size: 15px; color: #111;">
                            Merhaba <strong>{{ $notifiableName }}</strong>,
                        </p>
                        <p style="margin: 0 0 22px; font-size: 14px; color: #666;">
                            {{ $eventDescription }}
                        </p>

                        {{-- Optional note --}}
                        @if(!empty($note))
                        <div style="margin-bottom: 18px; padding: 10px 14px; background-color: #fffbeb; border-left: 3px solid #f59e0b; font-size: 13px; color: #92400e;">
                            <strong>Not:</strong> {{ $note }}
                        </div>
                        @endif

                        {{-- Ticket details --}}
                        <table width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="border: 1px solid #e0e0e0; border-radius: 6px; font-size: 13px;">
                            <tr>
                                <td colspan="2" style="padding: 9px 14px; background-color: #f9f9f9; border-bottom: 1px solid #e0e0e0; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; color: #888;">
                                    Talep Bilgileri
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 9px 14px; color: #888; width: 130px; border-bottom: 1px solid #f0f0f0;">Talep No</td>
                                <td style="padding: 9px 14px; font-weight: 700; color: #111; border-bottom: 1px solid #f0f0f0;">{{ $ticket->ticket_no }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 9px 14px; color: #888; border-bottom: 1px solid #f0f0f0;">Durum</td>
                                <td style="padding: 9px 14px; border-bottom: 1px solid #f0f0f0;">
                                    <span style="display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background-color: {{ $headerColor }}22; color: {{ $headerColor }}; border: 1px solid {{ $headerColor }}44;">
                                        {{ $eventTitle }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 9px 14px; color: #888; border-bottom: 1px solid #f0f0f0;">Bölge / Lokasyon</td>
                                <td style="padding: 9px 14px; font-weight: 700; color: #111; border-bottom: 1px solid #f0f0f0;">
                                    {{ $ticket->area?->name ?? '-' }}
                                    @if($ticket->subArea)
                                        / {{ $ticket->subArea->name }}
                                    @endif
                                </td>
                            </tr>
                            @if($ticket->unit)
                            <tr>
                                <td style="padding: 9px 14px; color: #888; border-bottom: 1px solid #f0f0f0;">Birim</td>
                                <td style="padding: 9px 14px; font-weight: 700; color: #111; border-bottom: 1px solid #f0f0f0;">{{ $ticket->unit->name }}</td>
                            </tr>
                            @endif
                            <tr>
                                <td style="padding: 9px 14px; color: #888; border-bottom: 1px solid #f0f0f0;">Öncelik</td>
                                <td style="padding: 9px 14px; border-bottom: 1px solid #f0f0f0;">
                                    @php
                                        $priorityStyles = match($ticket->priority) {
                                            \App\Enums\TaskPriorityEnum::Low    => ['bg' => '#d4edda', 'text' => '#155724'],
                                            \App\Enums\TaskPriorityEnum::Medium => ['bg' => '#e7f3ff', 'text' => '#007bff'],
                                            \App\Enums\TaskPriorityEnum::High   => ['bg' => '#fff3cd', 'text' => '#856404'],
                                            \App\Enums\TaskPriorityEnum::Urgent => ['bg' => '#f8d7da', 'text' => '#721c24'],
                                            default                             => ['bg' => '#f1f3f5', 'text' => '#6c757d'],
                                        };
                                    @endphp
                                    <span style="display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background-color: {{ $priorityStyles['bg'] }}; color: {{ $priorityStyles['text'] }};">
                                        {{ $ticket->priority?->getLabel() ?? '-' }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 9px 14px; color: #888; border-bottom: 1px solid #f0f0f0;">Atanan Personel</td>
                                <td style="padding: 9px 14px; font-weight: 700; color: #111; border-bottom: 1px solid #f0f0f0;">{{ $ticket->employee?->name ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 9px 14px; color: #888; border-bottom: 1px solid #f0f0f0;">Talep Sahibi</td>
                                <td style="padding: 9px 14px; font-weight: 700; color: #111; border-bottom: 1px solid #f0f0f0;">{{ $ticket->createdBy?->name ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 9px 14px; color: #888;">SLA Son Tarihi</td>
                                <td style="padding: 9px 14px; font-weight: 700; color: #111;">
                                    @if($ticket->sla_deadline)
                                        {{ $ticket->sla_deadline->translatedFormat('d F Y H:i') }}
                                    @else
                                        <span style="color: #aaa; font-weight: 400;">SLA tanımlanmamış</span>
                                    @endif
                                </td>
                            </tr>
                        </table>

                        {{-- CTA --}}
                        <div style="text-align: center; margin-top: 24px; margin-bottom: 8px;">
                            <a href="{{ url('/tickets/' . $ticket->id) }}" target="_blank"
                               style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 700; color: #ffffff; background-color: {{ $headerColor }}; border-radius: 6px; text-decoration: none;">
                                Talebi Görüntüle →
                            </a>
                        </div>

                        <p style="font-size: 13px; text-align: center; color: #999; margin-top: 16px;">
                            İyi çalışmalar,<br>
                            <strong>{{ config('app.name') }}</strong>
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td align="center" style="background-color: #f9f9f9; padding: 14px 28px; border-top: 1px solid #e0e0e0; border-radius: 0 0 8px 8px;">
                        <p style="margin: 0; font-size: 11px; color: #aaa; line-height: 1.5;">
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
