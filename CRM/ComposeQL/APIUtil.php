<?php

class CRM_ComposeQL_APIUtil {

/**
 * api.CustomField.get with chained CustomGroup and OptionGroup/Value.
 * returns a subset of the API result fields, relevant to schemas.
 * Chained calls are accessible via
 * <ul><li>custom_group</li><li>option_group</li><li>option_group > Options</li>
 *
 * @param type $fieldName
 * @return array()
 */
  static function getCustomFieldSchema($groupName, $fieldName) {
    $result = civicrm_api3('CustomField', 'get',
      array(
        'sequential' => 0,
        'name' => $fieldName,
        'is_active' => '1',
        'return' => array(
          'column_name',
          'custom_group_id',
          'id',
          'name',
          'label',
          'data_type',
          'html_type',
          'is_required',
          'option_group_id',
        ),
        'custom_group_id.name' => $groupName,
        'api.CustomGroup.get' => array('id' => '$value.custom_group_id'),
        'api.OptionGroup.get' => array('id' => '$value.option_group_id'),
        'api.OptionValue.get' => array(
          'option_group_id' => '$value.option_group_id',
          'is_active' => 1,
          'sequential' => 0,
          'return' => array('name','value')
        )
      )
    );

    $schema = self::extractFields($result,
      array(
        'column_name',
        'custom_group_id',
        'id',
        'name',
        'label',
        'data_type',
        'html_type',
        'is_required',
        'option_group_id',
      )
    );
    $schema['api_column_name'] = "custom_{$schema['id']}";

    $schema['custom_group'] = self::extractChainedApi('api.CustomGroup.get', $result,
      array(
        'id',
        'name',
       'table_name',
        'extends',
        'weight',
        'is_multiple',
        'is_active'
      )
    );

    if (array_key_exists('option_group_id', $schema)) {
      $schema['option_group'] = self::extractChainedApi('api.OptionGroup.get', $result);
      $result = self::extractChainedApi('api.OptionValue.get', $result);
      foreach($result as $option) {
        $options[$option['value']] = $option['name'];
      }
      $schema['option_group']['options'] = $options;
    }

    return $schema;
 }

 /**
  * fetch the values array from an api chain call
  *
  * @param string $chainKey identifies the API-Chain call
  * @param api $result civicrm api result
  * @param array $keys (optional) fields to return, all if empty
  * @return array api $result['values']
  */
  static function extractChainedApi($chainKey, $result, $keys=array()) {
    $chained = array_pop(self::extractFields($result, array($chainKey)));
    return self::extractFields($chained, $keys);
  }

  /**
   * returns the fields specified, from an api $result.
   * Can only return fields that are siblings.
   * Uses 'values' array if it is present.
   *
   * @param api civicrm api result
   * @param array $keys (optional) fields to return, all if empty
   * @return array $result['values']
   */
  static function extractFields($result, $keys=array()) {
    if (!is_array($result)) {
      return array();
    }
    $_keys = array_flip($keys);
    $items = (array_key_exists('values', $result)) ? $result['values'] : $result;
    $return = array();
    foreach ( $items as $key => $item ) {
      $return[$key] = ( count($_keys)>0 )
        ? array_intersect_key($item, $_keys)
        : $item ;
    }
    return (count($return) == 1 && is_array(current($return)))
      ? array_pop($return) : $return;
  }

}

