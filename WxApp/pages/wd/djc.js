const util = require('../../utils/util.js')
const time = require('../../utils/time.js')
const user = require('../../utils/user.js')
const app = getApp()
Page(
    {
    data: {
        list: [],
        page: 0,
        onAsync: false,
        payOnAsync: false,
    },

    onLoad() {
        let that = this
        that.setData({list: [], page: 0, onAsync: false, payOnAsync: false})
        that.loadData()
    },

    // 商家主页
    shop: function (e) {
        let id = e.currentTarget.dataset.id
        wx.navigateTo({url: "/pages/index/shop/index?id=" + id})
    },

    // 订单主页
    order: function (e) {
        let id = e.currentTarget.dataset.id
        wx.navigateTo({url: "/pages/wd/info?type=user&id=" + id})
    },

    // 触底加载
    onReachBottom: function () {
        this.loadData()
    },

    // 加载列表
    loadData: function () {
        let that = this
        let onAsync = that.data.onAsync
        if (onAsync) return false
        if (!onAsync) (onAsync = !onAsync, that.setData({onAsync}))
        let param = {
            status: 1,
            page: that.data.page + 1
        }
        // 获取购物车信息
        util.wxRequest("Order/getOrders", param, res => {
            if (res.code === 200) {
                for (let i = 0, length = res.data.data.length; i < length; i++) {
                    res.data.data[i].createtime = time.Format(res.data.data[i].createtime)
                    res.data.data[i].more = res.data.data[i].ordergood.length > 3
                    res.data.data[i].ordergood = res.data.data[i].ordergood.slice(0, 3)
                }
                let list = that.data.list.concat(res.data.data)
                that.setData({
                    page: res.data.current_page,
                    onAsync: false,
                    list,
                })
            }
        })
    },

    // 下单支付
    pay: function (e) {
        let that = this
        let param = {id: e.currentTarget.dataset.id}
        wx.showModal({
            title: '温馨提示',
            content: '请确认',
            success: res => {
                if (res.confirm) {
                    util.wxRequest("Order/confirmReceiving", param, res => {
                        wx.showToast({title: res.msg, icon: res.code === 200 ? 'success' : 'none'})
                        setTimeout(function () {
                            // that.onLoad({id: that.data.id, type: that.data.type})
                            wx.navigateTo({url: '/pages/wd/pj?id=' + param.id})
                        }, 200)
                    })
                }
            },
        })
    },

    // 删除订单
    delete_: function (e) {
        let that = this
        that.setData({payOnAsync: true})
        let param = {
            id: e.currentTarget.dataset.id
        }
        // 调用订单隐藏
        util.wxRequest('Order/deleteOrder', param, res => {
            wx.showToast({title: res.msg, icon: res.code === 200 ? 'success' : 'none'})
            setTimeout(function () {
                that.onLoad()
            }, 2000)
        })
    }
})