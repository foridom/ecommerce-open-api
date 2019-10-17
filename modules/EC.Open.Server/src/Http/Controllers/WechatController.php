<?php

/*
 * This file is part of ibrand/EC-Open-Server.
 *
 * (c) iBrand <https://www.ibrand.cc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GuoJiangClub\EC\Open\Server\Http\Controllers;
use Carbon\Carbon;
use GuoJiangClub\Component\User\Models\UserBind;
use GuoJiangClub\EC\Open\Core\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;
use Log;
use DB;

class WechatController extends Controller
{
    protected $userRepository;
    protected $userBindRepository;
    protected $userService;
    protected $app;

    public function __construct()
    {
        $this->app = app('wechat.official_account');
    }

    /**
     * 获取二维码图片
     *
     * @param Request $request
     *
     * @throws \Exception
     */
    public function getWxPic(Request $request)
    {
        // 查询 cookie，如果没有就重新生成一次
        if (!$weChatFlag = $request->cookie(UserBind::TYPE_WECHAT)) {
            $weChatFlag = Uuid::uuid4()->getHex();
        }

        Log::info('weChatFlag :' . $weChatFlag);

        // 缓存微信带参二维码
        if (!$url = Cache::get(UserBind::QR_URL . $weChatFlag)) {
            // 有效期 1 天的二维码
            $qrCode = $this->app->qrcode;
            $result = $qrCode->temporary($weChatFlag, 3600 * 24);
            $url = $qrCode->url($result['ticket']);

            Cache::put(UserBind::QR_URL . $weChatFlag, $url, now()->addDay());

            Log::info('cache_key: ' . UserBind::QR_URL . $weChatFlag);
            Log::info('url: '. $url);
        }

        // 自定义参数返回给前端，前端轮询
        return $this->success(compact('url', 'weChatFlag'))
            ->cookie(UserBind::TYPE_WECHAT, $weChatFlag, 24 * 60);
    }

    /**
     * 微信消息接入（这里拆分函数处理）
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \EasyWeChat\Kernel\Exceptions\BadRequestException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \ReflectionException
     */
    public function serve()
    {
        $app = $this->app;

        Log::info('request arrived.');

        $app->server->push(function ($message) {
            if ($message) {
                $method = camel_case('handle_' . $message['MsgType']);

                if (method_exists($this, $method)) {
                    $this->openid = $message['FromUserName'];

                    return call_user_func_array([$this, $method], [$message]);
                }

                Log::info('无此处理方法:' . $method);
            }
        });

        return $app->server->serve();
    }

    /**
     * 事件引导处理方法（事件有许多，拆分处理）
     *
     * @param $event
     *
     * @return mixed
     */
    protected function handleEvent($event)
    {
        Log::info('事件参数：', [$event]);

        $method = camel_case('event_' . $event['Event']);
        Log::info('处理方法:' . $method);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], [$event]);
        }

        Log::info('无此事件处理方法:' . $method);
    }

    /**
     * 取消订阅
     *
     * @param $event
     */
    protected function eventUnsubscribe($event)
    {
        if ($userBind = UserBind::query()->where('open_id', $this->openid)->first()) {
            $userBind->subscribe = false;
            $userBind->subscribe_at = null;
            $userBind->save();
        }
    }

    /**
     * 扫描带参二维码事件
     *
     * @param $event
     */
    public function eventSCAN($event)
    {
        Log::info('扫码二维码');
        if ($userBind = UserBind::query()->where('open_id', $this->openid)->first()) {
            // 标记前端可登陆
            $this->markTheLogin($event, $userBind->id);

            return;
        }
    }

    /**
     * 订阅
     *
     * @param $event
     *
     * @throws \Throwable
     */
    protected function eventSubscribe($event)
    {
        $openId = $this->openid;
        Log::info('openid:' . $openId);

        if ($userBind = UserBind::query()->where('open_id', $openId)->first()) {
            // 标记前端可登陆
            $this->markTheLogin($event, $userBind->id);

            return;
        }

        // 微信用户信息
        $wxUser = $this->app->user->get($openId);

        // 用户注册
        $nickname = $this->filterEmoji($wxUser['nickname']);

        $result = DB::transaction(function () use ($openId, $event, $nickname, $wxUser) {
//            $uid  = Uuid::uuid4()->getHex();

            // 用户
//            $user = User::create([
//                'uid'        => $uid,
//                'created_at' => $time,
//            ]);

            // 用户信息
//            $user->user_info()->create([
//                'email'      => $user->email,
//                'nickname'   => $nickname,
//                'sex'        => $wxUser['sex'],
//                'address'    => $wxUser['country'] . ' ' . $wxUser['province'] . ' ' . $wxUser['city'],
//                'avatar'     => $wxUser['headimgurl'],
//                'code'       => app(UserRegisterController::class)->inviteCode(10),
//                'created_at' => $time,
//            ]);

            $userBind = UserBind::query()->create([
                'type' => 'official_account',
                'app_id' => config('wechat.official_account.default.app_id'),
                'open_id' => $wxUser['openid'],
                'nick_name' => $nickname,
                'sex' => $wxUser['sex'],
                'avatar' => $wxUser['headimgurl'],
                'subscribe' => true,
                'subscribe_at' => Carbon::now(),
            ]);
//            $wxUserModel = $user->wx_user()->create([
//                'subscribe'      => $wxUser['subscribe'],
//                'subscribe_time' => $wxUser['subscribe_time'],
//                'openid'         => $wxUser['openid'],
//                'created_at'     => Carbon::now(),
//            ]);

            Log::info('用户注册成功 openid：' . $openId);

            $this->markTheLogin($event, $userBind->id);
        });

        Log::debug('SQL 错误: ', [$result]);
    }

    /**
     * 标记可登录
     *
     * @param $event
     * @param $uid
     */
    public function markTheLogin($event, $id)
    {
        if (empty($event['EventKey'])) {
            return;
        }

        $eventKey = $event['EventKey'];

        // 关注事件的场景值会带一个前缀需要去掉
        if ($event['Event'] == 'subscribe') {
            $eventKey = str_after($event['EventKey'], 'qrscene_');
        }

        Log::info('EventKey:' . $eventKey, [$event['EventKey']]);

        // 标记前端可登陆
        Cache::put(UserBind::TYPE_WECHAT . $eventKey, $id, now()->addMinute(30));
    }

    /**
     * 过滤emoji表情
     *
     * @param $str 要过滤的字符串
     * @return mixed
     */
    private function filterEmoji($str)
    {
        $str = preg_replace_callback(
            '/./u',
            function (array $match) {
                return strlen($match[0]) >= 4 ? '' : $match[0];
            },
            $str);

        return $str;
    }


}
