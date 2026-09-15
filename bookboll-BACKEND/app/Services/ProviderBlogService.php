<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Tag;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A provider's OWN site blog (My Website → Blog), as opposed to the Bookboll HQ blog.
 *
 * Both live in `posts`; `owner_user_id` is what separates them — set for a provider's
 * post, NULL for a Bookboll one. That column is filled from the session here, never
 * from the request, so a provider can neither publish onto the marketplace blog nor
 * read or edit another provider's posts.
 *
 * The wire shape deliberately matches /admin/posts so the dashboard's existing post
 * mappers (lib/blogApi.js) work for both blogs unchanged.
 */
class ProviderBlogService
{
    public function index(Request $request)
    {
        $ownerId = $this->ownerOrNull($request);
        if ($ownerId === null) {
            return sendError('Only a provider account has its own site blog.', [], 403);
        }

        $posts = Post::query()
            ->with('tags:id,name')   // map() reads them; without this it is 200 queries
            ->where('owner_user_id', $ownerId)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Post $p) => $this->map($p))
            ->values();

        return sendSuccess('Posts fetched successfully', ['posts' => $posts]);
    }

    /** The published count behind the storefront's Blog-module visibility. */
    public function publishedCount(Request $request)
    {
        $ownerId = $this->ownerOrNull($request);

        return sendSuccess('Published posts', [
            'count' => $ownerId === null ? 0 : Post::where('owner_user_id', $ownerId)->where('status', 'published')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $ownerId = $this->ownerOrNull($request);
        if ($ownerId === null) {
            return sendError('Only a provider account has its own site blog.', [], 403);
        }

        $data = $this->validated($request);
        $post = Post::create($this->attributes($data, $request) + [
            'owner_user_id' => $ownerId,
            'user_id'       => $request->user()?->id,
            'created_by'    => $request->user()?->id,
            'slug'          => $this->uniqueSlug($data['slug'] ?? $data['title'], null),
        ]);
        $this->syncTags($post, $data['tags'] ?? []);

        return sendSuccess('Post created successfully', ['post' => $this->map($post->fresh())], 201);
    }

    public function update(Request $request, $id)
    {
        $post = $this->ownedPost($request, $id);
        if (! $post) {
            return sendError('Post not found.', [], 404);
        }

        $data = $this->validated($request);
        $attrs = $this->attributes($data, $request, $post);
        if (! empty($data['slug']) && $data['slug'] !== $post->slug) {
            $attrs['slug'] = $this->uniqueSlug($data['slug'], $post->id);
        }
        $post->update($attrs);
        $this->syncTags($post, array_key_exists('tags', $data) ? ($data['tags'] ?? []) : null);

        return sendSuccess('Post updated successfully', ['post' => $this->map($post->fresh())]);
    }

    public function destroy(Request $request, $id)
    {
        $post = $this->ownedPost($request, $id);
        if (! $post) {
            return sendError('Post not found.', [], 404);
        }

        $post->delete();

        return sendSuccess('Post deleted successfully', ['ok' => true]);
    }

    /* ─────────────────────────────── internals ────────────────────────────── */

    protected function validated(Request $request): array
    {
        return $request->validate([
            'title'           => 'required|string|max:191',
            'slug'            => 'nullable|string|max:191',
            'excerpt'         => 'nullable|string',
            'content'         => 'nullable|string',
            'status'          => 'nullable|string|in:draft,published',
            'tags'            => 'nullable|array',
            'tags.*'          => 'string|max:60',
            'seo_title'       => 'nullable|string|max:191',
            'seo_description' => 'nullable|string|max:500',
            'image_url'       => 'nullable|string|max:2048',
            'read_time'       => 'nullable|string|max:32',
        ]);
    }

    /**
     * The post's tag NAMES.
     *
     * `$post->tags` cannot be used: `posts.tags` is a legacy text column, and an
     * attribute always shadows a relation of the same name in Eloquent — so that
     * returns the (empty) column, never the related rows. Read the relation
     * explicitly, preferring an eager-loaded copy so a list is not N+1.
     *
     * @return string[]
     */
    protected function tagNames(Post $post): array
    {
        $rel = $post->relationLoaded('tags') ? $post->getRelation('tags') : $post->tags()->get();

        return $rel->pluck('name')->values()->all();
    }

    /**
     * Attach the submitted tag names to the post, creating any that are new.
     * Passing an empty list clears them, so removing a tag in the editor sticks.
     */
    protected function syncTags(Post $post, ?array $tags): void
    {
        if ($tags === null) {
            return;   // field not submitted at all — leave what is there
        }

        $ids = collect($tags)
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->unique()
            ->map(fn ($t) => Tag::firstOrCreate(['slug' => Str::slug($t)], ['name' => $t])->id)
            ->all();

        $post->tags()->sync($ids);
    }

    /**
     * Column values only.
     *
     * `tags` is deliberately absent: Post::tags() is a belongsToMany to the tags
     * table, so putting the submitted array in here made Eloquent try to bind an
     * array to the legacy `posts.tags` text column — "Array to string conversion",
     * and no post could be saved. syncTags() puts them where they belong, which is
     * also what the platform blog has always done.
     *
     * @return array<string, mixed>
     */
    protected function attributes(array $data, Request $request, ?Post $existing = null): array
    {
        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        $attrs = [
            'title'           => trim($data['title']),
            'excerpt'         => $data['excerpt'] ?? '',
            'content'         => $data['content'] ?? '',
            'status'          => $status,
            'seo_title'       => $data['seo_title'] ?? '',
            'seo_description' => $data['seo_description'] ?? '',
            'read_time'       => $data['read_time'] ?? '5 min read',
            // `posts.type` is enum('main','business') — 'provider' was never one of
            // them, so MySQL truncated it and refused the row. What separates a
            // provider's blog from Bookboll's is owner_user_id (see scopeForOwner);
            // type just says which KIND of blog, and a business site's is 'business'.
            'type'            => Post::TYPE_BUSINESS,
            'featured'        => false,
        ];

        if (array_key_exists('image_url', $data)) {
            $attrs['featured_image'] = $data['image_url'] ?: null;
        }

        // Stamped the first time it goes live, and cleared if it returns to draft —
        // so "published on" always means what it says.
        if ($status === 'published') {
            $attrs['published_at'] = $existing?->published_at ?: now();
        } else {
            $attrs['published_at'] = null;
        }

        return $attrs;
    }

    protected function ownedPost(Request $request, $id): ?Post
    {
        $ownerId = $this->ownerOrNull($request);

        return $ownerId === null
            ? null
            : Post::where('owner_user_id', $ownerId)->whereKey($id)->first();
    }

    protected function ownerOrNull(Request $request): ?int
    {
        return AccessScope::ownerUserId($request->user());
    }

    /** Slugs are the post's public address, so they have to stay unique. */
    protected function uniqueSlug(?string $source, $ignoreId): string
    {
        $base = Str::slug((string) $source) ?: 'post';
        $base = Str::limit($base, 80, '');
        $slug = $base;
        $n = 2;
        while (Post::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    protected function map(Post $p): array
    {
        return [
            'id'              => $p->id,
            'title'           => $p->title,
            'slug'            => $p->slug,
            'excerpt'         => $p->excerpt,
            'content'         => $p->content,
            'featured_image'  => $p->featured_image,
            'status'          => $p->status,
            'tags'            => $this->tagNames($p),
            'seo_title'       => $p->seo_title,
            'seo_description' => $p->seo_description,
            'read_time'       => $p->read_time,
            'type'            => $p->type,
            'published_at'    => optional($p->published_at)?->toIso8601String(),
            'created_at'      => optional($p->created_at)?->toIso8601String(),
            'updated_at'      => optional($p->updated_at)?->toIso8601String(),
        ];
    }
}
