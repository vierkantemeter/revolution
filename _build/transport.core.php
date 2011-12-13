<?php
/**
 * Builds the MODX core transport package.
 *
 * @package modx
 * @subpackage build
 */
$config = array();
$isCommandLine = php_sapi_name() == 'cli';
if ($isCommandLine) {
    foreach ($argv as $idx => $argv) {
        $p = explode('=',ltrim($argv,'--'));
        if (isset($p[1])) {
            $config[$p[0]] = $p[1];
        }
    }
}
require_once dirname(__FILE__).'/moddistribution.class.php';
$distribution = new modDistribution($config);
$distribution->initialize();
$distribution->build();
return '';
