<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Notifications;

use App\Actions\Notifications\UpdatePreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdatePreferencesRequest;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    #[Response(status: 200, type: 'array{data: array{notifications: array<int, object>, next_cursor: string|null}}')]
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        /** @var User $user */
        $user = $request->user();

        $rows = DatabaseNotification::query()
            ->where('notifiable_type', $user::class)
            ->where('notifiable_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->when($request->query('cursor'), function ($q) use ($request): void {
                [$when, $id] = array_pad(explode('|', (string) $request->query('cursor'), 2), 2, '');
                $when = Carbon::createFromFormat('Y-m-d H:i:s.u', $when)?->toDateTimeString() ?? $when;

                $q->where(fn ($w) => $w
                    ->where('created_at', '<', $when)
                    ->orWhere(fn ($w2) => $w2->where('created_at', '=', $when)->where('id', '<', $id))
                );
            })
            ->get();

        $next = null;

        if ($rows->count() > $limit) {
            $rows->pop();
            /** @var DatabaseNotification $tail */
            /** @var DatabaseNotification $tail */
            $tail = $rows->last();
            assert($tail->created_at !== null);
            $next = $tail->created_at->format('Y-m-d H:i:s.u').'|'.$tail->id;
        }

        return response()->json(['data' => [
            'notifications' => $rows->map(fn (DatabaseNotification $n): array => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? $n->type,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'data' => $n->data['data'] ?? [],
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $next,
        ]]);
    }

    #[Response(status: 200, type: 'array{data: array{preferences: array<int, object>}}')]
    public function preferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $overrides = $user->notificationPreferences()->get()->keyBy('type');

        $matrix = [];

        foreach ($this->catalog->all() as $type => $entry) {
            $matrix[] = [
                'type' => $type,
                'locked' => $entry->locked(),
                'email_default' => $entry->emailDefault(),
                'email_enabled' => $overrides->get($type)->email_enabled ?? $entry->emailDefault(),
            ];
        }

        return response()->json(['data' => ['preferences' => $matrix]]);
    }

    #[Response(status: 200, type: 'array{data: array{updated: bool}}')]
    public function updatePreferences(UpdatePreferencesRequest $request, UpdatePreferences $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, (array) $request->validated('notifications'));

        return response()->json(['data' => ['updated' => true]]);
    }
}
