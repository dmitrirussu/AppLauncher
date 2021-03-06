<?php
/**
 * Created by Dmitri Russu. <dmitri.russu@gmail.com>
 * Date: 15.04.2014
 * Time: 22:38
 * ${NAMESPACE}${NAME} 
 */

namespace OmlManager\ORM\Query\DML\Clauses;

use OmlManager\ORM\Models\Reader;
use OmlManager\ORM\Query\Expression\Expression;
use OmlManager\ORM\Query\Expression\ExpressionInterface;
use OmlManager\ORM\Query\Types\ValueTypeValidator;
use OmlManager\ORM\SDB\SDBManagerConnections;

class UpdateClause implements DMLClauseInterface, DMLUpdateClauseInterface {

	const TABLE_NAME = '[TABLE_NAME]';
	const FIELD_AND_VALUES = '[FIELDS_AND_VALUES]';
	const STATEMENT = '[STATEMENT]';

	private $_UPDATE = 'UPDATE [TABLE_NAME] SET [FIELDS_AND_VALUES] WHERE [STATEMENT]';
	private $_STATEMENT = '';

	private $models = array();
	private $modelsReader = array();
	private $fieldsValuesAffect = array();


	/**
	 * @var Expression
	 */
	private $expressionObject;

	public function model($object, $alias = null) {
		$this->models[] = $object;
		$this->modelsReader[] = new Reader($object);

		return $this;
	}

	public function models(array $models) {

		$this->models = $models;

		if ( $models ) {
			foreach($models AS $model) {
				$this->modelsReader[] = new Reader($model);
			}
		}

		return $this;
	}



	public function setFieldsAffect(array $fieldsValue) {
		$this->fieldsValuesAffect = $fieldsValue;

		return $this;
	}


	public function getFieldsAffect() {
		return $this->fieldsValuesAffect;
	}


	public function expression(ExpressionInterface $exp) {
		$this->expressionObject = $exp;
		$this->expressionObject->checkValuesTypeByModels($this->modelsReader);

		$this->_STATEMENT = $exp->getExpression();

		return $this;
	}


	public function flush() {

		if ( $this->models ) {


			foreach($this->models AS $model) {
				/**
				 * @var $modelReader Reader
				 */
				$modelReader = new Reader($model);

				$tableName = $modelReader->getModelDataBaseName().'.'.$modelReader->getModelTableName();

				$fields = $modelReader->getModelPropertiesTokens();
				$statements = array();
				$expressionObject = $this->expressionObject;

				if ( $fields && !$this->getFieldsAffect()) {
					foreach($fields AS $field) {
						if ( !isset($field['primary_key']) || empty($expressionObject)) {

							$propertyValue = $modelReader->getValueByFieldName($field['field']);

							if ( $propertyValue !== null ) {
								$statements[':'.$field['field']] = $propertyValue;
								$fieldValues[] = "`{$field['field']}`" . '= :'.$field['field'];
							}
						}
					}
				}
				else {
					foreach ($modelReader->getModelPropertiesTokens() AS $modelField) {
						foreach($this->getFieldsAffect() AS $field => $value) {
							if ( strpos($field, $modelField['field']) === false) {
								continue;
							}

							$fieldCheck = ltrim(str_replace($modelField['field'], '', $field), '_');
							$catToInt = (int)$fieldCheck;

							if ( $fieldCheck !== '' && $catToInt === 0) {
								continue;
							}

							$type = $modelField['type'];
							$valueType = new ValueTypeValidator($value, $type, $modelField['field']);

							$fieldMacros = ':affect_'.$field;
							$statements[$fieldMacros] =  array('value' => $valueType->getValue(), 'type' => $valueType->getPDOFieldType());
							$fieldValues[] = "`{$field}`" . '= :affect_'.$field;
						}
					}
				}


				if ( $this->expressionObject ) {
					$statements = array_merge($statements, $this->expressionObject->getPreparedStatement());

					$this->_UPDATE = str_replace(array(self::TABLE_NAME, self::FIELD_AND_VALUES, self::STATEMENT),
						array($tableName, implode(', ', array_filter($fieldValues)), $this->_STATEMENT), $this->_UPDATE);
				}
				else {
					$this->_UPDATE = str_replace(array(self::TABLE_NAME, self::FIELD_AND_VALUES, self::STATEMENT),
						array($tableName, implode(', ', array_filter($fieldValues)), $modelReader->getModelPrimaryKey().'= :'.$modelReader->getModelPrimaryKey()), $this->_UPDATE);
				}

				$result = SDBManagerConnections::getManager($modelReader->getModelDataDriverConfName(), 1)->getDriver()->execute($this->_UPDATE, $statements);

				if ( empty($result) ) {

					return false;
				}
			}
		}

		return true;
	}
}
