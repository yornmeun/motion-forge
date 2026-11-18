<?php

namespace App\Http\Controllers;

use App\Models\BackgroundAsset;
use App\Models\ProjectFile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssetController extends Controller
{
    public function index(Request $request): View
    {
        $ids = $request->user()->projects()->pluck('id');

        return view('library.assets', [
            'files' => ProjectFile::with('project')->whereIn('project_id', $ids)->when($request->string('role')->toString(), fn ($q, $r) => $q->where('role', $r))->latest()->paginate(30)->withQueryString(),
            'backgrounds' => BackgroundAsset::orderBy('name')->get(),
            'roles' => ProjectFile::whereIn('project_id', $ids)->distinct()->pluck('role'),
        ]);
    }
}
