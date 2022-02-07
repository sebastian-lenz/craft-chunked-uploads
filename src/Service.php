<?php

namespace karmabunny\ChunkedUploads;

use Craft;
use craft\awss3\S3Client;
use craft\awss3\Volume as S3Volume;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use craft\web\assets\fileupload\FileUploadAsset;
use craft\web\Request;
use craft\web\UploadedFile;
use Exception;
use InvalidArgumentException;
use karmabunny\ChunkedUploads\assets\FileUploadPatch;
use karmabunny\ChunkedUploads\handlers\BucketChunkHandler;
use karmabunny\ChunkedUploads\handlers\LocalChunkHandler;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Response;
use yii\web\BadRequestHttpException;
use yii\web\View;

/**
 * Service helper
 */
class Service extends \craft\base\Component
{

  /**
   * @throws Exception
   */
  public function onBeforeAction() {
    $request = Craft::$app->getRequest();

    if (
      $request->getIsPost() &&
      $request->getHeaders()->has('content-disposition') &&
      $request->getHeaders()->has('content-range') &&
      ($upload = UploadedFile::getInstanceByName('assets-upload'))
    ) {
      $chunk = $this->createHandler($request, $upload);
      $res = $chunk->process();

      // If the chunk handler returns a response, return it.
      if ($res instanceof Response) {
        $res->send();
        exit;
      }

      // If incomplete, just die - we don't want the assets controller
      // interfering - yet.
      if ($res === false) {
        exit;
      }

      // Upload finished - continue the asset creation process.
    }
  }

  /**
   * @param Event $event
   * @throws InvalidConfigException
   */
  public function onViewEndBody(Event $event) {
    /** @var View $view */
    $view = $event->sender;
    if (array_key_exists(FileUploadAsset::class, $view->assetBundles)) {
      $view->registerAssetBundle(FileUploadPatch::class);
    }
  }


  /**
   * @param Request $request
   * @return BaseChunkHandler
   * @throws BadRequestHttpException
   */
  protected function createHandler(Request $request, UploadedFile $upload)
  {
    $folder = self::getFolder($request);
    $volume = $folder->getVolume();

    // AWS multipart uploads.
    if (
      class_exists(S3Client::class)
      and class_exists(S3Volume::class)
      and ($volume instanceof S3Volume)
    ) {
      return new BucketChunkHandler([
        'request' => $request,
        'upload' => $upload,
        'folder' => $folder,
        'volume' => $volume,
      ]);
    }

    // Local file chunked uploads.
    return new LocalChunkHandler([
      'request' => $request,
      'upload' => $upload,
      'folder' => $folder,
      'volume' => $volume,
    ]);
  }


  /**
   *
   * @return VolumeFolder
   * @throws InvalidConfigException
   * @throws InvalidArgumentException
   */
  protected static function getFolder(Request $request)
  {
    $folderId = $request->getBodyParam('folderId');
    $fieldId = $request->getBodyParam('fieldId');

    if (!$folderId && !$fieldId) {
      throw new BadRequestHttpException('No target destination provided for uploading');
    }

    if (empty($folderId)) {
      $field = Craft::$app->getFields()->getFieldById((int)$fieldId);

      if (!($field instanceof AssetsField)) {
        throw new BadRequestHttpException('The field provided is not an Assets field');
      }

      if ($elementId = $request->getBodyParam('elementId')) {
        $siteId = $request->getBodyParam('siteId') ?: null;
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
      } else {
        $element = null;
      }

      $folderId = $field->resolveDynamicPathToFolderId($element);
    }

    if (empty($folderId)) {
      throw new BadRequestHttpException('The target destination provided for uploading is not valid');
    }

    $folder = Craft::$app->getAssets()->findFolder(['id' => $folderId]);

    if (!$folder) {
      throw new BadRequestHttpException('The target folder provided for uploading is not valid');
    }

    return $folder;
  }
}
