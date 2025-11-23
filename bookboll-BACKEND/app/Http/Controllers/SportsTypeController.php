<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SportsType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SportsTypeController extends Controller
{
    public function __construct(protected SportsType $sportsTypeModel)
    {}

    public function store(Request $request)
    {
        $formData = $request->validate([
            'icon'      => 'nullable|string',
            'color'     => 'required|string',
            'sub_title' => 'required|max:255',
            'title'     => 'required|max:255|unique:sports_types',
        ]);

        $formData['slug'] = Str::slug($formData['title']);

        $type = $this->sportsTypeModel::create($formData);

        return sendSuccess('Sports type added successfully', compact('type'), 201);
    }

    public function update(Request $request, $id)
    {
        $type = $this->sportsTypeModel::findOrFail($id);

        $formData = $request->validate([
            'icon'      => 'nullable|string',
            'color'     => 'required|string',
            'sub_title' => 'required|max:255',
            'title'     => 'required|string|max:255|unique:sports_types,title,' . $id,
        ]);

        $formData['slug'] = Str::slug($formData['title']);

        $type->update($formData);

        return sendSuccess('Sports type updated successfully', compact('type'));
    }

    public function destroy($id)
    {
        $type = $this->sportsTypeModel::findOrFail($id);
        $type->delete();
        return sendSuccess('Sports type deleted successfully');
    }

    public function sort(Request $request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:sports_types,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates = $request->input('updates');
        $typeIds = collect($updates)->pluck('id')->toArray();

        $sportsTypes = $this->sportsTypeModel::whereIn('id', $typeIds)->get(['id']);

        if ($sportsTypes->isEmpty()) {
            return sendError('No sports types found.');
        }

        if ($sportsTypes->count() !== count($typeIds)) {
            return sendError('Some sports types not found.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->sort()->values()->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders) || $sortOrders[0] !== 1 || end($sortOrders) !== count($sortOrders)) {
            return sendError('Sort orders must be unique, sequential integers starting from 1.');
        }

        DB::beginTransaction();

        try {
            foreach ($updates as $update) {
                $this->sportsTypeModel::where('id', $update['id'])
                    ->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', [
                'data' => ['updated_sports_types' => $typeIds],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sort order: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to update sort order.');
        }
    }
}
