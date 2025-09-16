<?php
namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $categories       = PostCategory::latest()->get();
        $posts            = Post::with(['category', 'user', 'tags'])->latest()
            ->filter($request->only(['search']))->paginate($records_per_page);

        return sendSuccess('Posts fetched successfully', compact('posts'));
    }
    public function store(Request $request)
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

        if ($formData['status'] == Post::PUBLISHED) {
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

        $post = Post::create($formData);

        if (! empty($tags)) {
            $tagIds = collect($tags)->map(function ($tag) {
                return Tag::firstOrCreate(
                    ['slug' => Str::slug($tag)],
                    ['name' => $tag]
                )->id;
            });
            $post->tags()->sync($tagIds);
        }

        $post->load('tags', 'category');

        return sendSuccess('Post created successfully', compact('post'), 201);
    }

    public function update(Request $request, Post $post)
    {
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

        if ($formData['status'] == Post::PUBLISHED && $post->published_at == null) {
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
                return Tag::firstOrCreate(
                    ['slug' => Str::slug($tag)],
                    ['name' => $tag]
                )->id;
            });
            $post->tags()->sync($tagIds);
        }

        return sendSuccess('Post updated successfully', compact('post'));
    }

    public function changeStatus(Request $request)
    {
        $request->validate([
            'id'     => 'required|numeric|exists:posts',
            'status' => 'required|string|in:draft,published',
        ]);

        $post = Post::findOrFail($request->id);

        if ($request->status == Post::PUBLISHED) {
            $post->status       = Post::PUBLISHED;
            $post->published_at = now();
            $post->save();
        } elseif ($request->status == Post::DRAFT) {
            $post->published_at = null;
            $post->status       = Post::DRAFT;
            $post->save();
        }

        return sendSuccess('Post status updated successfully', compact('post'));
    }

    public function destroy(Post $post)
    {
        $post->delete();
        return sendSuccess('Post deleted successfully');
    }
}
