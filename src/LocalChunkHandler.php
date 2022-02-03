<?php

namespace lenz\craft\chunkedUploads;

use yii\web\BadRequestHttpException;

/**
 *
 */
class LocalChunkHandler extends BaseChunkHandler
{

    /** @inheritdoc */
    public function process()
    {
        $tempFile = $this->getTempFilename();
        $uploadedSize = filesize($tempFile);

        // Appending chunks to the temp file.
        if ($this->chunkOffset > 0) {
            if ($uploadedSize != $this->chunkOffset) {
                throw new BadRequestHttpException('Invalid chunk offset.');
            }

            file_put_contents($tempFile, fopen($this->upload->tempName, 'r'), FILE_APPEND);
        }
        // Initial file.
        else {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            move_uploaded_file($this->upload->tempName, $tempFile);
        }

        clearstatcache();

        // It's finished!
        if ($uploadedSize == $this->totalSize) {
            rename($tempFile, $this->upload->tempName);
            return true;
        }

        // Not finished.
        return false;
    }
}
