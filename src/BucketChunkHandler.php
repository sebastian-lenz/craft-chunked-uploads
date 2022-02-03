<?php

namespace lenz\craft\chunkedUploads;

use Aws\S3\S3Client;
use Craft;
use craft\awss3\Volume as S3Volume;
use craft\elements\Asset;
use craft\helpers\Assets;
use yii\web\BadRequestHttpException;

/**
 *
 */
class BucketChunkHandler extends BaseChunkHandler
{

    /** @var VolumeFolder */
    public $folder;


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
        $key = Assets::prepareAssetName($this->originalFilename);

        $session = Craft::$app->getSession();

        /** @var S3Volume */
        $volume = $this->folder->getVolume();

        // First chunk creates the multipart session.
        if ($this->chunkOffset == 0) {
            $res = $this->client->createMultipartUpload([
                'Bucket' => $volume->bucket,
                'Key' => $key,
                'ACL' => 'public-read',
            ]);

            $uploadId = $res->get('UploadId');
            if (!$uploadId) {
                throw new BadRequestHttpException('Unable to create multipart upload.');
            }

            $session->set("chunkedUploads.{$key}.uploadId", $uploadId);
        }

        // Subsequent chunks get the multipart session from our session.
        if (!isset($uploadId)) {
            $uploadId = $session->get("chunkedUploads.{$key}.uploadId");
            if (!$uploadId) {
                throw new BadRequestHttpException('Missing session upload ID.');
            }
        }

        $parts = $session->get("chunkedUploads.{$key}.parts", []);
        $uploadedSize = $session->get("chunkedUploads.{$key}.uploadedSize", 0);

        $res = $this->client->uploadPart([
            'Body' => file_get_contents($this->upload->tempName),
            'Bucket' => $volume->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => count($parts) + 1,
            'ContentLength' => $this->upload->size,
        ]);

        // Increment uploaded size key, add a part.
        $uploadedSize += $this->upload->size;
        $parts[] = [
            'PartNumber' => count($parts) + 1,
            'ETag' => $res->get('ETag'),
        ];

        $session->set("chunkedUploads.{$key}.parts", $parts);
        $session->set("chunkedUploads.{$key}.uploadedSize", $uploadedSize);

        // Ok we're done!
        if ($uploadedSize == $this->totalSize) {
            $this->client->completeMultipartUpload([
                'Bucket' => $volume->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ]
            ]);

            // Tidy up.
            $session->remove("chunkedUploads.{$key}.uploadId");
            $session->remove("chunkedUploads.{$key}.parts");
            $session->remove("chunkedUploads.{$key}.uploadedSize");

            // Can't trust the builtin S3 uploader with this one.
            // Gotta finish it up ourselves.
            return $this->createAsset();
        }

        // Not finished.
        return false;
    }


    /**
     *
     */
    protected function createAsset()
    {
        try {
            $filename = Assets::prepareAssetName($this->originalFilename);

            // It's pretty much identical, except we don't add the tempfile.

            $asset = new Asset();
            $asset->filename = $filename;
            $asset->newFolderId = $this->folder->id;
            $asset->setVolumeId($this->folder->volumeId);
            $asset->uploaderId = Craft::$app->getUser()->getId();
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            $result = Craft::$app->getElements()->saveElement($asset);

            // In case of error, let user know about it.
            if (!$result) {
                $errors = $asset->getFirstErrors();
                return $this->asErrorJson(Craft::t('app', 'Failed to save the asset:') . ' ' . implode(";\n", $errors));
            }

            if ($asset->conflictingFilename !== null) {
                $conflictingAsset = Asset::findOne([
                    'folderId' => $this->folder->id,
                    'filename' => $asset->conflictingFilename,
                ]);

                return $this->asJson([
                    'conflict' => Craft::t('app', 'A file with the name “{filename}” already exists.', ['filename' => $asset->conflictingFilename]),
                    'assetId' => $asset->id,
                    'filename' => $asset->conflictingFilename,
                    'conflictingAssetId' => $conflictingAsset ? $conflictingAsset->id : null,
                    'suggestedFilename' => $asset->suggestedFilename,
                    'conflictingAssetUrl' => ($conflictingAsset && $conflictingAsset->getVolume()->hasUrls) ? $conflictingAsset->getUrl() : null,
                ]);
            }

            return $this->asJson([
                'success' => true,
                'filename' => $asset->filename,
                'assetId' => $asset->id,
            ]);
        }
        catch (\Throwable $e) {
            Craft::error('An error occurred when saving an asset: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
            return $this->asErrorJson($e->getMessage());
        }
    }
}
