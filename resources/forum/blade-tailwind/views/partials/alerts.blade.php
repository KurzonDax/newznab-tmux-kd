@if (isset($errors) && !$errors->isEmpty())
    @foreach ($errors->all() as $error)
        @include ('forum::partials.alert', ['type' => 'danger', 'message' => $error])
    @endforeach
@endif
