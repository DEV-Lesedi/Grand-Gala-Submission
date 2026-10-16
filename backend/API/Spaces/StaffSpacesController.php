<?php

declare(strict_types=1);

namespace GrandGala\API\Spaces;

use GrandGala\Services\SpacesEditor;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class StaffSpacesController
{
    // GET /api/staff/spaces: every space, switched off ones too, with prices and blackouts
    public function list(Request $request): void
    {
        Response::json(SpacesEditor::all());
    }

    // POST /api/staff/spaces { venueId, name, type, capacity, isAccommodation, isHireable }
    // A room also sends parentId. The new space starts switched off.
    public function add(Request $request): void
    {
        Response::json(SpacesEditor::add($request->all()), 201);
    }

    // PATCH /api/staff/spaces/:id: only the fields sent, e.g. { isActive: true }
    public function update(Request $request): void
    {
        Response::json(SpacesEditor::update((int) $request->param('id'), $request->all()));
    }
}
