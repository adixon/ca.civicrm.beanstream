<?php

require_once 'beanstream.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function beanstream_civicrm_config(&$config) {
  _beanstream_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 */
function beanstream_civicrm_install() {
  return _beanstream_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 */
function beanstream_civicrm_enable() {
  return _beanstream_civix_civicrm_enable();
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
      'user_name_label' => 'Merchant Id',
      'password_label' => 'API access passcode',
      'url_site_default'=> 'https://www.beanstream.com/scripts/process_transaction.asp',
      'url_recur_default' => 'https://www.beanstream.com/scripts/process_transaction.asp',
      'url_site_test_default' => 'https://www.beanstream.com/scripts/process_transaction.asp',
      'url_recur_test_default' => 'https://www.beanstream.com/scripts/process_transaction.asp',
      'is_recur' => 1,
      'payment_type' => 1
    ),
  );

//   return _beanstream_civix_civicrm_managed($entities);
}

// /**
//  * Implements hook_civicrm_entityTypes().
//  *
//  * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
//  */
// function beanstream_civicrm_entityTypes(&$entityTypes) {
//   _beanstream_civix_civicrm_entityTypes($entityTypes);
// }
