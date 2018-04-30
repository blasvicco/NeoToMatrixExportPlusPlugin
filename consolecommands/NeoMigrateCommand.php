<?php
namespace Craft;

class NeoMigrateCommand extends BaseCommand
{
  public function actionMigrate(int $fieldId, bool $clean = false)
  {
    set_time_limit(0);
    $neoField = craft()->fields->getFieldById($fieldId);

    try
    {
      craft()->neoToMatrixExportPlus->convertFieldToMatrix($neoField, $clean);
    } catch (\Exception $e) {
      echo $e->getMessage()."\n";
    }
  }
}
?>
