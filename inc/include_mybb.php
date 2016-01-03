<?php

// load mybb
//$mybb_root=$wp_root.'/../mybb'; // FIXME wp_root

global $mybb_root;
$mybb_root=trim($mybb_root);

define('IN_MYBB', NULL);
require_once($mybb_root.'/global.php');
require_once('inc/class.MyBBIntegrator.php');

$MyBBI=new MyBBIntegrator($mybb, $db, $cache, $plugins, $lang, $config);

?>