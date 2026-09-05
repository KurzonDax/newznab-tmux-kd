{{-- Validation summary for an admin form. --}}
{{-- $errors is only bound when the request went through the session middleware. --}}
@if(isset($errors) && $errors->any())
    <div class="bg-red-100 dark:bg-red-900/20 border border-red-400 dark:border-red-900 text-red-700 dark:text-red-300 px-4 py-3 rounded mb-4">
        <i class="fas fa-exclamation-circle mr-2"></i>Please correct the errors below and try again.
        <ul class="mt-2 list-disc list-inside text-sm">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
