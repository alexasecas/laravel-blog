<?php

namespace BinshopsBlog\Traits;

use Illuminate\Http\UploadedFile;
use BinshopsBlog\Events\UploadedImage;
use BinshopsBlog\Models\BinshopsBlogPost;
use File;
use Illuminate\Support\Facades\Storage;

trait UploadFileTrait
{
    /** How many tries before we throw an Exception error */
    static $num_of_attempts_to_find_filename = 100;

    /**
     * If false, we check if the blog_images/ dir is writable, when uploading images
     * @var bool
     */
    protected $checked_blog_image_dir_is_writable = false;

    /**
     * Small method to increase memory limit.
     * This can be defined in the config file. If binshopsblog.memory_limit is false/null then it won't do anything.
     * This is needed though because if you upload a large image it'll not work
     */
    protected function increaseMemoryLimit()
    {
        // increase memory - change this setting in config file
        if (config("binshopsblog.memory_limit")) {
            @ini_set('memory_limit', config("binshopsblog.memory_limit"));
        }
    }

    protected function getImageFilename(string $suggested_title, $image_size_details, UploadedFile $photo)
    {
        $base = $this->generate_base_filename($suggested_title);
        $wh   = $this->getWhForFilename($image_size_details);
        $ext  = '.' . $photo->getClientOriginalExtension();

        for ($i = 1; $i <= self::$num_of_attempts_to_find_filename; $i++) {
            $suffix  = $i > 1 ? '-' . str_random(5) : '';
            $attempt = str_slug($base . $suffix . $wh) . $ext;

            $path = $this->imageDir() . '/' . $attempt; // path on disk

            if (!Storage::disk($this->imageDisk())->exists($path)) {
                return $attempt; // keep returning just the filename (BC)
            }
        }

        throw new \RuntimeException("Unable to find a free filename after $i attempts - aborting now.");
    }

    /**
     * @return string
     * @throws \RuntimeException
     */
    protected function image_destination_path()
    {
        return $this->imageDir();
    }

    protected function imageDisk(): string
    {
        return config('binshopsblog.image_disk', 'public');
    }

    protected function imageDir(): string
    {
        $dir = config('binshopsblog.blog_upload_dir', 'blog');
        $dir = str_replace('\\', '/', $dir);    // normalize backslashes → slashes
        return trim($dir, '/');                 // drop leading/trailing slashes
    }

    protected function UploadAndResize(BinshopsBlogPost $new_blog_post = null, $suggested_title, $image_size_details, $photo)
    {
        // Decide encode format based on original extension (fallback to jpg)
        $originalExt   = strtolower($photo->getClientOriginalExtension() ?: 'jpg');
        $encodeFormat  = in_array($originalExt, ['jpg', 'jpeg', 'png', 'webp'], true) ? $originalExt : 'jpg';
        $normalizedExt = $encodeFormat === 'jpeg' ? 'jpg' : $encodeFormat; // unify jpeg → jpg
        $extWithDot    = '.' . $normalizedExt;

        // Build a unique filename (from helper) and normalize the extension to match encode format
        $image_filename = $this->getImageFilename($suggested_title, $image_size_details, $photo);
        $image_filename = preg_replace('/\.[^.]+$/', $extWithDot, $image_filename); // force correct extension

        $disk = $this->imageDisk();          // e.g. 'public' or 'blog' (S3)
        $dir  = $this->imageDir();           // e.g. 'blog'
        $path = ltrim($dir . '/' . $image_filename, '/');

        // Resize with Intervention Image
        // (keeps your existing alias; switch to Image::read if you use Intervention v3)
        $resizedImage = \Image::make($photo->getRealPath());

        if (is_array($image_size_details)) {
            $w = $image_size_details['w'];
            $h = $image_size_details['h'];

            if (!empty($image_size_details['crop'])) {
                $resizedImage = $resizedImage->fit($w, $h);
            } else {
                $resizedImage = $resizedImage->resize($w, $h, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }
        } elseif ($image_size_details === 'fullsize') {
            // No resizing, keep original dimensions
            $w = $resizedImage->width();
            $h = $resizedImage->height();
        } else {
            throw new \Exception("Invalid image_size_details value");
        }

        // Encode and upload to the configured disk
        $quality = (int) config('binshopsblog.image_quality', 80);
        $bytes   = (string) $resizedImage->encode($encodeFormat, $quality);

        \Illuminate\Support\Facades\Storage::disk($disk)->put($path, $bytes, 'public');

        // Fire event hook
        event(new \BinshopsBlog\Events\UploadedImage($image_filename, $resizedImage, $new_blog_post, __METHOD__));

        // Return filename + dimensions (DB stores the filename; URLs are built via Storage::url)
        return [
            'filename' => $image_filename,
            'w' => $w,
            'h' => $h,
        ];
    }

    /**
     * Get the width and height as a string, with x between them
     * (123x456).
     *
     * It will always be prepended with '-'
     *
     * Example return value: -123x456
     *
     * $image_size_details should either be an array with two items ([$width, $height]),
     * or a string.
     *
     * If an array is given:
     * getWhForFilename([123,456]) it will return "-123x456"
     *
     * If a string is given:
     * getWhForFilename("some string") it will return -some-string". (max len: 30)
     *
     * @param array|string $image_size_details
     * @return string
     * @throws \RuntimeException
     */
    protected function getWhForFilename($image_size_details)
    {
        if (is_array($image_size_details)) {
            return '-' . $image_size_details['w'] . 'x' . $image_size_details['h'];
        } elseif (is_string($image_size_details)) {
            return "-" . str_slug(substr($image_size_details, 0, 30));
        }

        // was not a string or array, so error
        throw new \RuntimeException("Invalid image_size_details: must be an array with w and h, or a string");
    }

    /**
     * @param string $suggested_title
     * @return string
     */
    protected function generate_base_filename(string $suggested_title)
    {
        $base = substr($suggested_title, 0, 100);
        if (!$base) {
            // if we have an empty string then we should use a random one:
            $base = 'image-' . str_random(5);
            return $base;
        }
        return $base;
    }

}