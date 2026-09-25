<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\SignatureAssetResource;
use App\Services\Signatures\SignatureAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SignatureAssetController extends Controller
{
    public function __construct(private readonly SignatureAssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        return SignatureAssetResource::collection($this->assets->listOwn($request->user()))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file']]);
        $asset = $this->assets->upload($request->user(), $request->file('image'));

        return (new SignatureAssetResource($asset))->response()->setStatusCode(201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $asset): JsonResponse
    {
        return (new SignatureAssetResource($this->assets->own($request->user(), $asset)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $asset): Response
    {
        $bytes = $this->assets->preview($request->user(), $asset);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="signature.png"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function retire(Request $request, string $asset): JsonResponse
    {
        $this->assets->own($request->user(), $asset);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return (new SignatureAssetResource($this->assets->retire($request->user(), $asset, $validated['reason'] ?? null)))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
