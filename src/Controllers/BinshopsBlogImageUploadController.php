<?php

namespace BinshopsBlog\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use BinshopsBlog\Middleware\UserCanManageBlogPosts;
use BinshopsBlog\Models\BinshopsBlogUploadedPhoto;
use File;
use BinshopsBlog\Requests\UploadImageRequest;
use BinshopsBlog\Traits\UploadFileTrait;
use Illuminate\Support\Facades\Storage;

/**
 * Class BinshopsBlogAdminController
 * @package BinshopsBlog\Controllers
 */
class BinshopsBlogImageUploadController extends Controller
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


        if (!config("binshopsblog.image_upload_enabled")) {
            throw new \RuntimeException("The binshopsblog.php config option has not enabled image uploading");
        }


    }

    /**
     * Show the main listing of uploaded images
     * @return mixed
     */


    public function index()
    {
        return view("binshopsblog_admin::imageupload.index", ['uploaded_photos' => BinshopsBlogUploadedPhoto::orderBy("id", "desc")->paginate(10)]);
    }

    /**
     * show the form for uploading a new image
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        return view("binshopsblog_admin::imageupload.create", []);
    }

    /**
     * Save a new uploaded image
     *
     * @param UploadImageRequest $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Exception
     */
    public function store(UploadImageRequest $request)
    {
        $processed_images = $this->processUploadedImages($request);

        return view("binshopsblog_admin::imageupload.uploaded", ['images' => $processed_images]);
    }

    /**
     * Process any uploaded images (for featured image)
     *
     * @param UploadImageRequest $request
     *
     * @return array returns an array of details about each file resized.
     * @throws \Exception
     * @todo - This class was added after the other main features, so this duplicates some code from the main blog post admin controller (BinshopsBlogAdminController). For next full release this should be tided up.
     */
    protected function processUploadedImages(UploadImageRequest $request)
    {
        $this->increaseMemoryLimit();
        $photo = $request->file('upload');

        // to save in db later
        $uploaded_image_details = [];

        $sizes_to_upload = $request->get("sizes_to_upload");

        // now upload a full size - this is a special case, not in the config file. We only store full size images in this class, not as part of the featured blog image uploads.
        if (isset($sizes_to_upload['BinshopsBlog_full_size']) && $sizes_to_upload['BinshopsBlog_full_size'] === 'true') {

            $uploaded_image_details['BinshopsBlog_full_size'] = $this->UploadAndResize(null, $request->get("image_title"), 'fullsize', $photo);

        }

        foreach ((array)config('binshopsblog.image_sizes') as $size => $image_size_details) {

            if (!isset($sizes_to_upload[$size]) || !$sizes_to_upload[$size] || !$image_size_details['enabled']) {
                continue;
            }

            // this image size is enabled, and
            // we have an uploaded image that we can use
            $uploaded_image_details[$size] = $this->UploadAndResize(null, $request->get("image_title"), $image_size_details, $photo);
        }


        // store the image upload.
        BinshopsBlogUploadedPhoto::create([
            'image_title' => $request->get("image_title"),
            'source' => "ImageUpload",
            'uploader_id' => optional(\Auth::user())->id,
            'uploaded_images' => $uploaded_image_details,
        ]);


        return $uploaded_image_details;

    }

    // Delete a single file variant by key/filename and optionally prune log JSON
    public function deleteFileVariant(Request $request)
    {
        $request->validate([
            'key'       => 'required|string',
            'filename'  => 'required|string',
            'image_id'  => 'nullable|integer',
            'cleanup_log' => 'nullable|boolean',
            'return'    => 'nullable|url',
        ]);

        $disk = config('binshopsblog.image_disk', 'public');
        $key  = preg_replace('~[\\\\/]+~', '/', ltrim($request->string('key'), '/'));

        \Storage::disk($disk)->delete($key);

        if ($request->boolean('cleanup_log') && $request->filled('image_id')) {
            $photo = \BinshopsBlog\Models\BinshopsBlogUploadedPhoto::find($request->integer('image_id'));
            if ($photo) {
                $imgs = (array) $photo->uploaded_images;
                foreach ($imgs as $slot => $meta) {
                    if (($meta['filename'] ?? null) === $request->string('filename')) {
                        unset($imgs[$slot]);
                    }
                }
                // If no variants left, delete log row; else save pruned JSON
                if (empty($imgs)) {
                    $photo->delete();
                } else {
                    $photo->uploaded_images = $imgs;
                    $photo->save();
                }
            }
        }

        \BinshopsBlog\Helpers::flash_message('Image variant removed.');
        return redirect($request->input('return', route('binshopsblog.admin.images.all')));
    }

    // Delete the entire upload log + all its variants
    public function deleteUploadLog(Request $request)
    {
        $uploadedPhoto = BinshopsBlogUploadedPhoto::findOrFail($request->input('uploaded_photo_id'));

        $disk = config('binshopsblog.image_disk', 'public');
        $dir  = trim(str_replace('\\','/', config('binshopsblog.blog_upload_dir', 'images')), '/');

        foreach ((array) $uploadedPhoto->uploaded_images as $f) {
            if (!empty($f['filename'])) {
                $base = ltrim(str_replace('\\','/',$f['filename']), '/');
                $key  = $dir ? "$dir/$base" : $base;
                $key  = preg_replace('~[\\\\/]+~','/',$key);
                Storage::disk($disk)->delete($key);
            }
        }

        $uploadedPhoto->delete();
        \BinshopsBlog\Helpers::flash_message('Upload entry and all variants removed.');

        return redirect($request->input('return', route('binshopsblog.admin.images.all')));
    }
}
