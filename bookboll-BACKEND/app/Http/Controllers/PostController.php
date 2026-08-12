<?php
namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use App\Services\PostService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PostController extends Controller
{
    public function __construct(protected PostService $postService){}

    public function index(Request $request)
    {
        return $this->postService->list($request);
    }

    public function postTagsList()
    {
        return $this->postService->tagsList();
    }
    public function store(Request $request)
    {
       return $this->postService->create($request);
    }

    public function show($id)
    {
        return $this->postService->getPost($id);
    }

    public function update(Request $request, $id)
    {
        return $this->postService->update($request, $id);
    }

    public function changeStatus(Request $request)
    {
        return $this->postService->changeStatus($request);
    }

    public function destroy($id)
    {
        return $this->postService->delete($id);
    }

    public function blogArticles(Request $request)
    {
        return $this->postService->blogArticles($request);
    }

    public function blogPostDetails(Request $request, $slug)
    {
        return $this->postService->getPostBySlug($slug, $request);
    }
}
