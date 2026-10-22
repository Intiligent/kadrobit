<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Previews transactional emails in the browser. Each preview renders the real notification's
 * mail message, so it always matches what is sent. Local environment only.
 *
 * ?locale=xx renders the email in another language.
 */
class EmailPreviewController extends Controller
{
    public function index(): View
    {
        $previews = collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route, string $name) => str_starts_with($name, 'dev.emails.') && $name !== 'dev.emails.index')
            ->keys()
            ->sort()
            ->map(fn (string $name) => ['name' => str($name)->after('dev.emails.')->headline(), 'url' => route($name)])
            ->values();

        return view('dev.emails', ['previews' => $previews]);
    }

    public function resetPassword(Request $request): Response
    {
        $this->applyLocale($request);

        return $this->render(
            (new ResetPasswordNotification(str_repeat('0', 64)))->toMail($this->recipient($request)),
        );
    }

    /**
     * The logged-in user, or an unsaved sample user: previews never write to the database.
     */
    protected function recipient(Request $request): User
    {
        return $request->user() ?? new User([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'username' => 'jane.doe',
            'email' => 'jane@example.com',
        ]);
    }

    /**
     * Applied before building the message, so the subject is translated too.
     */
    protected function applyLocale(Request $request): void
    {
        if ($locale = $request->query('locale')) {
            app()->setLocale((string) $locale);
        }
    }

    protected function render(MailMessage $mail): Response
    {
        return response($mail->render());
    }
}
