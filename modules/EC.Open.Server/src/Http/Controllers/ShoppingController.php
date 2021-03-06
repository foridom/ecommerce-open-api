<?php

namespace GuoJiangClub\EC\Open\Server\Http\Controllers;

use Carbon\Carbon;
use Cart;
use DB;
use GuoJiangClub\Component\Address\RepositoryContract as AddressRepository;
use GuoJiangClub\Component\Discount\Applicators\DiscountApplicator;
use GuoJiangClub\Component\Discount\Models\Coupon;
use GuoJiangClub\Component\Discount\Models\Discount;
use GuoJiangClub\Component\Discount\Repositories\CouponRepository;
use GuoJiangClub\Component\Order\Models\Comment;
use GuoJiangClub\Component\Order\Models\Order;
use GuoJiangClub\Component\Order\Models\OrderItem;
use GuoJiangClub\Component\Order\Repositories\OrderRepository;
use GuoJiangClub\Component\Point\Repository\PointRepository;
use GuoJiangClub\Component\Product\Models\AttributeValue;
use GuoJiangClub\Component\Product\Repositories\GoodsRepository;
use GuoJiangClub\Component\Product\Repositories\ProductRepository;
use GuoJiangClub\Component\Shipping\Models\Shipping;
use GuoJiangClub\EC\Open\Backend\Store\Model\NiceClassification;
use GuoJiangClub\EC\Open\Core\Applicators\PointApplicator;
use GuoJiangClub\EC\Open\Core\Applicators\TaxApplicator;
use GuoJiangClub\EC\Open\Core\Processor\OrderProcessor;
use GuoJiangClub\EC\Open\Core\Services\DiscountService;
use GuoJiangClub\EC\Open\Server\Events\UserClassification;
use GuoJiangClub\EC\Open\Server\Events\UserClassificationEvent;
use Illuminate\Support\Collection;
use GuoJiangClub\Component\Product\Models\Goods;
use GuoJiangClub\Component\Product\Models\Product;
use iBrand\Shoppingcart\Item;
use Storage;
use Log;

class ShoppingController extends Controller
{
    private $goodsRepository;
    private $productRepository;
    private $discountService;
    private $orderRepository;
    private $discountApplicator;
    private $couponRepository;
    private $addressRepository;
    private $orderProcessor;
    private $pointRepository;
    private $pointApplicator;
    private $taxApplicator;


    public function __construct(GoodsRepository $goodsRepository
        , ProductRepository $productRepository
        , DiscountService $discountService
        , OrderRepository $orderRepository
        , CouponRepository $couponRepository
        , DiscountApplicator $discountApplicator
        , AddressRepository $addressRepository
        , OrderProcessor $orderProcessor
        , PointRepository $pointRepository
        , PointApplicator $pointApplicator
        , TaxApplicator $taxApplicator
    )
    {
        $this->goodsRepository = $goodsRepository;
        $this->productRepository = $productRepository;
        $this->discountService = $discountService;
        $this->orderRepository = $orderRepository;
        $this->discountApplicator = $discountApplicator;
        $this->couponRepository = $couponRepository;
        $this->addressRepository = $addressRepository;
        $this->orderProcessor = $orderProcessor;
        $this->pointRepository = $pointRepository;
        $this->pointApplicator = $pointApplicator;
        $this->taxApplicator = $taxApplicator;
    }

    /**
     * 购物车结算(商品直接下单)
     *
     * @return \Dingo\Api\Http\Response|mixed
     */
    public function checkout()
    {
        $user = request()->user();

        $checkoutType = $this->getCheckoutType();

        //1.获取购物车条目
        $cartItems = call_user_func(array($this, 'getSelectedItemFrom' . $checkoutType));

        if (0 == $cartItems->count()) {
            return $this->failed('未选中商品，无法提交订单');
        }

        $order = new Order(['user_id' => request()->user()->id]);

        //2. 生成临时订单对象
        $order = $this->buildOrderItemsFromCartItems($cartItems, $order);

//        $defaultAddress = $this->addressRepository->getDefaultByUser(request()->user()->id);
        $defaultContact = $this->addressRepository->getDefaultByUser(request()->user()->id);

        if (!$order->save()) {
            return $this->failed('订单提交失败，请重试');
        }

        //3.get available discounts
        list($discounts, $bestDiscountAdjustmentTotal, $bestDiscountId) = $this->getOrderDiscounts($order);

        //4. get available coupons
        list($coupons, $bestCouponID, $bestCouponAdjustmentTotal) = $this->getOrderCoupons($order, $user);

        //5. get point for order.
        $orderPoint = $this->getOrderPoint($order, $user);

        //6.生成运费
        $order->payable_freight = 0;

        $discountGroup = $this->discountService->getOrderDiscountGroup($order, new Collection($discounts), new Collection($coupons));

        return $this->success([
            'order' => $order,
            'discounts' => $discounts,
            'coupons' => $coupons,
            'order_contact' => $defaultContact,
            'discountGroup' => $discountGroup,
            'orderPoint' => $orderPoint,
            'best_discount_id' => $bestDiscountId,
            'best_coupon_id' => $bestCouponID,
            'best_coupon_adjustment_total' => $bestCouponAdjustmentTotal,
            'best_discount_adjustment_total' => $bestDiscountAdjustmentTotal,
        ]);
    }


    /**
     * confirm the order to be waiting to pay.
     *
     * @return \Dingo\Api\Http\Response|mixed
     */
    public function confirm()
    {
        $user = request()->user();

        $order_no = request('order_no');
        if (!$order_no || !$order = $this->orderRepository->getOrderByNo($order_no)) {
            return $this->failed('订单不存在');
        }

        if ($user->cant('submit', $order)) {
            return $this->failed('订单提交失败，无权操作');
        }

        if ($note = request('note')) {
            $order->note = $note;
        }

        if (request()->has('need_invoice')) {
            $order->need_invoice = request('need_invoice');
        }

        //1. check stock.
        foreach ($order->getItems() as $item) { // 再次checker库存
            $model = $item->getModel();

            if (!$model->getIsInSale($item->quantity)) {
                return $this->failed('商品: ' . $item->name . ' ' . $item->item_meta['specs_text'] . ' 库存不够，请重新下单');
            }
        }

        try {
            DB::beginTransaction();
            //2. apply the available discounts
            $discount = Discount::find(request('discount_id'));
            if (!empty($discount)) {
                if ($this->discountService->checkDiscount($order, $discount)) {
                    $order->type = Order::TYPE_DISCOUNT;

                    $this->discountApplicator->apply($order, $discount);
                } else {
                    return $this->failed('折扣信息有误，请确认后重试');
                }
            }
            //3. apply the available coupons
            if (empty($discount) or 1 != $discount->exclusive) {

                $coupon = Coupon::find(request('coupon_id'));
                if (!empty($coupon)) {
                    if (null != $coupon->used_at) {
                        return $this->failed('此优惠券已被使用');
                    }
                    if ($user->can('update', $coupon) and $this->discountService->checkCoupon($order, $coupon)) {
                        $this->discountApplicator->apply($order, $coupon);
                    } else {
                        return $this->failed('优惠券信息有误，请确认后重试');
                    }
                }
            }

            //4. use point
            if ($point = request('point') && config('ibrand.app.point.enable')) {
                if ($this->checkUserPoint($order, $point)) {
                    $this->pointApplicator->apply($order, $point);
                } else {
                    return $this->failed('积分不足或不满足积分折扣规则');
                }
            }

            //5. 叠加税费
            if (request('need_invoice')) {
                $this->taxApplicator->apply($order);
            }

            //6. 保存订单联系人信息 生成服务协议
            if ($inputContact = request('order_contact')) {
                $contact = $this->addressRepository->firstOrCreate(array_merge($inputContact, ['user_id' => request()->user()->id]));

                $order->accept_name = $contact->accept_name;
                $order->mobile = $contact->mobile;
                $order->address = $contact->address;
                $order->address_name = $contact->address_name;
                $order->email = $contact->contact_email;

                $order->agreement()->create(['party_a_name'=> $contact->accept_name]);
            }

            //7. 保存订单状态
            $this->orderProcessor->submit($order);

            //8. remove goods store.
            foreach ($order->getItems() as $item) {
                /** @var Goods | Product $product */
                $product = $item->getModel();
//                $product->reduceStock($item->quantity);
                $product->increaseSales($item->quantity);
                $product->save();
            }

            //9. 移除购物车中已下单的商品
            foreach ($order->getItems() as $orderItem) {
                if ($carItem = Cart::search(['name' => $orderItem->item_name])->first()) {
                    Cart::remove($carItem->rawId());
                }
            }

            DB::commit();

            return $this->success(['order' => $order], true);

        } catch (\Exception $exception) {
            DB::rollBack();
            \Log::info($exception->getMessage() . $exception->getTraceAsString());

            return $this->failed('订单提交失败');
        }
    }

    /**
     * cancel this order.
     */
    public function cancel()
    {
        $user = request()->user();

        $order_no = request('order_no');
        if (!$order_no || !$order = $this->orderRepository->getOrderByNo($order_no)) {
            return $this->failed('订单不存在');
        }

        if ($user->cant('cancel', $order)) {
            return $this->failed('无法取消该订单');
        }

        $this->orderProcessor->cancel($order);

        //TODO: 用户未付款前取消订单后，需要还原库存
        foreach ($order->getItems() as $item) {
            $product = $item->getModel();
//            $product->restoreStock($item->quantity);
            $product->restoreSales($item->quantity);
            $product->save();
        }

        return $this->success();
    }

    /**
     * received this order.
     */
    public function received()
    {
        try {
            DB::beginTransaction();

            $user = request()->user();

            $order_no = request('order_no');
            if (!$order_no || !$order = $this->orderRepository->getOrderByNo($order_no)) {
                return $this->failed('订单不存在');
            }

            if ($user->cant('received', $order)) {
                return $this->failed('无法对此订单进行确认收货操作');
            }

            $order->status = Order::STATUS_RECEIVED;
            $order->accept_time = Carbon::now();
            $order->save();

            DB::commit();

            return $this->api([], true, 200, '确认收货操作成功');
        } catch (\Exception $exception) {
            DB::rollBack();
            \Log::info($exception->getMessage() . $exception->getTraceAsString());
            $this->response()->errorInternal($exception->getMessage());
        }
    }

    public function delete()
    {
        $user = request()->user();

        $order_no = request('order_no');
        if (!$order_no || !$order = $this->orderRepository->getOrderByNo($order_no)) {
            return $this->failed('订单不存在');
        }

        if ($user->cant('delete', $order)) {
            return $this->failed('无权删除此订单');
        }

        $order->status = Order::STATUS_DELETED;
        $order->save();

        return $this->success();
    }

    public function review()
    {
        $user = request()->user();
//        $comments = request()->except('_token');
        $comments = request('reviews');

        if (!is_array($comments)) {
            return $this->failed('提交参数错误');
        }

        foreach ($comments as $key => $comment) {
            if (!isset($comment['order_no']) or !$order = $this->orderRepository->getOrderByNo($comment['order_no'])) {
                return $this->failed('订单 ' . $comment['order_no'] . ' 不存在');
            }

            if (!isset($comment['order_item_id']) or !$orderItem = OrderItem::find($comment['order_item_id'])) {
                return $this->failed('请选择具体评价的商品');
            }

//            if ($user->cant('review', [$order, $orderItem])) {
//                return $this->failed('无权对该商品进行评价');
//            }

            if ($order->comments()->where('order_item_id', $comment['order_item_id'])->count() > 0) {
                return $this->failed('该产品已经评论，无法再次评论');
            }

            $content = isset($comment['contents']) ? $comment['contents'] : '';
            $point = isset($comment['point']) ? $comment['point'] : 5;
            $pic_list = isset($comment['images']) ? $comment['images'] : [];

            $comment = new Comment([
                'user_id' => $user->id,
                'order_item_id' => $comment['order_item_id'],
                'item_id' => $orderItem->item_id,
                'item_meta' => $orderItem->item_meta,
                'contents' => $content,
                'point' => $point, 'status' => 'show',
                'pic_list' => $pic_list,
//                'goods_id' => $orderItem->item_meta['detail_id'],
            ]);

            $order->comments()->save($comment);

            $order->status = Order::STATUS_COMPLETE;
            $order->completion_time = Carbon::now();
            $order->save();
        }

        return $this->success();
    }

    /**
     * call by call_user_func().
     *
     * @return bool|Collection
     *
     * @throws \Exception
     */
    private function getSelectedItemFromCart()
    {
        //获取购物车中选中的商品数据
        $ids = request('cart_ids');

        $cartItems = new Collection();

        if (!$ids || 0 == count($ids)) {
            return $cartItems;
        }

        foreach ($ids as $cartId) {
            if ($cart = Cart::get($cartId)) {
                $cartItems->put($cartId, $cart);
            }
        }

        foreach ($cartItems as $key => $item) {
            //检查库存是否足够
            if (!$this->checkItemStock($item)) {
                Cart::update($key, ['message' => '库存数量不足', 'status' => 'onhand']);

                throw new \Exception('商品: ' . $item->name . ' ' . $item->color . ',' . $item->size . ' 库存数量不足');
            }
        }

        return $cartItems;
    }

    private function checkItemStock($item)
    {
        if (is_null($item->model) || !$item->model->getIsInSale($item->qty)) {
            return false;
        }

        return true;
    }

    /**
     * get order discounts data.
     *
     * @param $order
     *
     * @return array
     */
    private function getOrderDiscounts($order)
    {
        $bestDiscountAdjustmentTotal = 0;
        $bestDiscountId = 0;

        $discounts = $this->discountService->getEligibilityDiscounts($order);

        if ($discounts) {
            if (0 == count($discounts)) { //修复过滤后discount为0时非false 的问题。
                $discounts = false;
            } else {
                $bestDiscount = $discounts->sortBy('adjustmentTotal')->first();
                $bestDiscountId = $bestDiscount->id;
                $bestDiscountAdjustmentTotal = -$bestDiscount->adjustmentTotal;
                $discounts = collect_to_array($discounts);
            }
        }

        return [$discounts, $bestDiscountAdjustmentTotal, $bestDiscountId];
    }

    /**
     * @param $order
     * @param $user
     *
     * @return array|bool
     */
    private function getOrderCoupons($order, $user)
    {
        $bestCouponID = 0;
        $bestCouponAdjustmentTotal = 0;
        $cheap_price = 0;

        $coupons = $this->discountService->getEligibilityCoupons($order, $user->id);

        if ($coupons) {
            $bestCoupon = $coupons->sortBy('adjustmentTotal')->first();
            if ($bestCoupon->orderAmountLimit > 0 and $bestCoupon->orderAmountLimit > ($order->total + $cheap_price)) {
                $bestCouponID = 0;
            } else {
                $bestCouponID = $bestCoupon->id;
                $cheap_price += $bestCoupon->adjustmentTotal;
                $bestCouponAdjustmentTotal = -$bestCoupon->adjustmentTotal;
            }

            $coupons = collect_to_array($coupons);
        } else {
            $coupons = [];
        }

        return [$coupons, $bestCouponID, $bestCouponAdjustmentTotal];
    }

    /**
     * @param $cartItems
     * @param $order
     *
     * @return OrderItem
     */
    private function buildOrderItemsFromCartItems($cartItems, $order)
    {
        foreach ($cartItems as $key => $item) {
            if (0 == $item->qty) {
                continue;
            }

            $item_meta = [
                'image' => $item->img,
                'detail_id' => $item->model->detail_id,
                'specs_text' => $item->model->specs_text,
                'service_price' => $item->model->service_price * 100,
                'official_price' => $item->model->official_price * 100,
            ];

            $unit_price = $item->model->sell_price;

            //TODO 附加服务待优
            if (isset($item['attribute_value_ids']) && $item['attribute_value_ids']) {
                $optionServices = $this->getOptionService($item['attribute_value_ids']);
                $option_services_price = $optionServices->sum('attribute_value');
                $item_meta['attribute_value_ids'] = $item['attribute_value_ids'];
                $item_meta['option_service'] = $optionServices;
                $unit_price += $option_services_price / 100;
            } else {
                $option_services_price = 0;
                $item_meta['attribute_value_ids'] = null;
                $item_meta['option_service'] = null;
            }

            //商标保障申请
            $ensure_price = 0;
            if (isset($item['classification_ids']) && $item['classification_ids']) {
                $classificationIds = explode(',', $item['classification_ids']);
                $ensure_price = count($classificationIds) * Goods::MARKUP_PRICE_TOTAL;
                $unit_price += $ensure_price;
                $item_meta['ensure_classifications'] = NiceClassification::query()->whereIn('id', $classificationIds)->get(['id', 'classification_name', 'classification_code', 'parent_id', 'level'])->toArray();
            }

            //自助申请
            if (isset($item['self_apply_classifications']) && $selectedClassifications = $item['self_apply_classifications']['selected_classifications']) {
                $order->type = Order::TYPE_SELF_APPLICATION;
                $this->submitUserClassifications($selectedClassifications, request()->user()->id);
                $unit_price += $this->getSelfApplyPrice($selectedClassifications, $item->model->official_price, $item->model->self_apply_additional_price);
                $item_meta['self_apply_classifications'] = $item['self_apply_classifications'];
            }

            $orderItem = new OrderItem([
                'quantity' => $item->qty,
                'unit_price' => $unit_price,
                'item_id' => $item->id,
                'type' => $item->__model,
                'item_name' => $item->name,
                'item_meta' => $item_meta,
                'brand_data' => $order->type === Order::TYPE_SELF_APPLICATION ? $item_meta['self_apply_classifications'] : null,
            ]);

            $orderItem->recalculateUnitsTotal();

            $order->addItem($orderItem);
        }

        return $order;
    }

    public function delivery()
    {
        $order_no = request('order_no');

        $order = $this->orderRepository->getOrderByNo($order_no);

        if (!$order_no || !$order || 2 != $order->status) {
            return $this->response()->errorBadRequest('订单不存在');
        }

        $data['delivery_time'] = date('Y-m-d H:i:s', Carbon::now()->timestamp);

        $data['order_id'] = $order->id;

        $data['method_id'] = mt_rand(1, 10);

        $data['tracking'] = uniqid();

        if (Shipping::create($data)) {

            $order->status = 3;
            $order->save();
            return $this->success();
        }

        return $this->failed('订单发货失败');
    }

    private function getOrderPoint($order, $user)
    {
        if (config('ibrand.app.point.enable')) {
            $orderPoint['userPoint'] = $this->pointRepository->getSumPointValid($user->id); //用户可用积分
            $orderPoint['pointToMoney'] = config('ibrand.app.point.order_proportion');  //pointToMoney
            $orderPoint['pointLimit'] = config('ibrand.app.point.order_limit') / 100; //pointLimit
            $pointAmount = min($orderPoint['userPoint'] * $orderPoint['pointToMoney'], $order->total * $orderPoint['pointLimit']);
            $orderPoint['pointAmount'] = -$pointAmount;
            $orderPoint['pointCanUse'] = $pointAmount / $orderPoint['pointToMoney'];
        }
    }


    /**
     * confirm user point can be used in this order.
     * @param $order
     * @param $point
     * @return bool
     */
    private function checkUserPoint($order, $point)
    {
        $userPoint = $this->pointRepository->getSumPointValid($order->user_id);
        $usePointAmount = $point * config('ibrand.app.point.order_proportion');
        $orderPointLimit = $order->total * config('ibrand.app.point.order_limit');

        //如果用户的积分小于使用的积分 或者抵扣的金额大于了订单可抵扣金额，则无法使用该积分
        if ($userPoint < $point || $usePointAmount > $orderPointLimit) {
            return false;
        }

        return true;
    }

    private function getCheckoutType()
    {
        if ($ids = request('cart_ids') AND count($ids) > 0)
            return 'Cart';
        if (request('product_id'))
            return 'Product';
        return 'Cart';
    }

    private function getOptionService($ids)
    {
        $optionServiceIds = explode(',', $ids);
        $optionService = [];
        foreach ($optionServiceIds as $id) {
            $attributeValue = AttributeValue::query()->find($id);
            $attribute = $attributeValue->attribute;
            $optionService[] = [
                'attribute_id' => $attribute->id,
                'attribute_value_id' => $id,
                'attribute_value' => $attributeValue['name'] * 100,
                'name' => $attribute['name'],
            ];
        }

        return collect($optionService);
    }

    private function getSelectedItemFromProduct()
    {
        $cartItems = new Collection();
        $productId = request('product_id');
        $__raw_id = md5(time() . request('product_id'));
        $item = request()->all();
        $input = ['__raw_id' => $__raw_id,
            'id' => $productId,    //如果是SKU，表示SKU id，否则是SPU ID
            'img' => isset($item['attributes']['img']) ? $item['attributes']['img'] : '',
            'qty' => request('qty'),
            'total' => isset($item['total']) ? $item['total'] : '',
        ];
        if (isset($item['attributes']['sku'])) {
            $product = Product::query()->find($productId);

            $input['price'] = $product->sell_price;
            $input['type'] = 'sku';
            $input['__model'] = Product::class;

            $input += $item['attributes'];
//            $input['name'] = $product->name;
//            $input['type'] = isset($item['attributes']['type']) ? $item['attributes']['type'] : [];
//            $input['time'] = isset($item['attributes']['time']) ? $item['attributes']['time'] : [];
//            $input['com_id'] = isset($item['attributes']['com_id']) ? $item['attributes']['com_id'] : [];
        } else {
            $goods = Goods::query()->find($productId);
            $input['name'] = $goods->name;
            $input['price'] = $goods->sell_price;
            $input['size'] = isset($item['size']) ? $item['size'] : '';
            $input['color'] = isset($item['color']) ? $item['color'] : '';
            $input['type'] = 'spu';
            $input['__model'] = Goods::class;
//            $input['com_id'] = $item['id'];

            $input += $item['attributes'];
        }
        $data = new Item(array_merge($input, $item));
        $cartItems->put($__raw_id, $data);

        return $cartItems;
    }

    /**
     * 用户提交尼斯分类事件
     *
     * @param $classifications
     */
    protected function submitUserClassifications($classifications, $userId)
    {
        event(new UserClassificationEvent($classifications, $userId));
    }

    /**
     * 计算自助申请价格
     *
     * @param collection $classifications
     * @param float $servicePrice
     * @param float $additionPrice
     * @return float|int
     */
    public function getSelfApplyPrice($classifications, $servicePrice, $additionPrice)
    {
        $totalPrice = 0;

        foreach ($classifications as $top) {
            $i = 0;
            foreach ($top['children']['data'] as $group) {
                $i += count($group['children']['data']);
            }

            //大类下超过10个叠加附加费用
            $topPrice = $i <= 10 ? $servicePrice : $servicePrice + ($i - 10) * $additionPrice;

            $totalPrice += $topPrice;
        }

        return $totalPrice;
    }


}
