<?php


namespace app\api\controller;


use app\admin\model\OrderGood;
use app\api\library\WxPay;
use app\api\model\UserAddress;
use app\common\controller\Api;
use app\admin\model\Shop;
use app\admin\model\Order;
use Exception;
use think\Db;
use think\exception\DbException;
use think\exception\HttpResponseException;
use think\Response;

/**
 * 支付接口
 */
class Pay extends Api
{
    protected $noNeedLogin = ['notifyPay', 'notifyRefund','refundOrder'];
    protected $noNeedRight = '*';
    private $instance;

    protected function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
        $this->instance = WxPay::instance(config('site.app_id'), config('site.mch_id'), config('site.key'));
    }

    /**
     * 生成订单，返回支付数据
     * @param integer $shop_id 商家 id
     * @param integer $address_id 收货地址 id
     * @param string $message 订单留言
     * @throws DbException
     * @throws Exception
     */
    public function pay($good_ids, $address_id, $refund_refuse_msg = '')
    {
        $user_id = $this->auth->id;

        // 校验商品和库存 （从购物车里面取出来）
        $find_good = Db::name('good')
            ->whereIn('id',$good_ids)
            ->select();

        foreach ($find_good as $item) {
            $item['deletetime'] != null && $this->error($item['name'] . '已经被平台删除');
            $item['status'] != '1' && $this->error($item['name'] . '已经被平台下架');
        }

        // 校验收货地址
         $find_address = UserAddress::get($address_id);
        // !$find_address && $this->error('超出了商家的配送范围,请更换收货地址', ['type' => 'NO_RIGHT_ADDRESS']);

        // 生成订单
        Db::startTrans();
        try {
            $price      = 0;
            $body       = '';
            $counts     = 0;
            $open_id    = $this->auth->open_id;
            $notify_url = config('site.pay_notify_url');
            // 计算价格 和 拼接名称
            foreach ($find_good as $item) {
                $price  += $item['price'] * 1;
                $counts ++;
                mb_strlen($body) < 20 && $body = $body . $item['name'] . ',';
            }
            $body  = mb_substr($body, 0, mb_strlen($body) - 1);
            $body  .= "等{$counts}项服务";
            $price = round($price, 2);
            // 生成微信支付数据
            $result = $this->instance->create($open_id, $body, 0.01, $notify_url);

            // 添加订单 获取主键：id
            $order['user_id']      = $user_id;
            $order['numbers']      = $this->instance->out_trade_no;
            $order['createtime']   = $order['updatetime'] = time();
            $order['message']      = $refund_refuse_msg;
            $order['total_counts'] = $counts;
            $order['total_price']  = $price;
            $order['body']         = $body;
            $order_id              = Db::name('order')->insertGetId($order);

            // 添加订单地址关联
            $insert_address['order_id'] = $order_id;
            $insert_address['contact']  = $find_address['contact'];
            $insert_address['phone']    = $find_address['phone'];
            $insert_address['address']  = $find_address['address'].$find_address['doorplate'];
            Db::name('order_address')->insert($insert_address);

            // 添加订单产品关联
            foreach ($find_good as $item) {
                $insert_good['order_id']    = $order_id;
//                $insert_good['good_id']     = $item['good_id'];
                $insert_good['name']        = $item['name'];
                $insert_good['thumb_image'] = $item['thumb_image'];
                $insert_good['short']       = $item['short'];
                $insert_good['original']    = $item['original'];
                $insert_good['price']       = $item['price'];
//                $insert_good['counts']      = $item['number'];
                Db::name('order_good')->insert($insert_good);
            }

            Db::commit();
        } catch (DbException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        // 写在 try里面会被 捕获到 HttpResponseException
        $this->success('下单成功', ['out_trade_no' => $this->instance->out_trade_no, 'package' => $result]);
    }

    /**
     * 再次支付，返回支付数据
     * @param integer $id 订单主键：id
     * @throws Exception
     */
    public function payAgain($id)
    {
        Db::startTrans();
        try {
            // 生成微信支付数据
            $open_id    = $this->auth->open_id;
            $notify_url = config('site.pay_notify_url');
            $find       = Db::name('order')->find(['id' => $id]);
            $result     = $this->instance->create($open_id, $find['body'], $find['total_price'], $notify_url);

            // 更新订单号
            Db::name('order')->where(['id' => $id])->update(['numbers' => $this->instance->out_trade_no, 'updatetime' => time()]);
            Db::commit();
        } catch (DbException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        // 写在 try里面会被 捕获到 HttpResponseException
        $this->success('下单成功', ['out_trade_no' => $this->instance->out_trade_no, 'package' => $result]);
    }

    /**
     * 查询订单，是否被支付
     * @param string $order_id 订单标识 默认传商户侧订单号，传入微信侧订单号时需指定 type = true
     * @param bool $type type = true 时应当传入 微信侧订单号
     * @throws Exception
     */
    public function queryPayResult($order_id, $type = false)
    {
        Db::startTrans();
        try {
            $result     = $this->instance->query($order_id, $type);
            $key        = $type ? 'transaction' : 'numbers';
            $find_order = Order::get([$key => $order_id]);

            $find_order->status      = '1';
            $find_order->pay_time    = time();
            $find_order->transaction = $result['transaction_id'];
            $find_order->save();
            // 支付成功 调整库存
            $id    = $find_order->id;
            $goods = Db::name('order_good')->where(['order_id' => $id])->select();
            foreach ($goods as $item) {
                Db::name('good')->where(['id' => $item['good_id']])->setDec('stock', $item['counts']);
            }

            // 操作商家余额
            $shop_commission = config('site.shop_commission');
            Db::name('shop_balance')->where(['shop_id'=>$find_order->shop_id])->setInc('balance_',$find_order->total_price*$shop_commission);
            Db::name('shop_balance')->where(['shop_id'=>$find_order->shop_id])->setInc('total_price',$find_order->total_price*$shop_commission);

            Db::commit();
        } catch (DbException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $this->success('支付成功', $result);
    }

    /**
     * 申请退款，(全额)
     * @param float $total_fee 订单金额
     * @param string $order_id 订单号
     * @param bool $type true 微信订单号，false 商户订单号
     */
    public function refundOrder($total_fee, $order_id, $type = false)
    {
        $this->instance->refund(config('site.ssl_cert'), config('site.ssl_key'), $total_fee, config('site.refund_notify_url'), $order_id, $type);
    }

    /**
     * 支付通知地址
     * 只是记录在log里面
     * 改变支付状态的操作在putOrder()方法
     */
    public function notifyPay()
    {
        $this->responseType = 'xml';
        $result             = ['return_code' => 'SUCCESS', 'return_msg' => 'OK'];
        $response           = Response::create($result, 'xml', 200);
        throw new HttpResponseException($response);
    }

    /**
     * 退款通知地址
     */
    public function notifyRefund()
    {
        $this->responseType = 'xml';
        $result             = ['return_code' => 'SUCCESS', 'return_msg' => 'OK'];
        $response           = Response::create($result, 'xml', 200);
        throw new HttpResponseException($response);
    }
}