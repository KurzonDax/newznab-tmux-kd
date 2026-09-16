<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\View\View;
use TeamTeaTime\Forum\Events\UserViewingRecent;
use TeamTeaTime\Forum\Events\UserViewingUnread;
use TeamTeaTime\Forum\Http\Controllers\Blade\ThreadController;
use TeamTeaTime\Forum\Models\Thread;
use TeamTeaTime\Forum\Support\Access\CategoryAccess;

class ForumThreadController extends ThreadController
{
    public function recent(Request $request): View
    {
        $threads = $this->recentThreads($request)->paginate()->withQueryString();

        if ($request->user() !== null) {
            UserViewingRecent::dispatch($request->user(), $threads->getCollection());
        }

        $threadReadStatuses = $this->readStatuses($request, $threads->getCollection());

        /** @var View $view */
        $view = ViewFactory::make('forum::thread.recent', compact('threads', 'threadReadStatuses'));

        return $view;
    }

    public function unread(Request $request): View
    {
        $query = $this->recentThreads($request);
        $user = $request->user();

        if ($user === null || ! config('forum.general.old_thread_threshold')) {
            $query->whereRaw('1 = 0');
        } else {
            $reader = DB::table(Thread::READERS_TABLE)
                ->select('thread_id')
                ->whereColumn('thread_id', 'forum_threads.id')
                ->where('user_id', $user->getAuthIdentifier());

            $query->where(function (Builder $query) use ($reader): void {
                $query->whereNotExists($reader)
                    ->orWhereExists((clone $reader)->where(function ($query): void {
                        $query->whereColumn('forum_threads.updated_at', '>', 'forum_threads_read.updated_at')
                            ->orWhereNull('forum_threads_read.updated_at');
                    }));
            });
        }

        $threads = $query->paginate()->withQueryString();
        $threadReadStatuses = $this->readStatuses($request, $threads->getCollection());

        if ($user !== null) {
            UserViewingUnread::dispatch($user, $threads->getCollection());
        }

        /** @var View $view */
        $view = ViewFactory::make('forum::thread.unread', compact('threads', 'threadReadStatuses'));

        return $view;
    }

    /**
     * @param  Collection<int, Thread>  $threads
     * @return array<int, string|null>
     */
    private function readStatuses(Request $request, Collection $threads): array
    {
        if ($request->user() === null || ! config('forum.general.old_thread_threshold') || $threads->isEmpty()) {
            return [];
        }

        $readAt = DB::table(Thread::READERS_TABLE)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->whereIn('thread_id', $threads->modelKeys())
            ->pluck('updated_at', 'thread_id')->all();
        $statuses = [];

        foreach ($threads as $thread) {
            $statuses[$thread->id] = ! array_key_exists($thread->id, $readAt)
                ? trans('forum::general.'.Thread::STATUS_UNREAD)
                : (($readAt[$thread->id] === null || $thread->updated_at->toDateTimeString() > $readAt[$thread->id])
                    ? trans('forum::general.'.Thread::STATUS_UPDATED) : null);
        }

        return $statuses;
    }

    /** @return Builder<Thread> */
    private function recentThreads(Request $request): Builder
    {
        $threads = Thread::recent()
            ->whereIn('category_id', CategoryAccess::getFilteredIdsFor($request->user()))
            ->orderByDesc('id')
            ->with('category', 'author', 'lastPost.author', 'lastPost.thread');

        if ($request->has('category_id')) {
            $threads->where('category_id', $request->input('category_id'));
        }

        return $threads;
    }
}
