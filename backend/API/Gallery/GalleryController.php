<?php

declare(strict_types=1);

namespace GrandGala\API\Gallery;

use GrandGala\Models\GalleryItem;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// No auth middleware on this route.
class GalleryController
{
    // GET /api/gallery?category= chip label, or omit for All.
    public function list(Request $request): void
    {
        $category = $request->queryText('category');
        Response::json(GalleryItem::active($category === '' ? null : $category));
    }
}
