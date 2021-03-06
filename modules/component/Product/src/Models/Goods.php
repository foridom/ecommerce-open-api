<?php

namespace GuoJiangClub\Component\Product\Models;

use GuoJiangClub\Component\Product\Brand;
use Illuminate\Database\Eloquent\Model as LaravelModel;

class Goods extends LaravelModel
{
    //商标保障申请价格
    const MARKUP_PRICE_SERVICE = 200;
    const MARKUP_PRICE_OFFICIAL = 200;
    const MARKUP_PRICE_TOTAL = 400;

    const TAX_RATE_SELF_APPLICATION = 6;

    protected $guarded = ['id'];

    protected $hidden = ['cost_price'];

    protected $casts = [
        'is_home_display' => 'boolean',
        'extra' => 'json',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('ibrand.app.database.prefix', 'ibrand_').'goods');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function getKeyCode($type = null)
    {
        return $this->id;
    }

    public function getStockQtyAttribute()
    {
        return $this->store_nums;
    }

    public function getPhotoUrlAttribute()
    {
        return $this->img;
    }

    public function getSpecsTextAttribute()
    {
        return '';
    }

    public function getDetailIdAttribute()
    {
        return $this->id;
    }

    public function getIsInSale($quantity)
    {
        return 0 == $this->is_del && $this->stock_qty >= $quantity;
    }

    public function increaseSales($quantity)
    {
        return $this->sale_count = $this->sale_count + $quantity;
    }

    public function restoreSales($quantity)
    {
        return $this->sale_count = $this->sale_count - $quantity;
    }

    public function photos()
    {
        return $this->hasMany(GoodsPhoto::class, 'goods_id');
    }

    public function questions()
    {
        return $this->hasMany(GoodsQuestion::class, 'goods_id', 'id');
    }

    public function reduceStock($quantity)
    {
        $this->store_nums = $this->products()->sum('store_nums');
    }

    public function restoreStock($quantity)
    {
        $this->store_nums = $this->store_nums + $quantity;
    }

    public function getArrayTagsAttribute()
    {
        return explode(',', $this->attributes['tags']);
    }

    /**
     * 详情页获取产品规格
     *
     * @return mixed
     */
    public function getSpecValueAttribute()
    {
        return json_decode($this->attributes['spec_array'], true);
    }

    public function specificationValue()
    {
        return $this->belongsToMany(SpecificationValue::class, config('ibrand.app.database.prefix', 'ibrand_').'goods_spec_relation', 'goods_id', 'spec_value_id')
            ->withPivot('spec_id', 'alias', 'img', 'sort')->withTimestamps();
    }

    public function getItemType()
    {
        return 'goods';
    }

    public function atrributes()
    {
        return $this->belongsToMany('GuoJiangClub\EC\Open\Backend\Store\Model\Attribute', config('ibrand.app.database.prefix', 'ibrand_').'goods_attribute_relation', 'goods_id', 'attribute_id')
            ->withPivot('attribute_value_id', 'model_id', 'attribute_value');
    }
}
