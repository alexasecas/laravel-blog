<?php

namespace BinshopsBlog\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use BinshopsBlog\Interfaces\BaseRequestInterface;
use BinshopsBlog\Events\BlogPostAdded;
use BinshopsBlog\Events\BlogPostEdited;
use BinshopsBlog\Events\BlogPostWillBeDeleted;
use BinshopsBlog\Helpers;
use BinshopsBlog\Middleware\UserCanManageBlogPosts;
use BinshopsBlog\Models\BinshopsBlogPost;
use BinshopsBlog\Models\BinshopsBlogUploadedPhoto;
use BinshopsBlog\Requests\CreateBinshopsBlogPostRequest;
use BinshopsBlog\Requests\DeleteBinshopsBlogPostRequest;
use BinshopsBlog\Requests\UpdateBinshopsBlogPostRequest;
use BinshopsBlog\Traits\UploadFileTrait;
use Swis\Laravel\Fulltext\Search;
use Illuminate\Support\Facades\Storage;

/**
 * Class BinshopsBlogAdminController
 * @package BinshopsBlog\Controllers
 */
class BinshopsBlogAdminController extends Controller
{
    use UploadFileTrait;

    /**
     * BinshopsBlogAdminController constructor.
     */
    public function __construct()
    {
        $this->middleware(UserCanManageBlogPosts::class);

        if (!is_array(config("binshopsblog"))) {
            throw new \RuntimeException('The config/binshopsblog.php does not exist. Publish the vendor files for the Binshops Blog package by running the php artisan publish:vendor command');
        }
    }

    /**
     * View all posts
     *
     * @return mixed
     */
    public function index()
    {
        $posts = BinshopsBlogPost::orderBy("posted_at", "desc")
            ->paginate(10);

        return view("binshopsblog_admin::index", ['posts'=>$posts]);
    }

    /**
     * Show form for creating new post
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create_post()
    {
        return view("binshopsblog_admin::posts.add_post");
    }

    /**
     * Save a new post
     *
     * @param CreateBinshopsBlogPostRequest $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function store_post(CreateBinshopsBlogPostRequest $request)
    {
        $new_blog_post = new BinshopsBlogPost($request->all());

        $this->processUploadedImages($request, $new_blog_post);

        if (!$new_blog_post->posted_at) {
            $new_blog_post->posted_at = Carbon::now();
        }

        $new_blog_post->user_id = \Auth::user()->id;
        $new_blog_post->save();

        $new_blog_post->categories()->sync($request->categories());

        Helpers::flash_message("Added post");
        event(new BlogPostAdded($new_blog_post));
        return redirect($new_blog_post->edit_url());
    }

    /**
     * Show form to edit post
     *
     * @param $blogPostId
     * @return mixed
     */
    public function edit_post( $blogPostId)
    {
        $post = BinshopsBlogPost::findOrFail($blogPostId);
        return view("binshopsblog_admin::posts.edit_post")->withPost($post);
    }

    /**
     * Save changes to a post
     *
     * @param UpdateBinshopsBlogPostRequest $request
     * @param $blogPostId
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function update_post(UpdateBinshopsBlogPostRequest $request, $blogPostId)
    {
        /** @var BinshopsBlogPost $post */
        $post = BinshopsBlogPost::findOrFail($blogPostId);
        $post->fill($request->all());

        $this->processUploadedImages($request, $post);

        $post->save();
        $post->categories()->sync($request->categories());

        Helpers::flash_message("Updated post");
        event(new BlogPostEdited($post));

        return redirect($post->edit_url());

    }

    public function remove_photo($postSlug)
    {
        $post = \BinshopsBlog\Models\BinshopsBlogPost::where('slug', $postSlug)->firstOrFail();

        $disk = config('binshopsblog.image_disk', 'public');
        $dir  = trim(str_replace('\\','/', config('binshopsblog.blog_upload_dir', 'images')), '/');

        $columns  = ['image_large', 'image_medium', 'image_thumbnail'];
        $deleted  = [];
        $missing  = [];

        foreach ($columns as $col) {
            $file = $post->{$col};
            if (!$file) {
                continue;
            }

            $base = ltrim(str_replace('\\','/',$file), '/');

            $candidates = [];
            $candidates[] = $dir !== '' ? ($dir . '/' . $base) : $base;   // images/foo.jpg
            $candidates[] = $base;                                        // foo.jpg
            if ($dir !== '' && str_starts_with($base, $dir.'/')) {
                $candidates[] = $base;                                    // images/foo.jpg (legacy stored with dir)
            }

            $candidates = array_values(array_unique(array_map(
                fn ($k) => preg_replace('~[\\\\/]+~', '/', ltrim($k, '/')),
                $candidates
            )));

            // Try delete (returns bool for first item, but we don’t rely on it)
            \Storage::disk($disk)->delete($candidates);

            // Optional: check, but don't *gate* DB update on this
            $anyLeft = false;
            foreach ($candidates as $k) {
                if (\Storage::disk($disk)->exists($k)) {
                    $anyLeft = true;
                    break;
                }
            }

            if ($anyLeft) {
                $missing[$col] = $candidates;
            } else {
                $deleted[$col] = $file;
            }
        }

        // Always clear the DB columns we attempted to remove (avoid S3 eventual consistency issues)
        $toNull = [];
        foreach ($columns as $col) {
            if (!empty($post->{$col})) {
                $toNull[$col] = null;
            }
        }
        if ($toNull) {
            // forceFill bypasses $fillable restrictions
            $post->forceFill($toNull)->save();
        }

        if ($missing) {
            \BinshopsBlog\Helpers::flash_message("Removed files from storage, but some keys may still exist (cache/consistency). DB cleared.");
            \Log::warning('Blog remove_photo: some keys may still exist after delete()', [
                'post_id' => $post->id,
                'disk'    => $disk,
                'missing' => $missing,
                'deleted' => $deleted,
            ]);
        } else {
            \BinshopsBlog\Helpers::flash_message('Photo removed');
        }

        return redirect($post->edit_url());
    }

    /**
     * Delete a post
     *
     * @param DeleteBinshopsBlogPostRequest $request
     * @param $blogPostId
     * @return mixed
     */
    public function destroy_post(DeleteBinshopsBlogPostRequest $request, $blogPostId)
    {

        $post = BinshopsBlogPost::findOrFail($blogPostId);
        event(new BlogPostWillBeDeleted($post));

        $post->delete();

        // todo - delete the featured images?
        // At the moment it just issues a warning saying the images are still on the server.

        return view("binshopsblog_admin::posts.deleted_post")
            ->withDeletedPost($post);

    }

    /**
     * Process any uploaded images (for featured image)
     *
     * @param BaseRequestInterface $request
     * @param $new_blog_post
     * @throws \Exception
     * @todo - next full release, tidy this up!
     */
    protected function processUploadedImages(BaseRequestInterface $request, BinshopsBlogPost $new_blog_post)
    {
        if (!config("binshopsblog.image_upload_enabled")) {
            // image upload was disabled
            return;
        }

        $this->increaseMemoryLimit();

        // to save in db later
        $uploaded_image_details = [];


        foreach ((array)config('binshopsblog.image_sizes') as $size => $image_size_details) {

            if ($image_size_details['enabled'] && $photo = $request->get_image_file($size)) {
                // this image size is enabled, and
                // we have an uploaded image that we can use

                $uploaded_image = $this->UploadAndResize($new_blog_post, $new_blog_post->slug, $image_size_details, $photo);

                $new_blog_post->$size = $uploaded_image['filename'];
                $uploaded_image_details[$size] = $uploaded_image;
            }
        }

        // store the image upload.
        // todo: link this to the BinshopsBlog_post row.
        if (count(array_filter($uploaded_image_details))>0) {
            BinshopsBlogUploadedPhoto::create([
                'source' => "BlogFeaturedImage",
                'uploaded_images' => $uploaded_image_details,
            ]);
        }
    }

    /**
     * Show the search results for $_GET['s']
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Exception
     */
    public function searchBlog(Request $request)
    {
        if (!config("binshopsblog.search.search_enabled")) {
            throw new \Exception("Search is disabled");
        }
        $query = $request->get("s");
        $search = new Search();
        $search_results = $search->run($query);

        \View::share("title", "Search results for " . e($query));

        return view("binshopsblog_admin::index", [
            'search' => true,
            'posts'=>$search_results
        ]);
    }
}
