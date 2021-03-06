<?php


namespace app\api\controller;


use app\admin\model\Good as Goods;
use app\admin\model\ShopAddress;
use app\common\controller\Api;
use think\Db;
use think\exception\DbException;

/**
 * 商品管理
 */
class Good extends Api
{
    protected $noNeedLogin = ['getGood', 'getGoods'];
    protected $noNeedRight = '*';

    protected function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
    }

    /**
     * 添加商品
     * @param string $shop_id 商家主键：id
     * @param string $shop_category_id 商家分类主键：id
     * @param string $name 名称
     * @param string $original 原价
     * @param string $price 售价
     * @param string $stock 库存
     * @param string $thumb_image 缩略图
     * @param string $images 多图
     * @param string $short 简介
     * @param string $content 详情
     * @throws DbException
     */
    public function postGood($shop_id, $shop_category_id, $name, $original, $price, $stock, $thumb_image, $images, $short, $content)
    {
        // 验证用户真实性
        $user_id   = $this->auth->id;
        $find_shop = (new \app\admin\model\Shop())->get(['id' => $shop_id, 'user_id' => $user_id]);
        !$find_shop && $this->error('没有权限操作其他商户的信息');

        // 验证产品是否同名
        $good      = new Goods();
        $find_good = $good->get(['shop_id' => $shop_id, 'name' => $name]);
        $find_good && $this->error('该商品名称已存在');

        $good->data('shop_id', $shop_id);
        $good->data('shop_category_id', $shop_category_id);
        $good->data('name', $name);
        $good->data('original', $original);
        $good->data('price', $price);
        $good->data('stock', $stock);
        $good->data('thumb_image', $thumb_image);
        $good->data('images', $images);
        $good->data('short', $short);
        $good->data('content', $content);

        $result = $good->save();

        $result ? $this->success('添加成功') : $this->error('添加失败');

    }

    /**
     * 删除商品
     * @param integer $id 主键：id
     * @param integer $shop_id 商家主键：id
     * @throws DbException
     */
    public function deleteGood($id, $shop_id)
    {
        // 验证用户真实性
        $user_id   = $this->auth->id;
        $find_shop = (new \app\admin\model\Shop())->get(['id' => $shop_id, 'user_id' => $user_id]);
        !$find_shop && $this->error('没有权限操作其他商户的信息');

        $find = Goods::get(['id' => $id]);
        if ($find) {
            $result = $find->delete();
            $result ? $this->success('删除成功') : $this->error('删除失败');
        } else {
            $this->error('未找到要删除的记录');
        }
    }

    /**
     * 更新商品
     * @param integer $id 主键：id
     * @param string $shop_id 商家主键：id
     * @param string $data json格式的数据包
     * @throws DbException
     */
    public function putGood($id, $shop_id, $data)
    {
        // 验证用户真实性
        $user_id   = $this->auth->id;
        $find_shop = (new \app\admin\model\Shop())->get(['id' => $shop_id, 'user_id' => $user_id]);
        !$find_shop && $this->error('没有权限操作其他商户的信息');

        $find = Goods::get(['id' => $id]);

        if ($find) {
            $data = json_decode(htmlspecialchars_decode($data), true);
            foreach ($data as $item => $value) {
                $find->data($item, $value);
            }
            $result = $find->save();
            $result ? $this->success('更新成功') : $this->error('更新失败');
        } else {
            $this->error('未找到要更新的记录');
        }
    }

    /**
     * 商品列表
     * @param string $where json格式的筛选条件
     * @throws DbException
     */
    public function getGoods($where = '{}')
    {
        $data         = json_decode(htmlspecialchars_decode($where), true);
        $where_format = [];
        foreach ($data as $item => $value) {
            $where_format[$item] = $value;
        }

        $result = Goods::where($where_format)
            ->order('weigh', 'desc')
            ->field('id,status,shop_id,good_ids,shop_category_id,name,original,price,stock,thumb_image,images,short')
            ->paginate(null, false, $this->paginate)
            ->each(function ($item) {
                $item['thumb_image'] = self::patch_oss($item['thumb_image']);
                $item['images']      = self::patch_oss($item['images'], true);
                return $item;
            });
        if ($result) {
            $this->success('获取成功', $result);
        } else {
            $this->success('暂无记录');
        }
    }

    /**
     * 商品详情
     * @param integer $id 主键：id
     * @param boolean $back 是否校验上架下架
     * @throws DbException
     */
    public function getGood($id, $back = false)
    {
        $result = Goods::get($id);
        if ($result) {
            if (!$back) {
                $result['status'] != 1 && $this->error('商品已下架');
            }
            $result['thumb_image'] = self::patch_oss($result['thumb_image']);
            $result['images']      = self::patch_oss($result['images'], true);
            $result['content']     = self::patch_cdn($result['content']);
            $find                  = Db::name('view')->where([
                'user_id' => $this->auth->id,
                'shop_id' => $id,
            ])->find();
            if ($find) {
                Db::name('view')->where(['id'=>$find['id']])->update([
                    'view_time' => time()
                ]);
            } else {
                Db::name('view')->insert([
                    'user_id'     => $this->auth->id,
                    'shop_id'     => $id,
                    'view_time' => time()
                ]);
            }
            $this->success('获取成功', $result);
        } else {
            $this->error('商品不存在，或已下架');
        }
    }
}