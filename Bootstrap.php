<?php

class ManipleQueue_Bootstrap extends Maniple_Application_Module_Bootstrap
    implements Zend_Tool_Framework_Manifest_ProviderManifestable
{
    public function getModuleDependencies()
    {
        return array();
    }

    public function getResourcesConfig()
    {
        return require __DIR__ . '/configs/resources.config.php';
    }

    public function getProviders()
    {
        return require __DIR__ . '/configs/providers.config.php';
    }
}
