<?php

namespace GuoJiangClub\EC\Open\Backend\Store\Model;

use Illuminate\Database\Eloquent\Model;
use Prettus\Repository\Contracts\Transformable;
use Prettus\Repository\Traits\TransformableTrait;

class Industry extends Model implements Transformable
{
    use TransformableTrait;

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable('industry');
    }

    public function recommendClassifications()
    {
        return $this->belongsToMany(NiceClassification::class, 'industry_recommend_classifications', 'industry_id', 'nice_classification_id')
            ->withPivot('nice_classification_parent_id', 'alias', 'sort')->withTimestamps();
    }

}
