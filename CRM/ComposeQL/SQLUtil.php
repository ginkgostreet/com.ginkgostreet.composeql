<?php

//namespace \Civi\ComposeQL;

class CRM_ComposeQL_SQLUtil {

  /**
   *
   * @param array $where (by reference)
   * @return String or False if non-parenthetical
   */
  static function normalizeParenthetical(&$where) {
    $first = NULL;
    if (!is_array($where)) {
      throw new CRM_Exception('not array:'.__FILE__.__LINE__);
    }
    foreach($where as $key => $v) {
      if (is_numeric($key)) {
        // Necessary:
        // switch 'loose comparison' SUCKS!
        continue;
      }
      switch ($key) {
        case 'paren':
        case 'sub':
        case 'parenthesis':
        case 'parenthetical':
          $first = $key;
          break 2;
      }
    }

    if (isset($first)) {
      $parens = array('parenthetical','parenthesis', 'paren', 'sub');
      foreach ($parens as $paren) {
        if ($paren == $first) {
          continue;
        }
        if (array_key_exists($paren, $where)) {
          $tmp = $where[$paren];
          unset($where[$paren]);
          $where[$first] = $tmp;
        }
      }
      //clean-up array_merge_recursive() resultant arrays:
      if (is_array($where[$first])) {
        $where[$first] = array_pop($where[$first]);
      }
      return $first;
    }
    return FALSE;
  }

  /**
   * YAGNI
   * un-tested
   * @param type $WHERES
   * @param type $params
   * @param type $n
   */
  static function reNumberParams(&$WHERES, &$params, $n) {
    foreach ($WHERES as &$where) {
      $match = array();
      preg_match('#%\n', $where, $match);
      $oldIndex = $match[0][1];
      str_replace($match[0], '%'.$n, $where);
      $newParams[$n++] = $params[$oldIndex];
    }
    $params = $newParams;
  }

  /**
   * Creates SQL WHERE clause and params for CRM_Core_DAO::executeQuery();
   *
   * @param type $WHERES
   * Each test is an array and can have keys for:
   * 'field', 'value', 'type', 'conj'(unction), and 'comp'(arison).
   * Type defaults to 'String'. Conjunction defaults to 'AND'. Comparison defaults to '='.
   *
   * For parameter escaping and validation, supply 'value'.
   * Types should conform to CRM_Core_Util_Type::validate()
   *
   * Alternate syntaxes (passthrough): <pre>$WHERES = array(
   * array('field' => 'civicrm_volunteer_need.end_time', 'comp' => '> NOW()'),
   * array('conj' => 'OR', 'civicrm_volunteer_need.end_time IS NOT NULL'),
   * '`year` = 2016'
   * );</pre>
   * For parenthesis, wrap the clausees in an array and add a magic key to the
   * wrapper that specifies the conjunction, e.g.: 'parens' => 'AND'
   * Supported keys: parenthetical; paren; parenthesis; sub;
   *
   * @param int $n (optional) starting index for params array (needed for recursion)
   *
   * @return array('WHERE' => '...', 'params' => array())
   * $params = array( 1 => array( 'value', 'type')) // see CRM_Utils_Type::validate() re type
   * @throws CRM_Exception
   */
  static function parseWHEREs($WHERES, &$n=0) {
    $params = array();
    $clzWhere = null;
    foreach ($WHERES as $key => $where) {
      $conj = $paren = $recurse = NULL;
      $passthrough = FALSE;
      if (is_array($where)) {
        $recurse = self::normalizeParenthetical($where);
      } else {
        $passthrough = TRUE;
      }

      if ($recurse) {
        //explicit syntax for sub-clause
        $conj = $where[$recurse];
        unset($where[$recurse]);

        if (count($where) < 1) {
          // don't try to recurse on an extraneous (empty) paren clause.
          continue;
        }

        if (strtoupper($key) == 'AND' || strtoupper($key) == 'OR') {
        // lazy syntax for sub-clause
          $conj = $key;
        }

        $passthrough = TRUE;
        $conj = strtoupper($conj);

        $parsed = self::parseWHERES($where, $n);
        $params = array_merge($params, $parsed['params']);

        $where = "({$parsed['WHERE']})";
      }

      if (!$passthrough) {
        if (array_key_exists('conj', $where)) {
          $conj = strtoupper(trim($where['conj']));
        }

        if (array_key_exists('field', $where)) {
          if (!array_key_exists('comp', $where)) {
            $where['comp'] = '=';
            if (!array_key_exists('value', $where)) {
              throw new CRM_Exception("'value' array required: ".__FILE__.':'.__LINE__);
            }
          }

          if (array_key_exists('value', $where) ) {
            if(!array_key_exists('type', $where)) {
              $where['type'] = 'String';
            }

            $params[$n] = array($where['value'], $where['type']);
            $where = "{$where['field']} {$where['comp']} %{$n}";
            $n++;
          } else {
            // e.g. comp is  'IS NULL' or  '> NOW()'
            $where = "{$where['field']} {$where['comp']}";
          }
        } elseif (count($where) === 2) {
          // array('conj' => or, '<arbitrary where clause string>')
          unset($where['conj']);
          $where = array_pop($where);
        } elseif (count($where) === 1)  {
          // un-wrap overzealous array nesting
          $where = array_pop($where);
        }
      }

      if (!isset($conj)) {
        $conj = 'AND';
      }

      if (is_array($where)) {
        throw new CRM_Exception("oops - Array; check your array format:".__FILE__.':'.__LINE__.
          var_export($where, TRUE));
      }
      if (trim($where) == '') {
        throw new CRM_Exception("zero-length clause encountered; check your array format: ".__FILE__.':'.__LINE__);
      }

      $clzWhere .= (isset($clzWhere))? " $conj $where" : $where;
    }

    return (isset($clzWhere))? array('WHERE' => $clzWhere, 'params' => $params): NULL;
  }

  /**
   * Useful when you have two complex WHERE clauses and you want to define
   * the relationship between then (i.e. specify $paren).
   *
   * If simply catenating two Where clauses, this function merely provides some
   * syntactic sugar and ensures the proper array structures.
   *
   * @param Array $where see format in parseWHEREs()
   * @param Array $add
   * @param String $paren - add as parenthetical (specify AND/OR)
   */
  static function composeWhereClauses($where, $add, $paren=NULL) {
    if (!is_array($where) && !is_array($add)) {
      throw new CRM_Exception('Missing $where parameter to composeWhereClauses()');
    } elseif( is_array($add) && ( !is_array($where) || count($where) < 1 ) ) {
      return $add;
    } elseif (is_array($where) && ( !is_array($add) || count($add) < 1 ) ) {
      return $where;
    }
    if (isset($paren)) {
      if (array_key_exists('WHERES', $add)) {
        $add['WHERES']['paren'] = $paren;
      } else {
        $add['paren'] = $paren;
      }
    }
    if (array_key_exists('WHERES', $where)
      && !array_key_exists('WHERES', $add)) {
      $add = array('WHERES' => $add);
    }
    if (!array_key_exists('WHERES', $where)
      && array_key_exists('WHERES', $add)) {
      $add = $add['WHERES'];
    }

    if (array_key_exists('WHERES', $where)) {
      if ($paren) {
        $where['WHERES'][] = $add['WHERES'];
      } else {
        $where['WHERES'] = array_merge_recursive($where['WHERES'], $add['WHERES']);
      }
    } else {
      if ($paren) {
        $where[] = $add;
      } else {
        // TODO: this may be causing damage. Maybe don't use this if there are string-keys in the array.
        $where = array_merge_recursive($where, $add);
      }
    }
    return $where;
  }

  /**
   * Prepare query and params for CRM_Core_DAO::executeQuery().
   *
   * At minnimum, components must have SELECTS and (TABLES or JOINS).
   * You may also provide components for WHERES, ORDER_BYS, and GROUP_BYS.
   *
   * More details in parseWHEREs().
   *
   * WARNING: may contain bugs if not specifying both sides of join.
   *
   * <pre>createSqlStatement( array(
   * 'SELECTS' => array('civicrm_contact' => array('id', 'display_name')),
   * 'JOINS' => array(
   *    array(
   *      'left' => 'civicrm_contact'
   *      'right' => 'civicrm_value_organization_information_5',
   *      'join' => 'INNER JOIN',
   *      'on' => 'civicrm_contact.id = civicrm_value_organization_information_5.entity_id')
   *    )
   * ),
   * 'WHERES' => array(
   *    array('conj' => 'AND', 'field' => 'civicrm_contact.first', 'value' => $first, 'type' => 'String'),
   *    array('conj' => 'AND', 'field' => 'civicrm_contact.is_active', 'value' => TRUE, 'type' => 'Boolean')
   *   )
   * );</pre>
   *
   * @param array $components
   * @return array( 'sql' => ..., 'params' => ... ) for invocation of CRM_Core_DAO::executeQuery()
   */
  static function createSqlSelectStatement($components=array()) {
    if (array_key_exists('SELECTS', $components) &&
       (array_key_exists('TABLES', $components) || array_key_exists('JOINS', $components))
      ) { // good
    }else {
      // please come again
      throw new CRM_Exception('minnimum components missing: '.__FILE__.':'.__LINE__);
    }
    $SELECTS = CRM_Utils_Array::value('SELECTS', $components);
    $TABLES = CRM_Utils_Array::value('TABLES', $components, array());
    $JOINS = CRM_Utils_Array::value('JOINS', $components, array());
    $WHERES = CRM_Utils_Array::value('WHERES', $components, array());
    $GROUP_BYS = CRM_Utils_Array::value('GROUP_BYS', $components, array());
    $ORDER_BYS = CRM_Utils_Array::value('ORDER_BYS', $components, array());
    $APPEND = CRM_Utils_Array::value('APPEND', $components, NULL);

    $clzSelect = null;
    $clzFrom = null;
    $clzWhere = null;
    $clzGroupBy = null;
    $clzOrderBy = null;

    if (is_array($SELECTS)) {
      foreach ($SELECTS as $table => $columns) {
        $tmp = array();
        foreach ($columns as $col) {
          $tmp[] = "{$table}.{$col}";
        }
        if (isset($clzSelect)) {
          $clzSelect .= ', ';
        }
        $clzSelect .= join(', ', $tmp);
      }
    } else {
      $clzSelect = $SELECTS;
    }

    if (isset($JOINS)) {
      $clzFrom = self::parseJOINS($JOINS);
    }

    if (!isset($clzFrom)) {
      $clzFrom = implode(', ', array_merge($TABLES, $tables));
    }

    $parseWHERE = self::parseWHEREs($WHERES);
    $clzWhere = $parseWHERE['WHERE'];
    $params = $parseWHERE['params'];

    if (count($GROUP_BYS)) {
      $clzGroupBy = join(', ', $GROUP_BYS);
    }
    if (count($ORDER_BYS)) {
      $clzOrderBy = join(', ', $ORDER_BYS);
    }

    return array( 'sql' =>
      "SELECT {$clzSelect} FROM {$clzFrom}"
      . ((isset($clzWhere))? " WHERE {$clzWhere}" : '')
      . ((isset($clzGroupBy))? " GROUP BY {$clzGroupBy}" : '')
      . ((isset($clzOrderBy))? " ORDER BY {$clzOrderBy}" : '')
      . ((isset($APPEND))? " {$APPEND}": ''),
      'params' => $params
    );
  }

  static function parseJOINS($JOINS=array()) {
    $clzFrom = null;
    foreach ($JOINS as &$join) {
      $left = $right = NULL;
      if (isset($join['left']) && isset($join['right'])) {
        $left = $join['left'];
        $right = $join['right'];
        $clzFrom .= " {$join['left']} {$join['join']} {$join['right']} on {$join['on']}";
      } else { // unary
        $left = $right = NULL;
        $left = (isset($join['left'])) ? $join['left'] : FALSE;
        $right = (isset($join['right'])) ? $join['right'] : FALSE;
        // normalize to use 'right'
        $join['right'] = ($left) ? $left : $right;
        $clzFrom .= " {$join['join']} {$join['right']} on {$join['on']}";
      }
    }
    return $clzFrom;
  }

  /**
  * return a ComposeQL Query object as a string.
  **/
  static function debugComposeQLQuery($parameterizedQuery) {
    if (isset($parameterizedQuery['SELECTS'])) {
      $parameterizedQuery = CRM_ComposeQL_SQLUtil::createSqlSelectStatement($parameterizedQuery);
    }
    if (isset($parameterizedQuery['sql']) && isset($parameterizedQuery['params'])) {
      $parameterizedQuery =
        CRM_ComposeQL_DAO::composeQuery($parameterizedQuery['sql'],
          $parameterizedQuery['params']
          );
    }
    return $parameterizedQuery;
  }

  /**
   * Auto-resolve Join-order. Doesn't work.
   * @param type $JOINS
   * @throws CRM_Exception
   */
  static function buggyParseJoins($JOINS) {
      $tables = array();
      $unary = array();
      $binary = array();
      $tableCount = array();
      $unaryTableCount = array();
      foreach ($JOINS as &$join) {
        $left = $right = NULL;
        if (isset($join['left']) && isset($join['right'])) {
          $left = $join['left'];
          $right = $join['right'];
          if (array_key_exists($left, $tableCount)) {
            $tableCount[$left]++;
          } else {
            $tableCount[$left] = 1;
          }
          if (array_key_exists($right, $tableCount)) {
            $tableCount[$right]++;
          } else {
            $tableCount[$right] = 1;
          }
          $join['binary'] = 1;
          $binary[] = $join;
        } else { // unary
          $left = $right = NULL;
          $left = (isset($join['left'])) ? $join['left'] : FALSE;
          $right = (isset($join['right'])) ? $join['right'] : FALSE;
          // normalize to use 'right'
          $join['right'] = ($left) ? $left : $right;
          if (array_key_exists($join['right'], $tableCount)) {
            $tableCount[$join['right']]++;
          } else {
            $tableCount[$join['right']] = 1;
          }
          if (array_key_exists(($join['right']), $unaryTableCount)) {
            $unaryTableCount[$join['right']]++;
          } else {
            $unaryTableCount[$join['right']] = 1;
          }
          $join['unary'] = 1;
          $unary[] = $join;
        }
      }
      reset($JOINS);
      $primary = FALSE;
      $maxTries = count($JOINS);
//      $log = '';
      $done = FALSE;
      while (count($JOINS) > 0) {
        $process = FALSE;
        $join = array_pop($JOINS);

        /**
         * TODO: try new strategy - it is highly problematic supporting joins that
         * don't specify left and right. Try ...
         *  * Look for join with right-match and un-matched left - make first
         *  * Build chain of matching joins
         *  * for each right-join, search for multiple left-matches
         *  * if remaining join, throw exception
         *
         * ... or don't try to auto-order joins at all. Idea was to support
         * auto-composing querries.
         *
         */
//        $log .= 'SHIFT'.var_export($join, TRUE)."\n ~~~~".count($JOINS);
        // decide to process or postpone
        if (
          ( !$primary && $join['unary'] === 1)
          || ( // binary:
            !$primary
              && ($tableCount[$join['left']] > 1
              && $tableCount[$join['right']] > 1)
            || ( $primary && ($tableCount[$join['left']] > 1
                || $tableCount[$join['right']] > 1 )
              )
          || (
            $primary
            && $join['binary'] === 1
            && ($tableCount[$join['left']] < 2
              || $tableCount[$join['right']] < 2)
            )
          )
        ) {//postpone
          if ($join['pushed'] > $maxTries) {
            // failsafe
            $process = TRUE;
          } else {
            if (isset($join['pushed'])) {
              $join['pushed']++;
            } else {
              $join['pushed'] = 1;
            }
            $log .= 'POSTPONE:'.var_export($join, TRUE);
            array_unshift($JOINS, $join);
            next($JOINS);
          }
        } else {
          $process = TRUE;
          $primary = ($join['binary'] === 1) ? TRUE: $primary;
$log .= 'PROCESS:'.var_export($join, TRUE);
        }

        if ($process) {
          if (!is_array($join)) {
            throw new CRM_Exception('Check your array format'.__FILE__.__LINE__);
          }
          if (!isset($join['join'])) {
            $join['join'] = 'INNER JOIN';
          }

          $left = $right = $both = NULL;

          if ($join['binary'] && !isset($clzFrom)) {
            $both = true;
          } else if($join['binary']) {
            if (in_array($join['left'], $tables)) {
              $right = $join['right'];
            }
            if (in_array($join['right'], $tables)) {
              $left = $join['left'];
            }
            $both = ($left && $right)? TRUE : FALSE ;
          }

          if ($both) {
            $clzFrom .= " {$join['left']} {$join['join']} {$join['right']} on {$join['on']}";
            $tables[] = $join['left'];
            $tables[] = $join['right'];
          } else {
            if ($join['binary'] && in_array($join['right'], $tables)) {
              $table = $join['left'];
            } else {
              $table = $join['right'];
            }
            $clzFrom .= " {$join['join']} {$table} on {$join['on']}";
            $tables[] = $table;
          }
        }
      } // while
  }

}
