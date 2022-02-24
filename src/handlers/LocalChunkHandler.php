<?php

namespace karmabunny\ChunkedUploads\handlers;

use yii\web\BadRequestHttpException;

/**
 *
 */
class LocalChunkHandler extends BaseChunkHandler
{

    /** @inheritdoc */
    public function process()
    {
        $tempFile = $this->getStored('filename');

        if (!$tempFile) {
            $tempFile = $this->getTempFilename();
        }

        // Initial file.
        if ($this->chunkOffset == 0) {
            $uploadedSize = filesize($this->upload->tempName);

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            move_uploaded_file($this->upload->tempName, $tempFile);

            $this->store('filename', $tempFile);
        }
        // Appending chunks to the temp file.
        else {
            $uploadedSize = filesize($tempFile);

            if ($uploadedSize != $this->chunkOffset) {
                throw new BadRequestHttpException("Invalid chunk offset; expected {$uploadedSize}, got {$this->chunkOffset}.");
            }

            file_put_contents($tempFile, fopen($this->upload->tempName, 'r'), FILE_APPEND);

            $uploadedSize += filesize($this->upload->tempName);
        }

        clearstatcache();

        // It's finished - now we're going to overwrite the upload tempfile.
        // The Craft assets controller will pick this up and do the rest.
        if ($uploadedSize == $this->totalSize) {
            rename($tempFile, $this->upload->tempName);
            $this->removeStored(['filename']);
            return true;
        }

        // Not finished.
        return false;
    }
}
