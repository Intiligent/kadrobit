@extends('emails.layout')

@section('title', __('verification.email.subject', ['code' => $code, 'app' => config('app.name')]))

@section('preheader', __('verification.email.preheader', ['code' => $code]))

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:30px;font-weight:600;">{{ __('verification.email.title') }}</h1>

    <p style="margin:0 0 12px;">{{ __('verification.email.greeting', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">{{ __('verification.email.intro') }}</p>

    <p style="margin:0 0 8px;font-size:32px;line-height:40px;font-weight:600;letter-spacing:8px;font-family:'SFMono-Regular',Menlo,Consolas,monospace;">{{ $code }}</p>
    <p style="margin:0 0 24px;font-size:13px;color:#706f6c;">{{ trans_choice('verification.email.expire', $expireMinutes, ['count' => $expireMinutes]) }}</p>

    <p style="margin:0 0 12px;font-size:13px;color:#706f6c;">{{ __('verification.email.action_hint') }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="border-radius:8px;background:#f53003;">
                <a href="{{ $verifyUrl }}" style="display:inline-block;padding:12px 24px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;border-radius:8px;">
                    {{ __('verification.email.action') }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 24px;font-size:13px;line-height:20px;color:#706f6c;">{{ __('verification.email.outro') }}</p>

    <p style="margin:0 0 4px;font-size:12px;line-height:18px;color:#706f6c;">{{ __('verification.email.link_hint') }}</p>
    <p style="margin:0;font-size:12px;line-height:18px;word-break:break-all;">
        <a href="{{ $verifyUrl }}" style="color:#f53003;text-decoration:none;">{{ $verifyUrl }}</a>
    </p>
@endsection
