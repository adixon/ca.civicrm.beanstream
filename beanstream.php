<?php

require_once 'beanstream.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function beanstream_civicrm_config(&$config) {
  _beanstream_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function beanstream_civicrm_xmlMenu(&$files) {
  _beanstream_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function beanstream_civicrm_install() {
  return _beanstream_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function beanstream_civicrm_uninstall() {
  return _beanstream_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function beanstream_civicrm_enable() {
  return _beanstream_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function beanstream_civicrm_disable() {
  return _beanstream_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function beanstream_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _beanstream_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function beanstream_civicrm_managed(&$entities) {

  $entities[] = array(
    'module' => 'ca.civicrm.beanstream',
    'name' => 'Beanstream',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'Beanstream',
      'title' => 'Beanstream',
      'description' => 'Beanstream Payment Processor',
      'class_name' => 'Payment_Beanstream',
      'billing_mode' => 'form',
      'user_name_label' => 'Merchant ID',
      'password_label' => 'API access passcode',
      'url_site_default'=> 'https://www.beanstream.com/scripts/process_transaction.asp',
      'url_recur_default' => 'https://www.beanstream.com/scripts/process_transaction.asp',
      'url_site_test_default' => 'https://www.beanstream.com/scripts/process_transaction.asp',
      'url_recur_test_default' => 'https://www.beanstream.com/scripts/process_transaction.asp',
      'is_recur' => 1,
      'payment_type' => 1
    ),
  );

  return _beanstream_civix_civicrm_managed($entities);
}
