{{-- The delete button of one row of an admin list (`#row-<id>`): asks with the browser's own
     confirm(), then posts the list's delete action to admin/ajax. Driven by `adminRowDelete`. --}}
@props(['action', 'rowId', 'question', 'deletedMessage', 'title'])
<span class="inline-flex"
      x-data="adminRowDelete"
      data-ajax-url="{{ route('admin.ajax') }}"
      data-csrf-token="{{ csrf_token() }}"
      data-ajax-action="{{ $action }}"
      data-row-id="{{ $rowId }}"
      data-question="{{ $question }}"
      data-deleted-message="{{ $deletedMessage }}">
    <button type="button"
            class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300"
            @click="remove"
            title="{{ $title }}">
        <i class="fas fa-trash"></i>
    </button>
</span>
