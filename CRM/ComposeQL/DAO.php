<?php

//namespace Civi\ComposeQL;

class CRM_ComposeQL_DAO extends CRM_Core_DAO {
  /**
   * Invokes createSqlSelectStatement()
   * and CRM_Core_DAO::executeQuery().
   *
   * Builds an array based on specified fields.
   *
   * @param array $sqlParts array(
        'SELECTS' => $select,
        'JOINS' => $joins,
        'WHERES' => $where,
      )
   * @param array $returnFields - fields to return;
   *   Optional alias syntax: array('field' => 'alias')
   * @return array of specified fields, or all
   */
  static function fetchSelectQuery($sqlParts, $returnFields=NULL) {
    $query = CRM_ComposeQL_SQLUtil::createSqlSelectStatement($sqlParts);

    $dao = self::executeQuery($query['sql'], $query['params']);

    while ($dao->fetch()) {
      $row = array();
      if (isset($returnFields)) {
        foreach ($returnFields as $field => $alias) {
          if (is_numeric($field)) {
            $field = $alias;
          }
          $row[$alias] = $dao->$field;
        }
      } else {
        $row = $dao->toArray();
      }
      $result[] = $row;
    }

    return $result;
  }

}

