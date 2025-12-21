<?php
namespace App\Services;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PostService
{
    public function __construct(protected Post $postModel, protected PostCategory $postCategoryModel, protected Tag $tagModel)
    {}

    public function list($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;

        $posts = $this->postModel->latest()
            ->with(['author', 'category', 'tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                        ->orWhere('slug', 'LIKE', "%{$search}%")
                        ->orWhere('excerpt', 'LIKE', "%{$search}%")
                        ->orWhere('content', 'LIKE', "%{$search}%")
                        ->orWhere('published_at', 'LIKE', "%{$search}%")
                        ->orWhere('seo_title', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->status ?? null, function ($query, $status) {
                $query->where('status', 'LIKE', $status);
            })
            ->when($request->category ?? null, function ($query, $category) {
                $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', 'LIKE', $category);
                });
            })
            ->when($request->author ?? null, function ($query, $author) {
                $query->whereHas('author', function ($q) use ($author) {
                    $q->where('name', 'LIKE', $author);
                });
            })
            ->paginate($records_per_page);

        return sendSuccess('Posts fetched successfully', compact('posts'));
    }

    public function tagsList()
    {
        $tags = Tag::latest()->get();
        return sendSuccess('Tags fetched successfully', compact('tags'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|unique:posts,slug',
            'excerpt'          => 'nullable|string|max:500',
            'content'          => 'nullable|string',
            'read_time'        => 'required|string',
            'status'           => 'required|in:draft,published',
            'post_category_id' => 'nullable|exists:post_categories,id',
            'featured_image'   => 'nullable|image|max:5120',
            'image_url'        => 'nullable|url',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'seo_title'        => 'nullable|string|max:255',
            '5 min read'       => 'nullable|string|max:50',
            'seo_description'  => 'nullable|string|max:160',
            'featured'         => 'nullable',
        ]);

        try {
            DB::beginTransaction();

            if ($formData['status'] == $this->postModel::PUBLISHED) {
                $formData['published_at'] = now();
            }

            if (! empty($formData['featured'])) {
                $formData['featured'] = true;
                $this->postModel->update(['featured' => false]);

            } else {
                $formData['featured'] = false;
            }

            if (empty($formData['slug'])) {
                $formData['slug'] = Str::slug($formData['title']);
            }

            if ($request->hasFile('featured_image')) {
                $formData['featured_image'] = $request->file('featured_image')->store('posts', 'public');
            } elseif ($request->filled('image_url')) {
                $formData['featured_image'] = $request->input('image_url');
            }

            $formData['user_id'] = $request->user()->id;

            if (! empty($formData['tags'])) {
                $tags = $formData['tags'];
                unset($formData['tags']);
            }

            $post = $this->postModel::create($formData);

            if (! empty($tags)) {
                $tagIds = collect($tags)->map(function ($tag) {
                    return $this->tagModel::firstOrCreate(
                        ['slug' => Str::slug($tag)],
                        ['name' => $tag]
                    )->id;
                });
                $post->tags()->sync($tagIds);
            }

            DB::commit();

            $post->load('tags', 'category');

            return sendSuccess('Post created successfully', compact('post'), 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Creating Blog Post Error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong.");
        }
    }

    public function getPost($id)
    {
        $post = $this->postModel::with(['author', 'category', 'tags'])->findOrFail($id);
        return sendSuccess('Post fetched successfully', compact('post'));
    }

    public function update($request, $id)
    {
        $post = $this->postModel->findOrFail($id);

        $formData = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|unique:posts,slug,' . $post->id,
            'excerpt'          => 'nullable|string|max:500',
            'content'          => 'nullable|string',
            'read_time'        => 'required|string',
            'status'           => 'required|in:draft,published',
            'post_category_id' => 'nullable|exists:post_categories,id',
            'featured_image'   => [
                'nullable',
                File::types([
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/bmp',
                    'image/webp',
                    'image/avif',
                ])->max(5120 * 1024),
            ],
            'image_url'        => 'nullable|url',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'seo_title'        => 'nullable|string|max:255',
            'seo_description'  => 'nullable|string|max:160',
            'featured'         => 'nullable',
        ]);

        try {
            DB::beginTransaction();

            if ($formData['status'] == $this->postModel::PUBLISHED && $post->published_at == null) {
                $formData['published_at'] = now();
            }

            if (! empty($formData['featured'])) {
                $formData['featured'] = true;
                $this->postModel->update(['featured' => false]);

            } else {
                $formData['featured'] = false;
            }

            if (empty($formData['slug'])) {
                $formData['slug'] = Str::slug($formData['title']);
            }

            if ($request->hasFile('featured_image')) {
                $formData['featured_image'] = $request->file('featured_image')->store('posts', 'public');
            } elseif ($request->filled('image_url')) {
                $formData['featured_image'] = $request->input('image_url');
            } else {
                unset($formData['featured_image']);
            }

            $tags = $formData['tags'] ?? null;
            unset($formData['tags']);

            $post->update($formData);

            if (! empty($tags)) {
                $tagIds = collect($tags)->map(function ($tag) {
                    return $this->tagModel::firstOrCreate(
                        ['slug' => Str::slug($tag)],
                        ['name' => $tag]
                    )->id;
                });
                $post->tags()->sync($tagIds);
            }

            $post->refresh();

            DB::commit();

            return sendSuccess('Post updated successfully', compact('post'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Updating Blog Post Error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong.");
        }
    }

    public function delete($id)
    {
        $post = $this->postModel->findOrFail($id);
        $post->delete();
        return sendSuccess('Post deleted successfully');
    }

    public function changeStatus($request)
    {
        $request->validate([
            'id'     => 'required|numeric|exists:posts',
            'status' => 'required|string|in:draft,published',
        ]);

        $post = $this->postModel::findOrFail($request->id);

        if ($request->status == $this->postModel::PUBLISHED) {
            $post->status       = $this->postModel::PUBLISHED;
            $post->published_at = now();
            $post->save();
        } elseif ($request->status == $this->postModel::DRAFT) {
            $post->published_at = null;
            $post->status       = $this->postModel::DRAFT;
            $post->save();
        }

        return sendSuccess('Post status updated successfully', compact('post'));
    }

    public function blogArticles($request)
    {
        $search           = $request->search ?? null;
        $category         = $request->category ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $blogArticles = $this->postModel->where('status', Post::PUBLISHED)
            ->with(['author', 'category', 'tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                        ->orWhere('slug', 'LIKE', "%{$search}%")
                        ->orWhere('excerpt', 'LIKE', "%{$search}%")
                        ->orWhere('content', 'LIKE', "%{$search}%")
                        ->orWhere('seo_title', 'LIKE', "%{$search}%")
                        ->orWhere('published_at', 'LIKE', "%{$search}%");
                });
            })
            ->when($category, function ($query, $category) {
                $query->whereRelation('category', 'name', 'LIKE', $category);
            })
            ->simplePaginate($records_per_page);

        $featuredArticle = $this->postModel::with(['author', 'category', 'tags'])
            ->where('featured', 1)->first();

        return sendSuccess('Blog articles fetched successfully', compact('blogArticles', 'featuredArticle'));
    }

    public function getPostBySlug($slug)
    {
        $post = $this->postModel->where('slug', $slug)
            ->where('status', Post::PUBLISHED)
            ->with(['author', 'category', 'tags'])
            ->first();

        if (! $post) {
            return sendError('Post not found', [], 404);
        }

        return sendSuccess('Post fetched successfully', compact('post'));
    }
}
