@extends('binshopsblog_admin::layouts.admin_layout')

@section('content')
    <div class="alert alert-success">
        <b>Deleted that post</b>
        <br>
        <a href="{{ route('binshopsblog.admin.index') }}" class="btn btn-primary mt-2">Back to posts overview</a>
    </div>

    @php
        $disk = config('binshopsblog.image_disk', 'public');
        $dir  = trim(config('binshopsblog.blog_upload_dir', 'blog'), '/');

        $images_to_delete = [];
        foreach ((array) config('binshopsblog.image_sizes') as $image_size => $image_size_info) {
            if (!empty($deletedPost->{$image_size})) {
                $images_to_delete[] = $image_size;
            }
        }
    @endphp

    @if(count($images_to_delete))
        <p>However, the following images were <strong>not</strong> deleted:</p>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Image / link</th>
                        <th>Filename / size</th>
                        <th>Storage location</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($images_to_delete as $image_size)
                    @php
                        $filename = $deletedPost->{$image_size};
                        $key      = $dir . '/' . $filename;
                        $exists   = \Storage::disk($disk)->exists($key);
                        $url      = $exists ? \Storage::disk($disk)->url($key) : null;
                        $bytes    = $exists ? \Storage::disk($disk)->size($key) : null;
                        $humanKb  = $bytes ? number_format($bytes / 1024, 1) . ' KB' : '—';
                    @endphp
                    <tr>
                        <td class="text-center">
                            @if($exists && $url)
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm mb-2">View</a>
                                <div>
                                    <img src="{{ $url }}" alt="{{ $filename }}" width="100" class="img-thumbnail">
                                </div>
                            @else
                                <span class="badge text-bg-warning">Missing on disk</span>
                            @endif
                        </td>
                        <td class="small">
                            <code>{{ $filename }}</code>
                            <div class="text-muted">{{ $humanKb }}</div>
                        </td>
                        <td class="small">
                            <div><strong>Disk:</strong> <code>{{ $disk }}</code></div>
                            <div><strong>Key:</strong> <code>{{ $key }}</code></div>
                            @if($url)
                                <div class="text-truncate"><strong>URL:</strong> <a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a></div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mb-4">If you want those images deleted please remove them manually from the <code>{{ $disk }}</code> disk (key shown above).</p>
    @endif

    <hr class="my-5">

    <p>Was deleting it a mistake? Here is some of the output from the deleted post, as JSON. Please use a JSON viewer to retrieve the information.</p>

    <textarea readonly class="form-control" rows="10">{{ $deletedPost->toJson() }}</textarea>
@endsection
