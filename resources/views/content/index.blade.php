@extends('layouts.main')

@section('content')
<div class="surface-panel rounded-xl shadow-sm">
    <x-breadcrumb :items="[['label' => 'Home', 'url' => url($site['home_link'] ?? '/')]]" />
        <!-- Content List Page -->
        <x-page-header title="Content" description="Browse our content pages" icon="fas fa-file-alt" />

        <div class="px-6 pb-6">
            @if(!empty($content) && count($content) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($content as $item)
                        @if($item)
                            <div class="surface-panel-alt rounded-lg border p-6 transition hover:shadow-md">
                                <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-3">
                                    <a href="{{ url('/content?page=content&id=' . $item->id) }}" class="hover:text-primary-600 dark:hover:text-primary-400 transition">
                                        {{ filled($item->title) ? $item->title : 'Untitled' }}
                                    </a>
                                </h3>

                                @if(isset($item->metadescription))
                                    <p class="text-gray-600 dark:text-gray-400 mb-4">{{ Str::limit($item->metadescription, 150) }}</p>
                                @endif

                                <a href="{{ url('/content?page=content&id=' . $item->id) }}" class="inline-flex items-center text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium">
                                    Read More <i class="fas fa-arrow-right ml-2"></i>
                                </a>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                <x-empty-state
                    icon="fas fa-file-alt"
                    title="No Content Available"
                    message="There is no content to display at this time."
                />
            @endif
        </div>
</div>
@endsection
