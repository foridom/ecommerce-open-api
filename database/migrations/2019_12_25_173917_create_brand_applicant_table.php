<?php

use GuoJiangClub\Component\Order\Models\BrandApplicant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBrandApplicantTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('brand_applicant', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('ibrand_user')->onDelete('cascade');
            $table->string('applicant_subject')->default(BrandApplicant::BRAND_APPLICANT_ENTERPRISE)->comment('申请人主体');
            $table->string('applicant_name')->comment('企业名称或者个人名称');
            $table->string('unified_social_credit_code')->nullable()->comment('统一社会信用代码');
            $table->string('id_card_no')->nullable()->comment('身份证号');
            $table->string('postcode')->comment('邮政编码');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('brand_applicant');
    }
}