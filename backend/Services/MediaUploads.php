<?php

declare(strict_types=1);

namespace GrandGala\Services;

use finfo;
use GrandGala\Models\Media;
use GrandGala\Models\PublicEvent;
use GrandGala\Models\Space;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use Throwable;

/*
 * Photos and videos. Type comes from the bytes, never the name or Content-Type.
 * Names are random, so a guest filename cannot escape the folder. A video is never the main photo.
 */
class MediaUploads
{
    // Detected type => file extension we save it with
    private const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const VIDEO_TYPES = ['video/mp4' => 'mp4'];

    private const PHOTO_MAX_BYTES = 5242880;  // 5 MB
    private const VIDEO_MAX_BYTES = 52428800; // 50 MB

    // Owner type => its folder inside the uploads folder
    private const FOLDERS = ['space' => 'spaces', 'event' => 'events'];

    private const MAX_TEXT_LENGTH = 255;   // m_caption and m_alt_text are varchar(255)
    private const MAX_SORT_ORDER = 65535;  // m_sort_order is a smallint unsigned

    // Tests copy a file they made. Production never turns this on: PHP only moves real uploads.
    private static bool $copyForTests = false;

    // Saves an upload. The controller must already have checked it is a real upload.
    public static function upload(
        string $ownerType,
        int $ownerId,
        string $tmpPath,
        int $size,
        int $uploadError,
        mixed $caption,
        mixed $altText,
        bool $isPrimary
    ): array {
        self::checkOwnerExists($ownerType, $ownerId);
        self::checkUploadError($uploadError);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new HttpError(422, 'Choose a file to upload');
        }
        // Use the bigger of PHP's size and the real size, so neither can sneak past the limit
        $realSize = (int) filesize($tmpPath);
        if ($realSize > $size) {
            $size = $realSize;
        }
        if ($size === 0) {
            throw new HttpError(422, 'The file is empty');
        }

        $type = self::detectType($tmpPath);
        $extension = '';
        $isVideo = false;
        if (isset(self::PHOTO_TYPES[$type])) {
            $extension = self::PHOTO_TYPES[$type];
            if ($size > self::PHOTO_MAX_BYTES) {
                throw new HttpError(422, 'A photo can be up to 5 MB');
            }
        } elseif (isset(self::VIDEO_TYPES[$type])) {
            $extension = self::VIDEO_TYPES[$type];
            $isVideo = true;
            if ($size > self::VIDEO_MAX_BYTES) {
                throw new HttpError(422, 'A video can be up to 50 MB');
            }
        } else {
            throw new HttpError(422, 'Only JPEG, PNG or WebP photos and MP4 videos can be uploaded');
        }

        $caption = self::checkText($caption, 'The caption');
        $altText = self::checkText($altText, 'The alt text');
        if ($isPrimary && $isVideo) {
            throw new HttpError(422, 'Only a photo can be the main photo');
        }

        // 32 random hex characters. Nothing from the guest goes in the path.
        $folder = self::FOLDERS[$ownerType];
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = self::saveFile($tmpPath, $folder, $name);
        $url = '/uploads/' . $folder . '/' . $name;

        // Save the row. If that fails, remove the file again so no orphan file is left behind.
        try {
            $mediaId = Db::transaction(function () use ($ownerType, $ownerId, $url, $caption, $altText, $isPrimary, $isVideo) {
                self::lockOwner($ownerType, $ownerId);

                // The first photo becomes the main one, so the catalogue has something to show.
                $makePrimary = $isPrimary;
                if (!$isVideo && !Media::hasPrimary($ownerType, $ownerId)) {
                    $makePrimary = true;
                }
                // Only one main photo per owner: take the mark off the old one first
                if ($makePrimary) {
                    Media::clearPrimary($ownerType, $ownerId);
                }

                $sortOrder = Media::nextSortOrder($ownerType, $ownerId);
                if ($sortOrder > self::MAX_SORT_ORDER) {
                    $sortOrder = self::MAX_SORT_ORDER;
                }

                return Media::insert($ownerType, $ownerId, $url, $caption, $altText, $makePrimary, $sortOrder);
            });
        } catch (Throwable $error) {
            unlink($destination);
            throw $error;
        }

        return Media::find($mediaId);
    }

    // A space's or public event's photos and videos: the main photo first, then by sort order
    public static function listFor(string $ownerType, int $ownerId): array
    {
        self::checkOwnerExists($ownerType, $ownerId);

        return Media::forOwner($ownerType, $ownerId);
    }

    // Only sent fields change. A blank caption or alt clears it. isPrimary moves the main photo.
    public static function update(int $mediaId, array $changes): array
    {
        $media = self::findOr404($mediaId);

        if (array_key_exists('caption', $changes)) {
            $media['caption'] = self::checkText($changes['caption'], 'The caption');
        }
        if (array_key_exists('altText', $changes)) {
            $media['altText'] = self::checkText($changes['altText'], 'The alt text');
        }
        if (array_key_exists('sortOrder', $changes)) {
            $sortOrder = $changes['sortOrder'];
            if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > self::MAX_SORT_ORDER) {
                throw new HttpError(422, 'sortOrder must be a whole number from 0 to 65535');
            }
            $media['sortOrder'] = $sortOrder;
        }

        // There must always be a main photo, so it can only move to another photo.
        $makePrimary = false;
        if (array_key_exists('isPrimary', $changes)) {
            if ($changes['isPrimary'] !== true) {
                throw new HttpError(422, 'isPrimary can only be true. To change the main photo, set it on another photo');
            }
            if ($media['type'] === 'video') {
                throw new HttpError(422, 'Only a photo can be the main photo');
            }
            $makePrimary = true;
        }

        Db::transaction(function () use ($media, $makePrimary) {
            Media::lockOwner($media['ownerType'], $media['ownerId']);
            // Someone may have deleted it while we were checking
            $current = self::findOr404($media['id']);

            $media['isPrimary'] = $current['isPrimary'];
            if ($makePrimary) {
                Media::clearPrimary($media['ownerType'], $media['ownerId']);
                $media['isPrimary'] = true;
            }
            Media::update($media);
        });

        return Media::find($mediaId);
    }

    // Deletes the row, then the file. If it was the main photo, the next photo takes over.
    public static function delete(int $mediaId): void
    {
        $media = self::findOr404($mediaId);

        Db::transaction(function () use ($media) {
            Media::lockOwner($media['ownerType'], $media['ownerId']);
            $current = self::findOr404($media['id']);

            Media::delete($current['id']);
            if ($current['isPrimary']) {
                $nextId = Media::firstPhotoOf($current['ownerType'], $current['ownerId']);
                if ($nextId !== null) {
                    Media::markPrimary($nextId);
                }
            }
        });

        // Remove the file only after the row is gone, so a failed delete leaves no broken photo.
        $file = self::fileOf($media['url']);
        if ($file !== null && is_file($file)) {
            unlink($file);
        }
    }

    // UPLOADS_DIR overrides this. A relative path starts at the backend folder.
    public static function uploadsDir(): string
    {
        $backend = dirname(__DIR__);
        $dir = trim((string) ($_ENV['UPLOADS_DIR'] ?? ''));
        if ($dir === '') {
            return $backend . '/public/uploads';
        }
        // Absolute on Unix ("/foo") or Windows ("C:\foo", "C:/foo"); anything else is relative.
        if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $dir)) {
            $dir = $backend . '/' . $dir;
        }

        return rtrim($dir, '/\\');
    }

    // Whether $path is a file PHP just received in this request. The controller asks this before
    // passing $_FILES on. In tests (copyFilesForTests) any real file counts.
    public static function isRealUpload(string $path): bool
    {
        if (self::$copyForTests) {
            return is_file($path);
        }

        return is_uploaded_file($path);
    }

    // Tests only: copy files instead of moving them (see $copyForTests above)
    public static function copyFilesForTests(bool $on): void
    {
        self::$copyForTests = $on;
    }

    // 404 unless the owner exists. A private event has no gallery.
    private static function checkOwnerExists(string $ownerType, int $ownerId): void
    {
        if ($ownerType === 'space') {
            if (Space::findForStaff($ownerId) === null) {
                throw new HttpError(404, 'Space not found');
            }
        } elseif ($ownerType === 'event') {
            if (PublicEvent::find($ownerId) === null) {
                throw new HttpError(404, 'Event not found');
            }
        } else {
            throw new HttpError(404, 'Not found');
        }
    }

    // Lock the owner first. Two first uploads would otherwise both become the main photo.
    private static function lockOwner(string $ownerType, int $ownerId): void
    {
        if ($ownerType === 'space') {
            if (!Space::lock($ownerId)) {
                throw new HttpError(404, 'Space not found');
            }
        } else {
            if (PublicEvent::find($ownerId, true) === null) {
                throw new HttpError(404, 'Event not found');
            }
        }
        Media::lockOwner($ownerType, $ownerId);
    }

    // Turns PHP's upload error code into a clear message. UPLOAD_ERR_OK (0) means all is well.
    private static function checkUploadError(int $uploadError): void
    {
        if ($uploadError === UPLOAD_ERR_OK) {
            return;
        }
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            throw new HttpError(422, 'Choose a file to upload');
        }
        // INI_SIZE: over upload_max_filesize in php.ini. FORM_SIZE: over the form's MAX_FILE_SIZE.
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(422, 'The file is larger than the server allows');
        }
        if ($uploadError === UPLOAD_ERR_PARTIAL) {
            throw new HttpError(422, 'Only part of the file arrived. Please try again');
        }
        // NO_TMP_DIR, CANT_WRITE, EXTENSION: a problem on the server, not with the file
        throw new HttpError(422, 'The server could not save the file. Please try again');
    }

    // Type from the first bytes. A text file named photo.png is text/plain.
    private static function detectType(string $path): string
    {
        $info = new finfo(FILEINFO_MIME_TYPE);
        $type = $info->file($path);
        if (!is_string($type)) {
            return '';
        }

        return $type;
    }

    // Optional text like a caption: null, or a string of up to 255 letters. Blank counts as none.
    private static function checkText(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpError(422, $label . ' must be text');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            throw new HttpError(422, $label . ' can be up to 255 characters');
        }

        return $value;
    }

    // Moves the upload into its folder and returns the new path
    private static function saveFile(string $tmpPath, string $folder, string $name): string
    {
        $dir = self::uploadsDir() . '/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $destination = $dir . '/' . $name;

        $saved = false;
        if (self::$copyForTests) {
            $saved = copy($tmpPath, $destination);
        } else {
            $saved = move_uploaded_file($tmpPath, $destination);
        }
        if (!$saved) {
            throw new HttpError(500, 'The server could not save the file. Please try again');
        }

        return $destination;
    }

    // File for an uploaded url, or null. Only our names match, so a url stays in the folder.
    private static function fileOf(string $url): ?string
    {
        $matches = [];
        if (preg_match('#^/uploads/(spaces|events)/([0-9a-f]{32}\.(jpg|png|webp|mp4))$#', $url, $matches) !== 1) {
            return null;
        }

        return self::uploadsDir() . '/' . $matches[1] . '/' . $matches[2];
    }

    private static function findOr404(int $mediaId): array
    {
        $media = Media::find($mediaId);
        if ($media === null) {
            throw new HttpError(404, 'Photo or video not found');
        }

        return $media;
    }
}
