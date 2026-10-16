<?php

declare(strict_types=1);

namespace GrandGala\API\Media;

use GrandGala\Services\MediaUploads;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

class MediaController
{
    // POST /api/staff/spaces/:id/media: file, caption, altText, isPrimary (multipart).
    public function uploadForSpace(Request $request): void
    {
        $this->upload('space', $this->idFromPath($request, 'Space not found'));
    }

    // POST /api/staff/events/:id/media (same form as for a space)
    public function uploadForEvent(Request $request): void
    {
        $this->upload('event', $this->idFromPath($request, 'Event not found'));
    }

    // GET /api/staff/spaces/:id/media
    public function listForSpace(Request $request): void
    {
        Response::json(MediaUploads::listFor('space', $this->idFromPath($request, 'Space not found')));
    }

    // GET /api/staff/events/:id/media
    public function listForEvent(Request $request): void
    {
        Response::json(MediaUploads::listFor('event', $this->idFromPath($request, 'Event not found')));
    }

    // PATCH /api/staff/media/:id { caption, altText, isPrimary, sortOrder }
    public function update(Request $request): void
    {
        $mediaId = $this->idFromPath($request, 'Photo or video not found');
        Response::json(MediaUploads::update($mediaId, $request->all()));
    }

    // DELETE /api/staff/media/:id
    public function delete(Request $request): void
    {
        $mediaId = $this->idFromPath($request, 'Photo or video not found');
        MediaUploads::delete($mediaId);

        Response::json(['deleted' => $mediaId]);
    }

    private function upload(string $ownerType, int $ownerId): void
    {
        $tmpPath = '';
        $size = 0;
        $uploadError = UPLOAD_ERR_NO_FILE;

        $file = $_FILES['file'] ?? null;
        if (is_array($file)) {
            // "file[]" sends a list of files. One file per request keeps errors clear.
            if (is_array($file['error'] ?? null)) {
                throw new HttpError(422, 'Send one file at a time');
            }
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $size = (int) ($file['size'] ?? 0);
            // Only a path PHP received for this upload, never another file on the server.
            $path = (string) ($file['tmp_name'] ?? '');
            if ($uploadError === UPLOAD_ERR_OK && $path !== '' && MediaUploads::isRealUpload($path)) {
                $tmpPath = $path;
            }
        } elseif ($this->formWasThrownAway()) {
            $uploadError = UPLOAD_ERR_INI_SIZE;
        }

        $media = MediaUploads::upload(
            $ownerType,
            $ownerId,
            $tmpPath,
            $size,
            $uploadError,
            $_POST['caption'] ?? null,
            $_POST['altText'] ?? null,
            $this->isPrimaryFromForm(),
        );

        Response::json($media, 201);
    }

    // Over post_max_size, PHP empties $_FILES and $_POST: too big, not missing.
    private function formWasThrownAway(): bool
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        return $length > 0 && count($_FILES) === 0 && count($_POST) === 0;
    }

    // isPrimary arrives as text ("true"/"false" or "1"/"0"). Missing means false.
    private function isPrimaryFromForm(): bool
    {
        $value = $_POST['isPrimary'] ?? 'false';
        if ($value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === '0' || $value === '') {
            return false;
        }

        throw new HttpError(422, 'isPrimary must be true or false');
    }

    // Whole-number :id only; anything else is not found.
    private function idFromPath(Request $request, string $notFound): int
    {
        $id = (string) $request->param('id');
        if (!ctype_digit($id)) {
            throw new HttpError(404, $notFound);
        }

        return (int) $id;
    }
}
