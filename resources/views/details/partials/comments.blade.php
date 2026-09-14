<h2>Comments ({{ isset($comments) ? $comments->total() : 0 }})</h2>
@if(isset($comments) && count($comments) > 0)
    <div class="details-comment-list">
        @foreach($comments as $comment)
            <article class="details-comment">
                <div><b>{{ $comment['username'] ?? 'Anonymous' }}</b><span class="text-muted"> · {{ userDateDiffForHumans($comment['created_at']) }}</span></div>
                <p>{{ $comment['text'] ?? '' }}</p>
            </article>
        @endforeach
    </div>
@elseif(isset($comments) && $comments->total() > 0)
    <p class="text-muted">No comments on this page.</p>
@else
    <p class="text-muted">No comments yet. Be the first to comment!</p>
@endif
@if(isset($comments) && $comments->hasPages())<div class="mt-4">{{ $comments->links() }}</div>@endif
@auth
    <form method="POST" action="{{ route('details', $release->guid) }}#comments" id="commentForm" class="details-comment-form">
        @csrf
        <div class="grow min-w-0">
            <x-label for="txtAddComment">Write a comment</x-label>
            <x-textarea name="txtAddComment" id="txtAddComment" rows="2" maxlength="2000" placeholder="Write a comment…" required>{{ old('txtAddComment') }}</x-textarea>
            @isset($errors)@error('txtAddComment')<p class="text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</p>@enderror@endisset
        </div>
        <x-button type="submit" icon="fas fa-paper-plane">Post</x-button>
    </form>
@else
    <p class="text-muted mt-4">Please <a href="{{ route('login') }}">log in</a> to add a comment.</p>
@endauth
