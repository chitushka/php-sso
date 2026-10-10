<?php

namespace app\models;

use yii\db\ActiveRecord;

abstract class ValidatedActiveRecord extends ActiveRecord
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function rules(): array
    {
        $rules = [];
        foreach (static::getTableSchema()->columns as $name => $column) {
            if ($column->autoIncrement) {
                continue;
            }
            if (
                !$column->allowNull
                && !$column->isPrimaryKey
                && $column->defaultValue === null
            ) {
                $rules[] = [[$name], 'required', 'isEmpty' => static function ($value): bool {
                    return $value === null;
                }];
            }
            if (
                (property_exists($column, 'dimension') && $column->dimension > 0)
                || in_array($column->dbType, ['json', 'jsonb'], true)
            ) {
                $rules[] = [[$name], 'safe'];
            } elseif ($column->dbType === 'uuid') {
                $rules[] = [[$name], 'match', 'pattern' => self::UUID_PATTERN, 'skipOnEmpty' => true];
            } elseif ($column->type === 'string' || $column->type === 'text') {
                $rule = [[$name], 'string'];
                if ($column->size !== null) {
                    $rule['max'] = $column->size;
                }
                $rules[] = $rule;
            } elseif ($column->type === 'boolean') {
                $rules[] = [[$name], 'boolean'];
            } elseif (in_array($column->type, ['integer', 'smallint', 'bigint'], true)) {
                $rules[] = [[$name], 'integer'];
            } elseif (in_array($column->type, ['decimal', 'double', 'float', 'money'], true)) {
                $rules[] = [[$name], 'number'];
            } else {
                $rules[] = [[$name], 'safe'];
            }
        }

        return $rules;
    }
}
