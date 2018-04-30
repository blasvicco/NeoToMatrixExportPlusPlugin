<?php

namespace Craft;

class NeoToMatrixExportPlusPlugin extends BasePlugin
{
  public function getName()
  {
    return Craft::t('Neo To Matrix Export Plus Plugin');
  }

  public function getDescription()
  {
    return Craft::t('Export nested Neo fields to a combination of Matrix for top level and Super table fields for nested levels (Only first nested level supported)');
  }

  public function getVersion()
  {
    return '1.0';
  }

  public function getDeveloper()
  {
    return 'Blas Vicco under SEOMoz Inbound Engineer Team';
  }

  public function getDeveloperUrl()
  {
    return 'https://github.com/blasvicco';
  }
  
}
