<?php

/*
 * This file is part of ibrand/EC-Open-Server.
 *
 * (c) 果酱社区 <https://guojiang.club>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GuoJiangClub\EC\Open\Server\Transformers;

class GoodsQuestionTransformer extends BaseTransformer
{
    public static $excludeable = [
        'code',
    ];

    public function transformData($model)
    {
        return array_except($model->toArray(), self::$excludeable);
    }
}