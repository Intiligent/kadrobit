@php
    $appName = config('app.name');
    $appUrl = rtrim(config('app.url'), '/');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>@yield('title')</title>
</head>
<body style="margin:0;padding:0;background:#FDFDFC;font-family:'Instrument Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1b1b18;-webkit-font-smoothing:antialiased;">

{{-- Preheader: hidden text shown in the inbox preview. --}}
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:transparent;opacity:0;">
    @yield('preheader')
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#FDFDFC;">
    <tr>
        <td align="center" style="padding:24px 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e3e3e0;border-radius:12px;">
                <tr>
                    <td style="padding:28px 32px 0;">
                        <a href="{{ $appUrl }}" style="font-size:18px;font-weight:600;color:#1b1b18;text-decoration:none;">{{ $appName }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 32px;font-size:15px;line-height:24px;color:#1b1b18;">
                        @yield('content')
                    </td>
                </tr>
            </table>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="max-width:560px;width:100%;">
                <tr>
                    <td style="padding:16px 32px 0;font-size:12px;line-height:18px;color:#706f6c;text-align:center;">
                        &copy; {{ now()->year }} {{ $appName }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
