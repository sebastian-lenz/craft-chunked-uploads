<?php

namespace karmabunny\ChunkedUploads\assets;

use craft\helpers\Json;
use karmabunny\ChunkedUploads\Plugin;
use yii\web\AssetBundle;

/**
 * A lightweight frontend JS library for uploading chunked files.
 */
class ChunkedUploader extends AssetBundle
{
  /**
   * @inheritdoc
   */
  public function init() {
    $settings = Plugin::getInstance()->getSettings();

    $this->sourcePath = __DIR__ . '/resources';
    $this->js = ['chunked.js'];
    $this->jsOptions = [
      'data-key' => 'karmabunny/chunked-uploads/chunked',
      'data-settings' => Json::htmlEncode($settings->toArray()),
    ];

    parent::init();
  }
}
