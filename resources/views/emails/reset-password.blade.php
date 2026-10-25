@extends('emails.layout')

@section('title', __('passwords.email.subject', ['app' => config('app.name')]))

@section('preheader', __('passwords.email.intro'))

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:30px;font-weight:600;">{{ __('passwords.email.title') }}</h1>

    <p style="margin:0 0 12px;">{{ __('passwords.email.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 24px;">{{ __('passwords.email.intro') }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="border-radius:8px;background:#f53003;">
                <a href="{{ $resetUrl }}" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;border-radius:8px;">
                    {{ __('passwords.email.action') }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:12px 0 24px;font-size:13px;color:#706f6c;">{{ trans_choice('passwords.email.expire', $expireMinutes, ['count' => $expireMinutes]) }}</p>

    <p style="margin:0 0 24px;font-size:13px;line-height:20px;color:#706f6c;">{{ __('passwords.email.outro') }}</p>

    <p style="margin:0 0 4px;font-size:12px;line-height:18px;color:#706f6c;">{{ __('passwords.email.link_hint') }}</p>
    <p style="margin:0;font-size:12px;line-height:18px;word-break:break-all;">
        <a href="{{ $resetUrl }}" style="color:#f53003;text-decoration:none;">{{ $resetUrl }}</a>
    </p>
@endsection
