<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SportsType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SportsTypeController extends Controller
{
    public function __construct(protected SportsType $sportsTypeModel)
    {}

    public function store(Request $request)
    {
        $formData = $request->validate([
            'icon' => 'nullable|string',
            'name' => 'required|max:255|unique:sports_types,name',
        ]);

        $formData['slug'] = Str::slug($formData['name']);

        $type = $this->sportsTypeModel::create($formData);

        return sendSuccess('Sports type added successfully', compact('type'), 201);
    }

    public function update(Request $request, $id)
    {
        $type = $this->sportsTypeModel::findOrFail($id);

        $formData = $request->validate([
            'icon' => 'nullable|string',
            'name' => 'required|string|max:255|unique:sports_types,name,' . $id,
        ]);

        $formData['slug'] = Str::slug($formData['name']);

        $type->update($formData);

        return sendSuccess('Sports type updated successfully', compact('type'));
    }

    public function destroy($id)
    {
        $type = $this->sportsTypeModel::findOrFail($id);
        $type->delete();

        return sendSuccess('Sports type deleted successfully');
    }
}
