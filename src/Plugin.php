<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\awss3\S3Client;
use craft\awss3\Volume as S3Volume;
use craft\base\Model;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use craft\web\Application;
use craft\web\assets\fileupload\FileUploadAsset;
use craft\web\Request;
use Exception;
use InvalidArgumentException;
use lenz\craft\chunkedUploads\assets\FileUploadPatch;
use Throwable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;
use yii\web\HeaderCollection;
use yii\web\View;

/**
 * Class Plugin
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
  /**
   * @inheritDoc
   */
  public $hasCpSettings = true;

  /**
   * @var array
   */
  public static $ALLOWED_IMAGE_FORMATS = ['GIF', 'PNG', 'JPEG'];

  /**
   * @var string
   */
  public static $DEFAULT_FORMAT = 'JPEG';

  /**
   * The name of the uploaded file we are watching for.
   */
  const FILE_NAME = 'assets-upload';


  /**
   * Plugin init.
   *
   * @param $id
   * @param null $parent
   * @param array $config
   */
  public function init() {
    parent::init();

    Event::on(Application::class, Application::EVENT_BEFORE_ACTION, [$this, 'onBeforeAction']);
    Event::on(View::class, View::EVENT_END_BODY, [$this, 'onViewEndBody']);
  }

  /**
   * @param Event $event
   * @throws Exception
   */
  public function onBeforeAction(Event $event) {
    $request = Craft::$app->request;

    if (
      $request->getIsPost() &&
      $request->getHeaders()->has('content-disposition') &&
      $request->getHeaders()->has('content-range') &&
      is_array($_FILES) &&
      isset($_FILES[self::FILE_NAME])
    ) {
      if (!$this->processUpload($request)) {
        die();
      }
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
   * @inheritDoc
   * @throws Exception
   */
  protected function settingsHtml() {
    return Craft::$app->view->renderTemplate(
      'chunked-uploads/_settings.twig',
      [
        'settings' => $this->getSettings(),
      ]
    );
  }


  // Protected methods
  // -----------------

  /**
   * @return Model|null
   */
  protected function createSettingsModel() {
    return new Settings();
  }

  /**
   * @param HeaderCollection $headers
   * @return string|null
   */
  private function getContentDisposition(HeaderCollection $headers) {
    $contentDisposition = $headers->get('content-disposition');
    return $contentDisposition ?
      rawurldecode(preg_replace(
        '/(^[^"]+")|("$)/',
        '',
        $contentDisposition
      )) : null;
  }

  /**
   * @param HeaderCollection $headers
   * @return array[]
   */
  private function getContentRange(HeaderCollection $headers) {
    $contentRange = $headers->get('content-range');
    $parts = $contentRange
      ? preg_split('/[^0-9]+/', $contentRange)
      : null;

    $offset = is_array($parts) && isset($parts[1]) ? intval($parts[1]) : null;
    $size   = is_array($parts) && isset($parts[3]) ? intval($parts[3]) : null;

    return [$offset, $size];
  }


  /**
   * @param Request $request
   * @return bool
   * @throws Exception
   */
  private function processUpload(Request $request) {
    $headers          = $request->getHeaders();
    $upload           = $_FILES[self::FILE_NAME];
    $uploadedFile     = $upload['tmp_name'];
    $originalFileName = $this->getContentDisposition($headers);

    list($chunkOffset, $totalSize) = $this->getContentRange($headers);

    if (is_array($uploadedFile)) {
      throw new Exception('Multiple files are not supported.');
    }

    if (!is_uploaded_file($uploadedFile)) {
      throw new Exception('Invalid upload.');
    }

    if (is_null($originalFileName) || is_null($chunkOffset) || is_null($totalSize)) {
      throw new Exception('Missing upload header data.');
    }

    if (class_exists(S3Client::class) and class_exists(S3Volume::class)) {
      $folder = $this->getFolder($request);
      $volume = $folder->getVolume();

      if ($volume instanceof S3Volume) {
        return $this->uploadBucket($request, $volume);
      }
    }

    return $this->uploadLocal($request);
  }


  /**
   *
   * @param Request $request
   * @return VolumeFolder
   * @throws InvalidConfigException
   * @throws InvalidArgumentException
   */
  protected function getFolder(Request $request)
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


  protected function uploadLocal(Request $request)
  {
    $headers          = $request->getHeaders();
    $upload           = $_FILES[self::FILE_NAME];
    $uploadedFile     = $upload['tmp_name'];
    $originalFileName = $this->getContentDisposition($headers);

    list($chunkOffset, $totalSize) = $this->getContentRange($headers);

    $tempFile = Craft::$app->getRuntimePath();
    $tempFile .= '/temp/craft_upload_chunks_' . md5($originalFileName);

    if ($chunkOffset > 0) {
      $uploadedSize = filesize($tempFile);
      if ($uploadedSize != $chunkOffset) {
        throw new Exception('Invalid chunk offset.');
      }

      file_put_contents($tempFile, fopen($uploadedFile, 'r'), FILE_APPEND);
    } else {
      if (file_exists($tempFile)) {
        unlink($tempFile);
      }

      move_uploaded_file($uploadedFile, $tempFile);
    }

    // Check for upload completion

    clearstatcache();
    $uploadedSize = filesize($tempFile);
    $isFinished = $uploadedSize == $totalSize;
    if ($isFinished) {
      rename($tempFile, $uploadedFile);
    }

    return $isFinished;
  }


  /**
   *
   * @param Request $request
   * @param S3Volume $volume
   * @return bool
   * @throws NotSupportedException
   * @throws Exception
   */
  protected function uploadBucket(Request $request, $volume)
  {
    $headers          = $request->getHeaders();
    $upload           = $_FILES[self::FILE_NAME];
    $uploadedFile     = $upload['tmp_name'];
    $originalFileName = $this->getContentDisposition($headers);

    list($chunkOffset, $totalSize) = $this->getContentRange($headers);

    $client = new S3Client([
      'version'     => 'latest',
      'region'      => $volume->region,
      'credentials' => [
        'key'    => $volume->keyId,
        'secret' => $volume->secret,
      ]
    ]);

    $tempFileName = 'temp/craft_upload_chunks_' . md5($originalFileName);
    $tempFile = Craft::$app->getRuntimePath() . $tempFileName;

    if ($chunkOffset > 0) {
      //fetch it from the S3 bucket
      $result = $client->getObject([
        'bucket' => $volume->bucket,
        'Key'    => $tempFileName,
        'SaveAs' => $tempFile
      ]);

      $uploadedSize = filesize($tempFile);
      if ($uploadedSize != $chunkOffset) {
        throw new Exception('Invalid chunk offset.');
      }

      file_put_contents($tempFile, fopen($uploadedFile, 'r'), FILE_APPEND);

      $client->putObject([
        'Bucket'     => $volume->bucket,
        'Key'        => $tempFileName,
        'SourceFile' => $tempFile,
      ]);
    } else {
      if (file_exists($tempFile)) {
        unlink($tempFile);
      }

      try {
        $client->deleteObject([
          'Bucket' => $volume->bucket,
          'Key'    => $tempFileName,
        ]);
      } catch (Throwable $e) {
        //continue
      }

      move_uploaded_file($uploadedFile, $tempFile);

      $client->putObject([
        'Bucket'     => $volume->bucket,
        'Key'        => $tempFileName,
        'SourceFile' => $tempFile,
      ]);
    }

    // Check for upload completion

    clearstatcache();
    $uploadedSize = filesize($tempFile);
    $isFinished = $uploadedSize == $totalSize;

    if ($isFinished) {
      $client->deleteObject([
        'Bucket' => $volume->bucket,
        'Key'    => $tempFileName,
      ]);

      rename($tempFile, $uploadedFile);
    }

    return $isFinished;
  }
}
