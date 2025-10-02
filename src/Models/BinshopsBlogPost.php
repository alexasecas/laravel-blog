<?php

namespace BinshopsBlog\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Swis\Laravel\Fulltext\Indexable;
use BinshopsBlog\Interfaces\SearchResultInterface;
use BinshopsBlog\Scopes\BinshopsBlogPublishedScope;
use Illuminate\Support\Facades\Storage;

/**
 * Class BinshopsBlogPost
 * @package BinshopsBlog\Models
 */
class BinshopsBlogPost extends Model implements SearchResultInterface
{

    use Sluggable;
    use Indexable;

    protected $indexContentColumns = ['post_body', 'short_description', 'meta_desc',];
    protected $indexTitleColumns = ['title', 'subtitle', 'seo_title',];

    /**
     * @var array
     */
    public $casts = [
        'is_published' => 'boolean',
        'posted_at' => 'date'
    ];

    /**
     * @var array
     */
    public $dates = [
        'posted_at'
    ];

    /**
     * @var array
     */
    public $fillable = [

        'title',
        'subtitle',
        'short_description',
        'post_body',
        'seo_title',
        'meta_desc',
        'slug',
        'use_view_file',

        'is_published',
        'posted_at',
    ];

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'title'
            ]
        ];
    }

    public function search_result_page_url()
    {
        return $this->url();
    }

    public function search_result_page_title()
    {
        return $this->title;
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        /* If user is logged in and \Auth::user()->canManageBinshopsBlogPosts() == true, show any/all posts.
           otherwise (which will be for most users) it should only show published posts that have a posted_at
           time <= Carbon::now(). This sets it up: */
        static::addGlobalScope(new BinshopsBlogPublishedScope());
    }

    /**
     * The associated author (if user_id) is set
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author()
    {
        return $this->belongsTo(config("binshopsblog.user_model"), 'user_id');
    }

    /**
     * Return author string (either from the User (via ->user_id), or the submitted author_name value
     * @return string
     */
    public function author_string()
    {
        if ($this->author) {
            return optional($this->author)->name;
        } else {
            return 'Unknown Author';
        }
    }

    /**
     * The associated categories for this blog post
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function categories()
    {
        return $this->belongsToMany(BinshopsBlogCategory::class, 'binshops_blog_post_categories');
    }

    /**
     * Comments for this post
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany(BinshopsBlogComment::class);
    }

    /**
     * Returns the public facing URL to view this blog post
     *
     * @return string
     */
    public function url()
    {
        return route("binshopsblog.single", $this->slug);
    }

    /**
     * Return the URL for editing the post (used for admin users)
     * @return string
     */
    public function edit_url()
    {
        return route("binshopsblog.admin.edit_post", $this->id);
    }

    /**
     * If $this->user_view_file is not empty, then it'll return the dot syntax location of the blade file it should look for
     * @return string
     * @throws \Exception
     */
    public function full_view_file_path()
    {
        if (!$this->use_view_file) {
            throw new \RuntimeException("use_view_file was empty, so cannot use " . __METHOD__);
        }
        return "custom_blog_posts." . $this->use_view_file;
    }

    /**
     * Does this object have an uploaded image of that size...?
     *
     * @param string $size
     * @return int
     */
    public function has_image($size = 'medium')
    {
        $this->check_valid_image_size($size);
        return strlen($this->{"image_" . $size});
    }

    public function imageUrl(string $sizeBasicKey = 'medium'): ?string
    {
        $column   = str_starts_with($sizeBasicKey, 'image_') ? $sizeBasicKey : 'image_'.$sizeBasicKey;
        $filename = $this->{$column} ?? null;
        if (!$filename) return null;

        $disk = config('binshopsblog.image_disk', 'public');
        $dir  = trim(str_replace('\\', '/', config('binshopsblog.blog_upload_dir', 'blog')), '/');

        // Build normalized key (no leading slash, no backslashes)
        $key = ltrim($dir . '/' . ltrim(str_replace('\\', '/', $filename), '/'), '/');

        return \Storage::disk($disk)->url($key);
    }

    public function image_tag(string $sizeBasicKey = 'medium', bool $lazy = true, string $class = ''): string
    {
        $url = $this->imageUrl($sizeBasicKey);
        if (!$url) return '';

        $attr = $class ? ' class="'.e($class).'"' : '';
        $loading = $lazy ? ' loading="lazy"' : '';
        return '<img src="'.e($url).'" alt="'.e($this->title ?? '').'"'.$attr.$loading.' />';
    }

    public function generate_introduction($max_len = 500)
    {
        $base_text_to_use = $this->short_description;
        if (!trim($base_text_to_use)) {
            $base_text_to_use = $this->post_body;
        }
        $base_text_to_use = strip_tags($base_text_to_use);

        $intro = str_limit($base_text_to_use, (int)$max_len);
        return nl2br(e($intro));
    }

    public function post_body_output()
    {
        if (config("binshopsblog.use_custom_view_files") && $this->use_view_file) {
            // using custom view files is enabled, and this post has a use_view_file set, so render it:
            $return = view("binshopsblog::partials.use_view_file", ['post' => $this])->render();
        } else {
            // just use the plain ->post_body
            $return = $this->post_body;
        }


        if (!config("binshopsblog.echo_html")) {
            // if this is not true, then we should escape the output
            if (config("binshopsblog.strip_html")) {
                $return = strip_tags($return);
            }

            $return = e($return);
            if (config("binshopsblog.auto_nl2br")) {
                $return = nl2br($return);
            }
        }

        return $return;
    }

    /**
     * Throws an exception if $size is not valid
     * It should be either 'large','medium','thumbnail'
     * @param string $size
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected function check_valid_image_size(string $size = 'medium')
    {
        if (array_key_exists("image_" . $size, config("binshopsblog.image_sizes"))) {
            return true;
        }

        // was an error!

        if (starts_with($size, "image_")) {
            // $size starts with image_, which is an error
            /* the config/binshopsblog.php and the DB columns SHOULD have keys that start with image_$size
            however when using methods such as image_url() or has_image() it SHOULD NOT start with 'image_'

            To put another way: :
                in the config/binshopsblog.php : config("binshopsblog.image_sizes.image_medium")
                in the database table:    : BinshopsBlog_posts.image_medium
                when calling image_url()  : image_url("medium")
            */
            throw new \InvalidArgumentException("Invalid image size ($size). BinshopsBlogPost image size should not begin with 'image_'. Remove this from the start of $size. It *should* be in the binshopsblog.image_sizes config though!");
        }


        throw new \InvalidArgumentException("BinshopsBlogPost image size should be 'large','medium','thumbnail' or another field as defined in config/binshopsblog.php. Provided size ($size) is not valid");
    }

    /**
     *
     * If $this->seo_title was set, return that.
     * Otherwise just return $this->title
     *
     * Basically return $this->seo_title ?? $this->title;
     * @return string
     */
    public function gen_seo_title()
    {
        if ($this->seo_title) {
            return $this->seo_title;
        }
        return $this->title;
    }
}
