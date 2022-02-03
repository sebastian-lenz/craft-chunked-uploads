<?php

namespace lenz\craft\chunkedUploads;

use Aws\S3\S3Client;
use craft\awss3\Volume as S3Volume;
use Throwable;
use yii\web\BadRequestHttpException;

/**
 *
 */
class BucketChunkHandler extends BaseChunkHandler
{

    /** @var S3Volume */
    public $volume;

    /** @var S3Client */
    protected $client;


    /** @inheritdoc */
    public function init()
    {
        parent::init();

        $this->client = new S3Client([
            'version'     => 'latest',
            'region'      => $this->volume->region,
            'credentials' => [
                'key'    => $this->volume->keyId,
                'secret' => $this->volume->secret,
            ]
        ]);
    }


    /** @inheritdoc */
    public function process()
    {
        $tempFile = $this->getTempFilename();
        $tempFileName = basename($tempFile);
        $uploadedSize = filesize($tempFile);

        if ($this->chunkOffset > 0) {
            //fetch it from the S3 bucket
            $result = $this->client->getObject([
                'bucket' => $this->volume->bucket,
                'Key'    => $tempFileName,
                'SaveAs' => $tempFile
            ]);

            if ($uploadedSize != $this->chunkOffset) {
                throw new BadRequestHttpException('Invalid chunk offset.');
            }

            file_put_contents($tempFile, fopen($this->upload->tempName, 'r'), FILE_APPEND);

            $this->client->putObject([
                'Bucket'     => $this->volume->bucket,
                'Key'        => $tempFileName,
                'SourceFile' => $tempFile,
            ]);
        } else {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            try {
                $this->client->deleteObject([
                    'Bucket' => $this->volume->bucket,
                    'Key'    => $tempFileName,
                ]);
            } catch (Throwable $e) {
                //continue
            }

            move_uploaded_file($this->upload->tempName, $tempFile);

            $this->client->putObject([
                'Bucket'     => $this->volume->bucket,
                'Key'        => $tempFileName,
                'SourceFile' => $tempFile,
            ]);
        }

        clearstatcache();

        if ($uploadedSize == $this->totalSize) {
            $this->client->deleteObject([
                'Bucket' => $this->volume->bucket,
                'Key'    => $tempFileName,
            ]);

            rename($tempFile, $this->upload->tempName);
            return true;
        }

        return false;
    }
}
