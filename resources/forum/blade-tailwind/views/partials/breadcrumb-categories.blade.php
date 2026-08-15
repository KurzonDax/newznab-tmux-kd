@if ($category->parent !== null)
    @include ('forum::partials.breadcrumb-categories', ['category' => $category->parent])
@endif
<li class=""><a href="{{ Forum::route('category.show', $category) }}" class="text-primary-500 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 transition-colors">{{ $category->title }}</a></li>
