<?php

namespace karmabunny\ChunkedUploads\handlers;

use Aws\S3\S3Client;
use Craft;
use craft\awss3\Volume as S3Volume;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\web\Session;
use yii\base\Response as YiiResponse;
use yii\web\BadRequestHttpException;

/**
 *
 * @property S3Volume $volume
 */
class BucketChunkHandler extends BaseChunkHandler
{
    /**
     * AWS multipart uploads have a minimum size of 5mb.
     *
     * @var int
     */
    static $MIN_CHUNK_SIZE = 5242880;

    /** @var string Session prefix. */
    static $PREFIX = 'chunkedUploads';


    /** @var S3Client */
    protected $client;


    /** @inheritdoc */
    public function init()
    {
        parent::init();

        $this->client = new S3Client([
            'version'     => 'latest',
            'region'      => Craft::parseEnv($this->volume->region),
            'credentials' => [
                'key'    => Craft::parseEnv($this->volume->keyId),
                'secret' => Craft::parseEnv($this->volume->secret),
            ]
        ]);
    }


    /** @inheritdoc */
    public function process()
    {
        $filename = $this->getStored('filename');

        if (!$filename) {
            $filename = $this->prepareAssetName();
        }

        $subfolder = Craft::parseEnv($this->volume->subfolder);
        $bucket = Craft::parseEnv($this->volume->bucket);

        $key = rtrim(preg_replace('|/+|', '/', $subfolder . '/' . $this->folder->path . '/' . $filename), '/');

        // First chunk creates the multipart session.
        if ($this->chunkOffset == 0) {
            $res = $this->client->createMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'ACL' => 'public-read',
            ]);

            $uploadId = $res->get('UploadId');
            if (!$uploadId) {
                throw new BadRequestHttpException('Unable to create multipart upload.');
            }

            $this->store('uploadId', $uploadId);
            $this->store('filename', $filename);

            // Could conflict, so tidy that up first.
            $this->removeStored([
                'parts',
                'uploadedSize',
            ]);

            Craft::debug('Created multipart upload for: ' . $key, __METHOD__);
        }

        // Subsequent chunks get the multipart session from our session.
        if (!isset($uploadId)) {
            $uploadId = $this->getStored('uploadId');
            if (!$uploadId) {
                throw new BadRequestHttpException('Missing session upload ID.');
            }
        }

        // Upload the chunk.
        $parts = $this->getStored('parts', []);

        $res = $this->client->uploadPart([
            'Body' => file_get_contents($this->upload->tempName),
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => count($parts) + 1,
            'ContentLength' => $this->upload->size,
        ]);

        $parts[] = [
            'PartNumber' => count($parts) + 1,
            'ETag' => $res->get('ETag'),
        ];

        $this->store('parts', $parts);

        // Bump total size.
        $uploadedSize = $this->getStored('uploadedSize', 0);
        $uploadedSize += $this->upload->size;
        $this->store('uploadedSize', $uploadedSize);

        Craft::debug('Uploaded part ' . count($parts) . ': ' . $this->upload->size, __METHOD__);

        // Ok we're done!
        if ($uploadedSize == $this->totalSize) {
            $this->client->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ]
            ]);

            // Tidy up.
            $this->removeStored([
                'uploadId',
                'parts',
                'uploadedSize',
                'filename',
            ]);

            Craft::debug('Completed multipart upload: ' . $key, __METHOD__);

            // Can't trust the builtin S3 uploader with this one.
            // Gotta finish it up ourselves.
            $filename = $key = rtrim(preg_replace('|/+|', '/', $this->folder->path . '/' . $filename), '/');
            $newFilename = $this->prepareAssetName();

            return $this->createAsset($filename, $newFilename);
        }

        // Not finished.
        return false;
    }


    /**
     * Sanitise the filename.
     *
     * Also add a rando ID to the end to avoid file conflicts.
     *
     * @return string
     */
    protected function prepareAssetName()
    {
        $filename = parent::prepareAssetName();

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $id = substr(uniqid(), 9, 4);

        return "{$name}_{$id}.{$extension}";
    }


    /**
     * This creates an asset.
     *
     * Craft expects a tempfile, which it moves to the target location. We can't
     * do that here. But we _can_ 'relocate' a file, which lets Craft still
     * create all the asset bits but doesn't care that there isn't a tempfile.
     *
     * So with the multipart, we've uploaded a file at the 'old' location. We
     * then provide the newFolder/newFilename properties to move the file as
     * we create the asset.
     *
     * The title is generated from the 'original' filename, otherwise we
     * get the funny dedupe IDs at the end.
     *
     * @param string $tempFilename
     * @param string $newFilename
     * @return YiiResponse
     */
    protected function createAsset($tempFilename, $newFilename)
    {
        try {
            $title = Assets::filename2Title(Assets::prepareAssetName($this->originalFilename));

            $asset = new Asset();
            $asset->setVolumeId($this->volume->id);
            $asset->title = $title;

            // From
            $asset->filename = $tempFilename;
            $asset->folderId = $this->folder->id;

            // To
            $asset->newFolderId = $this->folder->id;
            $asset->newFilename = $newFilename;

            $asset->uploaderId = Craft::$app->getUser()->getId();
            $asset->avoidFilenameConflicts = false;
            $asset->dateModified = new \DateTime();
            $asset->size = $this->totalSize;
            $asset->setScenario(Asset::SCENARIO_DEFAULT);

            $result = Craft::$app->getElements()->saveElement($asset);

            // In case of error, let user know about it.
            if (!$result) {
                $errors = $asset->getFirstErrors();
                return $this->asErrorJson(Craft::t('app', 'Failed to save the asset:') . ' ' . implode(";\n", $errors));
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


    /**
     * @return Session
     */
    protected static function getSession()
    {
        static $session;
        return $session ?? $session = Craft::$app->getSession();
    }


    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function store($key, $value)
    {
        $session = static::getSession();
        $session->set(static::$PREFIX . ".{$this->originalFilename}.{$key}", $value);
    }


    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getStored($key, $default = null)
    {
        $session = static::getSession();
        return $session->get(static::$PREFIX . ".{$this->originalFilename}.{$key}", $default);
    }


    /**
     * @param string[] $keys
     * @return void
     */
    protected function removeStored($keys)
    {
        $session = static::getSession();
        foreach ($keys as $key) {
            $session->remove(static::$PREFIX . ".{$this->originalFilename}.{$key}");
        }
    }

}
