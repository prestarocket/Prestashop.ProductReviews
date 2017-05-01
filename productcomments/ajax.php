<?php 

require_once(dirname(__FILE__).'../../../config/config.inc.php');
require_once(dirname(__FILE__).'../../../init.php');

$method = Tools::getValue('method');
$product_id = Tools::getValue('id');
$shop_id = Tools::getValue('shop', 1);
$lang_id = Tools::getValue('lang', 1);
$id_customer = Tools::getValue('id_customer', 0);

function submitCommeent()
{

}

?>