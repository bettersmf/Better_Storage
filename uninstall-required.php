<?php
if (!defined('SMF')) die('No direct access.');
global $sourcedir;
require_once($sourcedir.'/Subs.php');
updateSettings(array('betterstorage_installed'=>0));
remove_integration_function('integrate_admin_areas','betterstorage_integrate_admin_areas',true,'$sourcedir/BetterStorage.php');
remove_integration_function('integrate_assign_attachments','betterstorage_integrate_assign_attachments',true,'$sourcedir/BetterStorage.php');
remove_integration_function('integrate_remove_attachments','betterstorage_integrate_remove_attachments',true,'$sourcedir/BetterStorage.php');
remove_integration_function('integrate_pre_download_request','betterstorage_pre_download_request',true,'$sourcedir/BetterStorage.php');
updateSettings(array('betterstorage_integration_attachments'=>0));
