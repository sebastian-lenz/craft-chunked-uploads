<?php

namespace lenz\craft\chunkedUploads;

use craft\controllers\AssetsController;
use yii\base\Event;
use yii\web\View;

/**
 * Class Plugin
 */
class Plugin extends \craft\base\Plugin
{
  /**
   * @inheritDoc
   */
  public $hasCpSettings = false;


  /**
   * Plugin init.
   *
   * @param $id
   * @param null $parent
   * @param array $config
   */
  public function init() {
    parent::init();
    $service = Service::instance();

    Event::on(AssetsController::class, AssetsController::EVENT_BEFORE_ACTION, [$service, 'onBeforeAction']);
    Event::on(View::class, View::EVENT_END_BODY, [$service, 'onViewEndBody']);
  }


  /**
   * @return Model|null
   */
  protected function createSettingsModel() {
    return Settings::instance();
  }

}
