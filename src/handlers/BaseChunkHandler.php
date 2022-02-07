<?php

namespace karmabunny\ChunkedUploads\handlers;

use Craft;
use craft\base\Model;
use craft\base\VolumeInterface;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\models\VolumeFolder;
use craft\web\Request;
use craft\web\Response;
use craft\web\UploadedFile;
use yii\base\Response as YiiResponse;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HeaderCollection;

/**
 *
 * @package karmabunny\ChunkedUploads
 */
abstract class BaseChunkHandler extends Model
{
    /** @var Request */
    public $request;

    /** @var UploadedFile */
    public $upload;

    /** @var VolumeFolder */
    public $folder;

    /** @var VolumeInterface */
    public $volume;


    /** @var int */
    protected $chunkOffset;

    /** @var int */
    protected $totalSize;

    /** @var string */
    protected $originalFilename;


    /** @inheritdoc */
    public function init()
    {
        parent::init();

        $headers = $this->request->getHeaders();
        $contentRange = $this->getContentRange($headers);
        $originalFilename = $this->getContentDisposition($headers);

        if (!$contentRange or !$originalFilename) {
            throw new BadRequestHttpException('Missing upload header data.');
        }

        [$this->chunkOffset, $this->totalSize] = $contentRange;
        $this->originalFilename = $originalFilename;

        // Check permissions on the first chunk.
        if ($this->chunkOffset == 0) {
            $this->checkFolderPermissions();

            // This returns a response, not an exception.
            // TODO dunno what to do with it yet.
            // $this->checkConflicts();
        }
    }


    /**
     *
     * @return YiiResponse|bool true if finished
     */
    public abstract function process();


    /**
     * Responds to the request with a JSON error message.
     *
     * @param string $error The error message.
     * @return YiiResponse
     */
    public function asErrorJson(string $error)
    {
        return $this->asJson(['error' => $error]);
    }


    /**
     * Send data formatted as JSON.
     *
     * @param mixed $data
     * @return YiiResponse
     */
    public function asJson($data)
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_JSON;
        $response->data = $data;
        return $response;
    }


    /**
     * @param HeaderCollection $headers
     * @return int[]|null [ offset, size ] - or null if invalid/missing.
     */
    protected static function getContentRange(HeaderCollection $headers)
    {
        $contentRange = $headers->get('content-range');
        if (!$contentRange) return null;

        $parts = preg_split('/[^0-9]+/', $contentRange);
        if (!$parts or count($parts) < 4) return null;

        return [
            (int) $parts[1],
            (int) $parts[3],
        ];
    }


    /**
     * @param HeaderCollection $headers
     * @return string|null
     */
    protected static function getContentDisposition(HeaderCollection $headers)
    {
        $contentDisposition = $headers->get('content-disposition');
        if (!$contentDisposition) return null;

        return rawurldecode(preg_replace('/(^[^"]+")|("$)/', '', $contentDisposition));
    }


    /**
     *
     * Ripped from UploadedFile::saveAsTempFile()
     *
     * @return string
     */
    protected function getTempFilename()
    {
        $tempFilename = uniqid(pathinfo($this->originalFilename, PATHINFO_FILENAME), true) . '.' . pathinfo($this->originalFilename, PATHINFO_EXTENSION);
        return Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;
    }


    /**
     *
     * @return string
     */
    protected function prepareAssetName()
    {
        return Assets::prepareAssetName($this->originalFilename);
    }


    /**
     * Abbreviated from AssetsController::requireVolumePermissionByFolder()
     *
     * @return void
     * @throws ForbiddenHttpException
     */
    protected function checkFolderPermissions()
    {
        if (!$this->volume->id) {
            $userTemporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder();

            // Skip permission check only if it's the user's temporary folder
            if ($userTemporaryFolder->id == $this->volume->id) {
                return;
            }
        }

        if (!Craft::$app->getUser()->checkPermission('saveAssetInVolume:' . $this->volume->uid)) {
            throw new ForbiddenHttpException('User is not permitted to perform this action');
        }
    }


    /**
     * From AssetsController::actionUpload()
     *
     * @return void
     */
    protected function checkConflicts()
    {
        $filename = Assets::prepareAssetName($this->originalFilename);

        $asset = Asset::findOne([
            'folderId' => $this->folder->id,
            'filename' => $filename,
        ]);

        // No conflict, skip it.
        if (!$asset) return;

        $suggestedFilename = Craft::$app->getAssets()->getNameReplacementInFolder($filename, $this->folder->id);
        $conflictingAssetUrl = ($asset && $this->volume->hasUrls) ? $asset->getUrl() : null;

        return $this->asJson([
            'conflict' => Craft::t('app', 'A file with the name “{filename}” already exists.', ['filename' => $filename]),
            'assetId' => 0,
            'filename' => $filename,
            'conflictingAssetId' => $asset->id,
            'suggestedFilename' => $suggestedFilename,
            'conflictingAssetUrl' => $conflictingAssetUrl,
        ]);
    }
}
