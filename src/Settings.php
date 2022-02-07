<?php

namespace karmabunny\ChunkedUploads;

use Craft;
use craft\base\Model;

/**
 * Class Settings
 *
 * Override settings here with a static config file: `config/chunked.php`.
 */
class Settings extends Model
{
  /**
   * @var int in megabytes
   */
  public $chunkSize = 5;

  /**
   * @var int in megabytes
   */
  public $maxUploadSize = 500;


  /** @inheritdoc */
  public static function instance($refresh = false)
  {
      static $instance;
      if ($refresh or !$instance) {
        $config = Craft::$app->getConfig()->getConfigFromFile('chunked');
        $instance = new static($config);
      }
      return $instance;
  }
}
