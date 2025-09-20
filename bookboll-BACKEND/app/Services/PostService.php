<?php
namespace App\Services;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
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

        $categories = $this->postCategoryModel::latest()->get();

        return sendSuccess('Posts fetched successfully', compact('posts', 'categories'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|unique:posts,slug',
            'excerpt'          => 'nullable|string|max:500',
            'content'          => 'nullable|string',
            'status'           => 'required|in:draft,published',
            'post_category_id' => 'nullable|exists:post_categories,id',
            'featured_image'   => 'nullable|image|max:5120',
            'image_url'        => 'nullable|url',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'seo_title'        => 'nullable|string|max:255',
            'seo_description'  => 'nullable|string|max:160',
        ]);

        if ($formData['status'] == $this->postModel::PUBLISHED) {
            $formData['published_at'] = now();
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

        $tags = $formData['tags'];
        unset($formData['tags']);

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

        $post->load('tags', 'category');

        return sendSuccess('Post created successfully', compact('post'), 201);
    }

    public function update($request, $id)
    {
        $post = $this->postModel->findOrFail($id);

        $formData = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|unique:posts,slug,' . $post->id,
            'excerpt'          => 'nullable|string|max:500',
            'content'          => 'nullable|string',
            'status'           => 'required|in:draft,published',
            'post_category_id' => 'nullable|exists:post_categories,id',
            'featured_image'   => 'nullable|image|max:5120',
            'image_url'        => 'nullable|url',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'seo_title'        => 'nullable|string|max:255',
            'seo_description'  => 'nullable|string|max:160',
        ]);

        if ($formData['status'] == $this->postModel::PUBLISHED && $post->published_at == null) {
            $formData['published_at'] = now();
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

        return sendSuccess('Post updated successfully', compact('post'));
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
}
