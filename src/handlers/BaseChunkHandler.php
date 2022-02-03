<?php

namespace lenz\craft\chunkedUploads\helpers;

use Craft;
use craft\base\Model;
use craft\web\Request;
use craft\web\Response;
use craft\web\UploadedFile;
use yii\base\Response as YiiResponse;
use yii\web\BadRequestHttpException;
use yii\web\HeaderCollection;

/**
 *
 * @package lenz\craft\chunkedUploads
 */
abstract class BaseChunkHandler extends Model
{
    /** @var Request */
    public $request;

    /** @var UploadedFile */
    public $upload;

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

}
