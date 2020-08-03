<?php


namespace app\api\controller;


use app\common\controller\Api;
use app\admin\model\Shop as Shops;
use app\admin\model\User;
use Exception;
use think\Db;
use think\exception\DbException;

/**
 * 商家接口
 */
class Shop extends Api
{
    protected $noNeedLogin = ['getShopsByExtension','getShops'];
    protected $noNeedRight = '*';

    protected function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
    }

    /**
     * 申请入驻
     * @param string $contact 联系人
     * @param string $phone 联系电话
     * @param string $name 名称
     * @param string $short 简介
     * @param integer $category_id 经营行业
     * @param string $address 文字地址
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @param integer $hours_start 开始营业时间
     * @param integer $hours_end 结束营业时间
     * @param integer $distance 配送距离
     * @param integer $delivery 配送时间
     * @param integer $base_price 起送价格
     * @param string $card_up_image 身份证正面
     * @param string $card_down_image 身份证反面
     * @param string $license_image 营业执照
     * @param string $storefront_image 实体店照片
     * @param boolean|integer $parent_id 推广人
     * @throws DbException
     */
    public function setShop($contact, $phone, $name, $short, $category_id, $address, $longitude, $latitude, $hours_start, $hours_end, $delivery, $distance, $base_price, $card_up_image, $card_down_image, $license_image, $storefront_image, $parent_id = false)
    {
        // 先查找
        $user_id    = $this->auth->id;
        $shop_model = new Shops();
        $find_shop  = $shop_model->get(['user_id' => $user_id]);
        $find_shop && $this->error("不可重复提交");
        $find_shop_phone = $shop_model->get(['phone' => $phone]);
        $find_shop_phone && $this->error("手机号已经存在");
        $find_shop_name = $shop_model->get(['name' => $name]);
        $find_shop_name && $this->error("商铺名称已存在");

        Db::startTrans();
        try {
            // 推广人
            if ($parent_id) {
                $shop_model->data('parent_id', $parent_id);
            }
            $shop_model->data('user_id', $user_id);
            $shop_model->data('contact', $contact);
            $shop_model->data('phone', $phone);
            $shop_model->data('name', $name);
            $shop_model->data('short', $short);
            $shop_model->data('category_id', $category_id);
            $shop_model->data('logo_image', $storefront_image);
            $shop_model->save();

            $shop_model->shopaddress()->save([
                'address'     => $address,
                'latitude'    => $latitude,
                'longitude'   => $longitude,
                'hours_start' => $hours_start,
                'hours_end'   => $hours_end,
                'delivery'    => $delivery,
                'distance'    => $distance,
                'base_price'  => $base_price,
            ]);

            $shop_model->shopmaterial()->save([
                'card_up_image'    => $card_up_image,
                'card_down_image'  => $card_down_image,
                'license_image'    => $license_image,
                'storefront_image' => $storefront_image,
            ]);
            // 商家收益
            Db::name('shop_balance')->insert(['shop_id' => $shop_model->id]);

            // 推广人
            $find_user = (new User())->get(['id' => $parent_id]);
            if ($find_user) {
                $child = explode(',', $find_user->child);
                array_push($child, $shop_model->id);
                $find_user->child = implode(',', $child);
                $find_user->setInc('child_number');
                $find_user->save();
            }
            Db::commit();
        } catch (DbException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success("申请成功,稍后客服会联系您");
    }

    /**
     * 修改信息
     * @param integer $shop_id 主键：id
     * @param string $data 更新的json数据
     */
    public function putShop()
    {

    }

    /**
     * 商家列表
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @param int $type 类型 附近还是推荐
     * @param boolean|integer $category_id 行业分类
     */
    public function getShops($longitude, $latitude, $type = 0, $category_id = false)
    {
        if ($type == 0) {
            $where = ['s.status' => '1', 's.deletetime' => null];
            $category_id && $where['s.category_id'] = $category_id;
            $distance = "(6378.138 * 2 * asin(sqrt(pow(sin((a.latitude * pi() / 180 - " . $latitude . " * pi() / 180) / 2),2) + cos(a.latitude * pi() / 180) * cos(" . $latitude . " * pi() / 180) * pow(sin((a.longitude * pi() / 180 - " . $longitude . " * pi() / 180) / 2),2))) * 1000)";
            $field    = "s.id,s.name,s.logo_image,s.short,a.distance, $distance as distance,a.delivery,a.hours_start,a.hours_end,a.base_price";
            try {
                $shops = Db::name('shop ')
                    ->alias('s')
                    ->join('shop_address a', 's.id = a.shop_id', 'left')
                    ->where($where)
                    ->where($distance . '< ' . config("site.distance_limit"))
                    ->where('a.distance*1000 >' . $distance)
                    ->field($field)
                    ->orderRaw('s.weigh desc,distance asc')
                    ->paginate(null, false, $this->paginate)
                    ->each(function ($item) {
                        $item['logo_image'] = self::patch_oss($item['logo_image']);
                        $item['distance']   = round(($item['distance'] / 1000), 2);
                        return $item;
                    });

                $this->success('获取成功', $shops);
            } catch (DbException $e) {
                $this->error($e->getMessage());
            }
        } else {
            $shops = Db::name('shop ')
                ->alias('s')
                ->join('shop_address a', 's.id = a.shop_id', 'left')
                ->join('shop_balance b', 's.id = b.shop_id', 'left')
                ->where(['s.status' => '1', 's.deletetime' => null,'s.tui'=>'1'])
                ->orderRaw('s.weigh desc,b.total_price desc')
                ->field("s.id,s.name,s.logo_image,s.short,a.distance,a.delivery,a.hours_start,a.hours_end,a.base_price")
                ->paginate(null, false, $this->paginate)
                ->each(function ($item) {
                    $item['logo_image'] = self::patch_oss($item['logo_image']);
                    return $item;
                });

            $this->success('获取成功', $shops);
        }
    }

    /**
     * 商家详细信息
     * @param integer $id 主键：id
     * @throws DbException
     */
    public function getShop($id)
    {
        $shop_model = new Shops();
        $result     = $shop_model->with(['shopaddress', 'shopmaterial'])->find($id)->hidden(['user_id']);
        if ($result) {
            $result->logo_image                     = self::patch_oss($result->logo_image);
            $result->shopmaterial->card_up_image    = self::patch_oss($result->shopmaterial->card_up_image);
            $result->shopmaterial->card_down_image  = self::patch_oss($result->shopmaterial->card_down_image);
            $result->shopmaterial->license_image    = self::patch_oss($result->shopmaterial->license_image);
            $result->shopmaterial->storefront_image = self::patch_oss($result->shopmaterial->storefront_image);
            $this->success('获取成功', $result);
        } else {
            $this->error('获取失败');
        }
    }

    /**
     * 获取收藏
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @throws DbException
     */
    public function getShopsByLike($latitude, $longitude)
    {
        $distance = "(6378.138 * 2 * asin(sqrt(pow(sin((a.latitude * pi() / 180 - " . $latitude . " * pi() / 180) / 2),2) + cos(a.latitude * pi() / 180) * cos(" . $latitude . " * pi() / 180) * pow(sin((a.longitude * pi() / 180 - " . $longitude . " * pi() / 180) / 2),2))) * 1000)";
        $user_id  = $this->auth->id;
        $result   = Db::name('like')
            ->alias('l')
            ->join('shop s', 's.id = l.shop_id', 'left')
            ->join(' shop_address a', 'a.shop_id = l.shop_id', 'left')
            ->where(['l.user_id' => $user_id,'s.status'=>'1'])
            ->order("l.id desc")
            ->field("l.id as l_id,s.id,s.name,s.logo_image,s.short,a.delivery,a.hours_start,a.hours_end,a.base_price,$distance as distance")
            ->paginate(null, false, $this->paginate)
            ->each(function ($item) {
                $item['logo_image'] = self::patch_oss($item['logo_image']);
                $item['distance']   = round(($item['distance'] / 1000), 2);
                return $item;
            });
        $result ? $this->success('获取成功', $result) : $this->error('获取失败');
    }

    /**
     * 获取推广下级
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @throws DbException
     */
    public function getShopsByExtension($latitude, $longitude)
    {
        $distance = "(6378.138 * 2 * asin(sqrt(pow(sin((a.latitude * pi() / 180 - " . $latitude . " * pi() / 180) / 2),2) + cos(a.latitude * pi() / 180) * cos(" . $latitude . " * pi() / 180) * pow(sin((a.longitude * pi() / 180 - " . $longitude . " * pi() / 180) / 2),2))) * 1000)";
        $user_id  = $this->auth->id;
        $result   = Db::name('shop')
            ->alias('s')
            ->join(' user u', 'find_in_set(s.id,u.child)!=0')
            ->join(' shop_address a', 's.id = a.shop_id', 'left')
            ->where(['u.id' => $user_id,'s.status'=>'1'])
            ->field("s.id,s.name,s.logo_image,s.short,a.delivery,a.hours_start,a.hours_end,a.base_price,$distance as distance")
            ->paginate(null, false, $this->paginate)
            ->each(function ($item) {
                $item['logo_image'] = self::patch_oss($item['logo_image']);
                $item['distance']   = round(($item['distance'] / 1000), 2);
                return $item;
            });
        $result ? $this->success('获取成功', $result) : $this->error('获取失败');
    }
}