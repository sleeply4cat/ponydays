<?php
/*-------------------------------------------------------
*
*   LiveStreet Engine Social Networking
*   Copyright © 2008 Mzhelskiy Maxim
*
*--------------------------------------------------------
*
*   Official site: www.livestreet.ru
*   Contact e-mail: rus.engine@gmail.com
*
*   GNU General Public License, version 2:
*   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*
---------------------------------------------------------
*/

namespace Engine\Modules\Validate;

/**
 * CBooleanValidator class file.
 *
 * @author    Qiang Xue <qiang.xue@gmail.com>
 * @link      http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2011 Yii Software LLC
 * @license   http://www.yiiframework.com/license/
 */

use Engine\LS;
use Engine\Modules\ModuleLang;

/**
 * Валидатор булевых значений
 *
 * @package engine.modules.validate
 * @since   1.0
 */
class ValidatorBoolean extends Validator
{
    /**
     * Значение true
     *
     * @var mixed
     */
    public $trueValue = '1';
    /**
     * Значение false
     *
     * @var mixed
     */
    public $falseValue = '0';
    /**
     * Строгое сравнение с учетом типов
     *
     * @var bool
     */
    public $strict = false;
    /**
     * Допускать или нет пустое значение
     *
     * @var bool
     */
    public $allowEmpty = true;

    /**
     * Запуск валидации
     *
     * @param mixed $sValue Данные для валидации
     *
     * @return bool|string
     */
    public function validate($sValue)
    {
        if ($this->allowEmpty && $this->isEmpty($sValue)) {
            return true;
        }
        if (!$this->strict && $sValue != $this->trueValue && $sValue != $this->falseValue
            || $this->strict
            && $sValue !== $this->trueValue
            && $sValue !== $this->falseValue
        ) {
            return $this->getMessage(
                LS::Make(ModuleLang::class)->Get('validate_boolean_invalid', null, false),
                'msg',
                ['true' => $this->trueValue, 'false' => $this->falseValue]
            );
        }

        return true;
    }
}
