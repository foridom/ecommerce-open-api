<?php

namespace GuoJiangClub\EC\Open\Backend\Store\Model;

use Illuminate\Database\Eloquent\Model;
use Prettus\Repository\Contracts\Transformable;
use Prettus\Repository\Traits\TransformableTrait;

class GoodsPhoto extends Model implements Transformable
{
    use TransformableTrait;

    const PHOTO_TYPE_PRODUCT_DETAIL = 'detail';
    const PHOTO_TYPE_HOME = 'home';

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('ibrand.app.database.prefix', 'ibrand_').'goods_photo');
    }

    public function goods(){
        return $this->belongsTo('GuoJiangClub\EC\Open\Backend\Store\Model\Goods','goods_id');
    }

    public function getCheckedStatusAttribute()
    {
        if($this->attributes['is_default'] == 1)
        {
            return 'checked';
        }
            return '';
    }

}
