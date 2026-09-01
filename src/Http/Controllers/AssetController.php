<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\Support\AssetManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the package's JS/CSS directly from the package directory.
 *
 * This removes the "php artisan vendor:publish" step from installation. The
 * file name is matched against a fixed allowlist, so this can never be used to
 * read arbitrary paths.
 */
class AssetController extends Controller
{
    public function __invoke(
        Request $request,
        AssetManager $assets,
        string $version,
        ?string $file = null,
    ): BinaryFileResponse|Response {
        // The legacy route has no version segment, so the first parameter is
        // the file name.
        if ($file === null) {
            [$version, $file] = [null, $version];
        }

        $path = $assets->pathFor($file);

        abort_if($path === null, 404, 'Unknown asset.');

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $assets->mimeFor($file),
        ]);

        $response->setPublic();
        $response->setAutoEtag();
        $response->setAutoLastModified();

        // A versioned path is uniquely addressed, so it can be cached forever.
        // The legacy URL is not, so it must revalidate — caching it immutably
        // is how a deploy ends up serving stale modules beside a fresh core.
        if ($version !== null) {
            $response->setMaxAge(31536000);
            $response->setImmutable();
        } else {
            $response->setMaxAge(0);
            $response->headers->addCacheControlDirective('must-revalidate');
        }

        $response->isNotModified($request);

        return $response;
    }
}
