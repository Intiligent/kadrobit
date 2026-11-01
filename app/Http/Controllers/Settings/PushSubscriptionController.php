<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use App\Notifications\Push\ReviewSubmittedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Browser notifications of the current browser: turned on (its push subscription is kept), turned off, and an
 * example of an event's notification. See docs/notifications.md.
 */
class PushSubscriptionController extends Controller
{
    /**
     * Keeps the browser's subscription; the same endpoint signed in as another user moves to this one.
     */
    public function store(StorePushSubscriptionRequest $request): Response
    {
        $request->user()->updatePushSubscription(
            $request->validated('endpoint'),
            $request->validated('keys.p256dh'),
            $request->validated('keys.auth'),
            $request->validated('content_encoding') ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['endpoint' => ['required', 'string']]);

        $request->user()->deletePushSubscription($request->input('endpoint'));

        return response()->noContent();
    }

    /**
     * An example of the event's notification (notifications.browser.* settings), exactly as it comes, to every browser
     * the user turned them on in: sent at once, not through the queue, so it shows while the user waits for it.
     */
    public function example(Request $request): Response
    {
        $request->validate(['event' => ['required', 'string', 'in:review_submitted']]);

        $request->user()->notifyNow(match ($request->input('event')) {
            'review_submitted' => new ReviewSubmittedNotification(3),
        });

        return response()->noContent();
    }
}
