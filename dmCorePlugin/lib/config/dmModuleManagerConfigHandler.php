<?php

/*
 * This file is part of the symfony package.
 * (c) 2004-2006 Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * @package    symfony
 * @subpackage config
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfRoutingConfigHandler.class.php 21923 2009-09-11 14:47:38Z fabien $
 */
class dmModuleManagerConfigHandler extends sfYamlConfigHandler
{
  protected
  $config,
  $modules,
  $projectModules;
  
  /**
   * Executes this configuration handler.
   *
   * @param array $configFiles An array of absolute filesystem path to a configuration file
   *
   * @return string Data to be written to a cache file
   *
   * @throws sfConfigurationException If a requested configuration file does not exist or is not readable
   * @throws sfParseException         If a requested configuration file is improperly formatted
   */
  public function execute($configFiles)
  {
    $config = sfFactoryConfigHandler::getConfiguration(ProjectConfiguration::getActive()->getConfigPaths('config/factories.yml'));
    
    $options = $config['module_manager']['param'];
    $managerClass = $config['module_manager']['class'];
    
    $this->parse($configFiles);
    
    $this->validate();
    
    $this->processHierarchy();
    
    $this->sortModuleTypes();
    
    $data = array();

    $data[] = sprintf('$manager = new %s();', $managerClass);

    $data[] = sprintf('$modules = $projectModules = $modelModules = $types = array();');

    foreach($this->config as $typeName => $typeConfig)
    {
      $data[] = sprintf('$types[\'%s\'] = new %s();', $typeName, $options['type_class']);

      $data[] = sprintf('$typeSpaces = array();');

      foreach($typeConfig as $spaceName => $modulesConfig)
      {
        $data[] = sprintf('$typeSpaces[\'%s\'] = new %s();', $spaceName, $options['space_class']);

        $data[] = sprintf('$spaceModules = array();');

        foreach($modulesConfig as $moduleKey => $moduleConfig)
        {
          $moduleClass = $options[$moduleConfig['is_project'] ? 'module_node_class' : 'module_base_class'];

          if ($moduleConfig['is_project'])
          {
            $moduleReceivers = sprintf('$modules[\'%s\'] = $projectModules[\'%s\'] = $spaceModules[\'%s\']', $moduleKey, $moduleKey, $moduleKey);
          }
          else
          {
            $moduleReceivers = sprintf('$modules[\'%s\'] = $spaceModules[\'%s\']', $moduleKey, $moduleKey);
          }
          
          $data[] = sprintf('%s = new %s(\'%s\', $typeSpaces[\'%s\'], %s);', $moduleReceivers, $moduleClass, $moduleKey, $spaceName, $this->getExportedModuleOptions($moduleKey, $moduleConfig));
        
          if ($moduleConfig['model'])
          {
            $data[] = sprintf('$modelModules[\'%s\'] = \'%s\';', $moduleConfig['model'], $moduleKey);
          }
        }

        $data[] = sprintf('$typeSpaces[\'%s\']->initialize(\'%s\', $types[\'%s\'], $spaceModules);', $spaceName, $spaceName, $typeName);
        
        $data[] = 'unset($spaceModules);';
      }

      $data[] = sprintf('$types[\'%s\']->initialize(\'%s\', $typeSpaces);', $typeName, $typeName);
      
      $data[] = 'unset($typeSpaces);';
    }

    $data[] = sprintf('$manager->load($types, $modules, $projectModules, $modelModules);');
    
    $data[] = 'unset($types, $modules, $projectModules, $modelModules);';

    $data[] = 'return $manager;';
    
    unset($this->config, $this->modules, $this->projectModules);

    return sprintf("<?php\n".
                 "// auto-generated by dmModuleManagerConfigHandler\n".
                 "// date: %s\n%s", date('Y/m/d H:i:s'), implode("\n", $data)
    );
  }
  
  protected function sortModuleTypes()
  {
    // We generally want writer modules first
    if($projectModules = dmArray::get($this->config, 'Project'))
    {
      unset($this->config['Project']);
      
      $this->config = array_merge(array('Project' => $projectModules), $this->config);
    }
  }
  
  protected function validate()
  {
    if (!isset($this->modules['main']))
    {
      $this->throwException('No main module');
    }
    
    if (!isset($this->config['Project']))
    {
      $this->throwException('No Project module type');
    }
    
    foreach($this->modules as $key => $module)
    {
      if ($key != dmString::modulize($key))
      {
        $this->throwModulizeException($key);
      }

      foreach(dmArray::get($module, 'components', array()) as $componentKey => $component)
      {
        if (is_numeric($componentKey))
        {
          $componentKey = $component;
        }
        
        if ($componentKey != dmString::modulize($componentKey))
        {
          $this->throwModulizeException($componentKey);
        }
      }
      
      if($parentKey = dmArray::get($module, 'parent_key'))
      {
        if ($parentKey == $key)
        {
          $this->throwException('module %s is it\'s own parent...');
        }
        if (!isset($this->modules[$parentKey]))
        {
          $this->throwException('module %s has a parent that do not exist : %s', $key, $parentKey);
        }
      }
    }
    
    $moduleKeys = array();
    foreach($this->config as $typeName => $typeConfig)
    {
      foreach($typeConfig as $spaceName => $modulesConfig)
      {
        foreach($modulesConfig as $moduleKey => $moduleConfig)
        {
          if (in_array($moduleKey, $moduleKeys))
          {
            $this->throwException('The module '.$moduleKey.' is declared twice');
          }
          else
          {
            $moduleKeys[] = $moduleKey;
          }
        }
      }
    }
  }
  
  protected function throwException($message)
  {
    $params = func_get_args();
    
    if (count($params) > 1)
    {
      ob_start();
      call_user_func_array('printf', $params);
      $message = ob_get_clean();
    }
    
    $fullMessage = 'Error in config/dm/modules.yml : '.$message;
    
    throw new sfConfigurationException($fullMessage);
  }
  
  protected function throwModulizeException($string)
  {
    return $this->throwException(sprintf('The word "%s" must follow the symfony module convention : "%s"',
      $string, dmString::modulize($string)
    ));
  }

  protected function getExportedModuleOptions($key, $options)
  {
    $isProject = $options['is_project'];
    unset($options['is_project']);
    
    if ($isProject && !empty($options['components']))
    {
      //export actions properly
      
      $componentsConfig = $options['components'];
      
      $options['components'] = '__DM_MODULE_COMPONENTS_PLACEHOLDER__';
      
      $exported = var_export($options, true);
      
      $components = 'array(';

      foreach($componentsConfig as $componentKey => $componentConfig)
      {
        if (is_integer($componentKey))
        {
          $componentKey = $componentConfig;
          $componentConfig = array();
        }
        
        if (empty($componentConfig['name']))
        {
          $componentConfig['name'] = dmString::humanize($componentKey);
        }
    
        if (empty($componentConfig['type']))
        {
          if (strncmp($componentKey, 'list', 4) === 0)
          {
            $componentConfig['type'] = 'list';
          }
          elseif (strncmp($componentKey, 'show', 4) === 0)
          {
            $componentConfig['type'] = 'show';
          }
          elseif (strncmp($componentKey, 'form', 4) === 0)
          {
            $componentConfig['type'] = 'form';
          }
          else
          {
            $componentConfig['type'] = 'simple';
          }
        }
        
        $components .= sprintf('\'%s\' => new dmModuleComponent(\'%s\', %s), ', $componentKey, $componentKey, var_export($componentConfig, true));
      }

      $components .= ')';
      
      $exported = str_replace('\'__DM_MODULE_COMPONENTS_PLACEHOLDER__\'', $components, $exported);
    }
    else
    {
      $exported = var_export($options, true);
    }
    
    return $exported;
  }
  
  protected function getModuleChildrenKeys($key)
  {
    $children = array();
    
    foreach($this->projectModules as $moduleConfig)
    {
      if ($moduleConfig['parent'] === $this->key)
      {
        $children[$otherModule->getKey()] = $otherModule;
      }
    }
  }

  protected function parse($configFiles)
  {
    // parse the yaml
    $config = self::getConfiguration($configFiles);
    
    $this->config = array();
    $this->modules = array();
    $this->projectModules = array();
    
    $pluginModules = $this->getPluginModules($configFiles);
    
    foreach($config as $typeName => $typeConfig)
    {
      $this->config[$typeName] = array();
      $isInProject = $typeName === 'Project';
      
      foreach($typeConfig as $spaceName => $spaceConfig)
      {
        $this->config[$typeName][$spaceName] = array();
        
        foreach((array) $spaceConfig as $moduleKey => $moduleConfig)
        {
          $moduleKey = dmString::modulize($moduleKey);
          
          if (isset($this->modules[$moduleKey]))
          {
            continue;
          }
          
          $plugin = dmArray::get($pluginModules, $moduleKey, false);
    
          $moduleConfig = $this->fixModuleConfig($moduleKey, $moduleConfig, $isInProject, $plugin);
          
          $this->modules[$moduleKey] = $moduleConfig;
          
          if ($moduleConfig['is_project'])
          {
            $this->projectModules[$moduleKey] = $moduleConfig;
          }
          
          $this->config[$typeName][$spaceName][$moduleKey] = $moduleConfig;
        }
      }
    }
  }
  
  /*
   * Returns plugins modules,
   * not defined in the project but in a plugin
   */
  protected function getPluginModules(array $configFiles)
  {
    $pluginModules = array();
    
    foreach($configFiles as $configFile)
    {
      if (0 === strpos($configFile, sfConfig::get('sf_plugins_dir')) || (0 === strpos($configFile, dm::getDir()) && !dmProject::isInProject($configFile)))
      {
        $pluginName = basename(str_replace('/config/dm/modules.yml', '', $configFile));
        
        foreach((array)sfYaml::load(file_get_contents($configFile)) as $typeName => $typeConfig)
        {
          foreach($typeConfig as $spaceName => $spaceConfig)
          {
            foreach($spaceConfig as $moduleKey => $moduleConfig)
            {
              $pluginModules[$moduleKey] = $pluginName;
            }
          }
        }
      }
    }
    
    return $pluginModules;
  }
  
  protected function fixModuleConfig($moduleKey, $moduleConfig, $isInProject, $plugin)
  {
    /*
     * Extract plural from name
     * name | plural
     */
    if (!empty($moduleConfig['name']))
    {
      if (strpos($moduleConfig['name'], '|'))
      {
        list($moduleConfig['name'], $moduleConfig['plural']) = explode('|', $moduleConfig['name']);
      }
    }
    else
    {
      $moduleConfig['name'] = dmString::humanize($moduleKey);
    }
    
    if (empty($moduleConfig['model']))
    {
      $candidateModel = dmString::camelize($moduleKey);
      
      $model = class_exists('Base'.$candidateModel, true) ?
      Doctrine_Core::isValidModelClass($candidateModel) ? $candidateModel : false
      : false;
    }
    else
    {
      $model = $moduleConfig['model'];
    }

    // BC "actions" deprecated keyword becomes "components"
    if(isset($moduleConfig['actions']))
    {
      $moduleConfig['components'] = $moduleConfig['actions'];
      unset($moduleConfig['actions']);
    }
    
    $moduleOptions = array(
      'name' =>       (string) trim($moduleConfig['name']),
      'plural' =>     (string) trim(empty($moduleConfig['plural']) ? ($model ? dmString::pluralize($moduleConfig['name']) : $moduleConfig['name']) : $moduleConfig['plural']),
      'model' =>      $model,
      'credentials' => isset($moduleConfig['credentials']) ? trim($moduleConfig['credentials']) : null,
      'underscore'  => (string) dmString::underscore($moduleKey),
      'is_project'  => (boolean) dmArray::get($moduleConfig, 'project', $isInProject),
      'plugin'      => dmArray::get($moduleConfig, 'plugin', $plugin),
      'has_admin'   => (boolean) dmArray::get($moduleConfig, 'admin', $model || !$isInProject),
      'has_front'   => (boolean) dmArray::get($moduleConfig, 'front', true),
      'components'  => dmArray::get($moduleConfig, 'components', array())
    );
    
    if ($moduleOptions['is_project'])
    {
      $moduleOptions = array_merge($moduleOptions, array(
        'parent_key' => dmArray::get($moduleConfig, 'parent') ? dmString::modulize(trim(dmArray::get($moduleConfig, 'parent'))) : null,
        'has_page'   => (boolean) dmArray::get($moduleConfig, 'page', false)
      ));
    }
    
    // fix non array action filters
    foreach($moduleOptions['components'] as $componentKey => $componentConfig)
    {
      if(is_array($componentConfig) && array_key_exists('filters', $componentConfig) && !is_array($componentConfig['filters']))
      {
        $moduleOptions['components'][$componentKey]['filters'] = array($componentConfig['filters']);
      }
    }
    
    return $moduleOptions;
  }
  
  protected function processHierarchy()
  {
    foreach($this->config as $typeName => $typeConfig)
    {
      foreach($typeConfig as $spaceName => $spaceConfig)
      {
        foreach($spaceConfig as $moduleKey => $moduleConfig)
        {
          if (!$moduleConfig['is_project'])
          {
            continue;
          }
          
          $moduleConfig['children_keys'] = $this->getChildrenKeys($moduleKey);
          
          $moduleConfig['path_keys'] = $this->getPathKeys($moduleKey);
          
          $this->config[$typeName][$spaceName][$moduleKey] = $moduleConfig;
        }
      }
    }
  }
  
  protected function getChildrenKeys($moduleKey)
  {
    $childrenKeys = array();
    
    foreach($this->projectModules as $otherModuleKey => $otherModuleConfig)
    {
      if ($otherModuleConfig['parent_key'] === $moduleKey)
      {
        $childrenKeys[] = $otherModuleKey;
      }
    }
    
    return $childrenKeys;
  }
  
  protected function getPathKeys($moduleKey)
  {
    $pathKeys = array();

    $ancestorModuleKey = $moduleKey;
    while($ancestorModuleKey = $this->projectModules[$ancestorModuleKey]['parent_key'])
    {
      $pathKeys[] = $ancestorModuleKey;
    }

    return array_reverse($pathKeys);
  }
  
  /**
   * @see sfConfigHandler
   */
  static public function getConfiguration(array $configFiles)
  {
    return self::parseYamls($configFiles);
  }
}