<?php

namespace lenz\craft\chunkedUploads;

use Craft;
use craft\base\Model;
use craft\web\Application;
use craft\web\assets\fileupload\FileUploadAsset;
use craft\web\Request;
use Exception;
use Imagick;
use lenz\craft\chunkedUploads\assets\FileUploadPatch;
use Throwable;
use yii\base\Event;
use yii\base\InvalidConfigException;
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
   * Plugin constructor.
   *
   * @param $id
   * @param null $parent
   * @param array $config
   */
  public function __construct($id, $parent = null, array $config = []) {
    parent::__construct($id, $parent, $config);

    if (Craft::$app->request->isCpRequest) {
      Event::on(Application::class, Application::EVENT_BEFORE_ACTION, [$this, 'onBeforeAction']);
      Event::on(View::class, View::EVENT_END_BODY, [$this, 'onViewEndBody']);
    }
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
   * @param string $uploadedFile
   */
  private function processImage(Request $request, $uploadedFile) {
    if (!extension_loaded('imagick')) {
      return;
    }

    list($maxWidth, $maxHeight) = $this
      ->getSettings()
      ->getMaxImageDimension($request->getParam('folderId'));

    if (is_null($maxWidth) && is_null($maxHeight)) {
      return;
    }

    try {
      $hasChanged   = false;
      $image        = new Imagick($uploadedFile);
      $format       = $image->getImageFormat();
      $geometry     = $image->getImageGeometry();
      $nativeWidth  = $geometry['width'];
      $nativeHeight = $geometry['height'];
      $scale        = 1;

      if (
        is_array(self::$ALLOWED_IMAGE_FORMATS) &&
        !in_array($format, self::$ALLOWED_IMAGE_FORMATS)
      ) {
        $hasChanged = true;
        $image->setFormat(self::$DEFAULT_FORMAT);
      }

      if (!is_null($maxWidth) && $nativeWidth > $maxWidth) {
        $scale = $maxWidth / $nativeWidth;
      }

      if (!is_null($maxHeight) && $nativeHeight > $maxHeight) {
        $scale = min($scale, $maxHeight / $nativeHeight);
      }

      if ($scale < 1) {
        $hasChanged = true;
        $image->resizeImage(
          round($nativeWidth * $scale),
          round($nativeHeight * $scale),
          Imagick::FILTER_LANCZOS,
          1
        );
      }

      if ($hasChanged) {
        $image->setCompressionQuality(100);
        file_put_contents($uploadedFile, $image->getImageBlob());
      }
    } catch (Throwable $error) {
      Craft::error($error->getMessage());
    }
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

    // Recompose chunks

    $tempFile = sys_get_temp_dir() . '/craft_upload_chunks_' . md5($originalFileName);
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
      $this->processImage($request, $uploadedFile);
    }

    return $isFinished;
  }
}
