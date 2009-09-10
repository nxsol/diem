<?php

class dmAdminContext extends dmContext
{
	protected
	$moduleType,
	$moduleSpace;

  /**
   * Loads the diem services
   */
  public function loadServiceContainer()
  {
    $name = 'dm'.md5(dmProject::getRootDir().sfConfig::get('sf_app')).'ServiceContainer';
    
    $file = dmOs::join(sys_get_temp_dir(), $name.'.php');
     
    if (!sfConfig::get('sf_debug') && file_exists($file))
    {
      require_once $file;
      $sc = new $name;
    }
    else
    {
      // build the service container dynamically
      $sc = new sfServiceContainerBuilder();
      $loader = new sfServiceContainerLoaderFileYaml($sc);
      $loader->load(dmOs::join(sfConfig::get('dm_core_plugin'), 'config/dm/services.yml'));
     
      if (!$isDebug)
      {
        $dumper = new sfServiceContainerDumperPhp($sc);
        file_put_contents($file, $dumper->dump(array('class' => $name)));
      }
    }
    
    $this->serviceContainer = $sc;
  }
	
	public function isModuleAction($module, $action)
	{
		return $this->sfContext->getModuleName() === $module && $this->sfContext->getActionName() === $action;
	}

  /*
   * @return dmModule a module
   */
  public function getModule()
  {
    return dmModuleManager::getModuleOrNull($this->sfContext->getModuleName());
  }

  public function getModuleType()
  {
  	if (is_null($this->moduleType))
  	{
      $this->moduleType = dmModuleManager::getTypeBySlug($this->sfContext->getRequest()->getParameter('moduleTypeName'), false);
  	}
  	return $this->moduleType;
  }

  public function getModuleSpace()
  {
  	if (is_null($this->moduleSpace))
  	{
	  	if($moduleType = $this->getModuleType())
	  	{
	      $this->moduleSpace = $moduleType->getSpaceBySlug($this->sfContext->getRequest()->getParameter('moduleSpaceName'), false);
	  	}
	  	else
	  	{
	  		$this->moduleSpace = false;
	  	}
  	}
  	return $this->moduleSpace;
  }

  public function isListPage()
  {
    return in_array($this->sfContext->getActionName(), array('index'));
  }

  public function isFormPage()
  {
    return in_array($this->sfContext->getActionName(), array('edit', 'new', 'update', 'create'));
  }

  public static function createInstance(sfContext $sfContext)
  {
    return self::$instance = new self($sfContext);
  }

}