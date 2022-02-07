<?php

namespace karmabunny\ChunkedUploads\assets;

use craft\helpers\Json;
use craft\web\assets\fileupload\FileUploadAsset;
use karmabunny\ChunkedUploads\Plugin;
use yii\web\AssetBundle;

/**
 * Class FileUploadPatch
 */
class FileUploadPatch extends AssetBundle
{
  /**
   * @inheritdoc
   */
  public function init() {
    $settings = Plugin::getInstance()->getSettings();

    $this->sourcePath = __DIR__ . '/resources';
    $this->depends    = [FileUploadAsset::class];
    $this->js         = ['fileupload.patch.js'];
    $this->jsOptions  = [
      'data-key' => 'karmabunny/chunked-uploads/fileupload',
      'data-settings' => Json::htmlEncode($settings->toArray()),
    ];

    parent::init();
  }
}
