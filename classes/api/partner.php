<?php

/**
 * @copyright (c) 2011 aircheng.com
 * @file partner.php
 * @brief 合作方api方法
 * @author john
 * @date 2019/12/09 13:59:44
 * @version 2.7
 */
class APIPartner
{
    //获取合作方数据
    public function getPartnerInfo($Id)
    {
        $id    = IFilter::act($id, 'int');
        $query = new IModel('partner');
        $info  = $query->getObj("id=" . $id);
        return $info;
    }

    public function getPartnerListByCode($code)
    {
        $code = IFilter::act($code);
        $page        = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query       = new IQuery('partner');
        $query->where = 'partner_code = ' . $code . ' and is_del = 0';
        $query->order = 'id desc';
        $query->page  = $page;
        return $query;
    }

    //合作方资金
    public function getPartnerAccount($userid = '')
    {
        $userid = $userid ? IFilter::act($userid, 'int') : 0;
        $query  = new IQuery('partner_account as a');
        $query->join   = "left join partner_account_config as b on a.account_type = b.code";
        $query->fields = "sum(a.balance) as balance,a.account_type,b.percent";
        $query->order = 'a.account_type asc';
        $query->where  = "a.user_id =" . $userid;
        $query->group = 'a.account_type';
        return $query->find();
        // $query->join   = "left join partner as b on a.appid = b.appid";
        // $query->where  = "a.user_id =" . $userid;
        // $query->fields = "a.balance,b.partner_name,b.appid,a.account_type,a.id";
        // $query->order = 'a.balance desc';
        // return $query->find();
    }

    ///用户中心-账户余额
    public function getPartnerAccoutLog($userid = '')
    {
        $userid = $userid ? IFilter::act($userid, 'int') : 0;
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query  = new IQuery('account_log as a');
        $query->join = "left join partner as b on a.appid=b.appid";
        $query->where = "a.user_id = " . $userid . " and a.appid!=''";
        $query->order = 'a.id desc';
        $query->page  = $page;
        return $query;
    }

    //后台-平台用户
    public function getAllUserList()
    {
        $appid = IReq::get('appid') ? IFilter::act(IReq::get('appid')) : '';
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query  = new IQuery('partner_account as a');
        $query->join = "left join user as b on a.user_id = b.id left join partner as c on a.appid=c.appid";
        if (!empty($appid)) {
            $query->where = "c.appid = '" . $appid . "'";
        }
        $query->fields = "a.*,b.username,c.partner_name";
        $query->order = 'a.id desc';
        $query->page  = $page;
        return $query;
    }

    //后台-平台用户余额统计
    public function getAllUserBalance()
    {
        $appid = IReq::get('appid') ? IFilter::act(IReq::get('appid')) : '';
        $query  = new IQuery('partner_account as a');
        $query->join = "left join user as b on a.user_id = b.id left join partner as c on a.appid=c.appid";
        if (!empty($appid)) {
            $query->where = "c.appid = '" . $appid . "'";
        }
        $query->fields = 'sum(balance) as balance,sum(recharge_total) as recharge';
        $data = $query->find();

        $res['balance'] = $data[0]['balance'];
        $res['recharge'] = $data[0]['recharge'];
        return $res;
    }

    //后台-资金记录
    public function getAccoutLog()
    {
        $where = "1=1";
        $username = IReq::get('username') ? IFilter::act(IReq::get('username')) : '';
        $appid = IReq::get('appid') ? IFilter::act(IReq::get('appid')) : '';
        $time_start = IReq::get('time_start') ? IFilter::act(IReq::get('time_start')) : '';
        $time_end = IReq::get('time_end') ? IFilter::act(IReq::get('time_end')) : '';
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query  = new IQuery('account_log as a');
        $query->join = "left join partner as b on a.appid=b.appid left join user as c on a.user_id=c.id";
        if (!empty($username)) {
            $where .= " and c.username='" . $username . "'";
        }
        if (!empty($appid)) {
            $where .= " and a.appid='" . $appid . "'";
        }

        if (!empty($time_start) && !empty($time_end)) {
            $where .= " and str_to_date(a.time, '%Y-%m-%d %H:%i:%s') BETWEEN str_to_date('" . $time_start . "', '%Y-%m-%d %H:%i:%s') AND str_to_date('" . $time_end . "', '%Y-%m-%d %H:%i:%s')";
        }

        $query->where = $where;
        $query->order = 'a.id desc';
        $query->page  = $page;
        return $query;
    }

    //后台-资金记录统计
    public function getAccoutCount()
    {
        $where = "1=1";
        $username = IReq::get('username') ? IFilter::act(IReq::get('username')) : '';
        $appid = IReq::get('appid') ? IFilter::act(IReq::get('appid')) : '';
        $time_start = IReq::get('time_start') ? IFilter::act(IReq::get('time_start')) : '';
        $time_end = IReq::get('time_end') ? IFilter::act(IReq::get('time_end')) : '';
        $query  = new IQuery('account_log as a');
        $query->join = "left join partner as b on a.appid=b.appid left join user as c on a.user_id=c.id";
        if (!empty($username)) {
            $where .= " and c.username='" . $username . "'";
        }
        if (!empty($appid)) {
            $where .= " and a.appid='" . $appid . "'";
        }

        if (!empty($time_start) && !empty($time_end)) {
            $where .= " and str_to_date(a.time, '%Y-%m-%d %H:%i:%s') BETWEEN str_to_date('" . $time_start . "', '%Y-%m-%d %H:%i:%s') AND str_to_date('" . $time_end . "', '%Y-%m-%d %H:%i:%s')";
        }

        $query->where = $where;
        $query->order = 'a.id desc';
        $query->fields = 'sum(abs(a.amount)) as total,a.type';
        $query->group = 'a.type';
        $data = $query->find();

        $count = array();
        foreach ($data as $key => $value) {
            if ($value['type'] == '1') {
                $count['decr'] = $value['total'];
            } else if ($value['type'] == '0') {
                $count['incr'] = $value['total'];
            }
        }
        return $count;
    }

    //权益金类型配置列表
    public function getPartnerConfig()
    {
        $name = IReq::get('name') ? IFilter::act(IReq::get('name')) : '';
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query  = new IQuery('partner_account_config');
        if (!empty($name)) {
            $query->where = "name = '" . $name . "'";
        }
        $query->fields = 'id,name,code,percent';

        $query->order = 'id desc';
        $query->page  = $page;
        return $query;
    }

    //所有权益金配置配置
    public function getPartnerConfigALL()
    {
        $query  = new IQuery('partner_account_config');
        $query->fields = 'id,name,code,percent';

        return $query->find();
    }

    //用户可转让权益金列表
    public function getPartnerCanChange($userid = '')
    {
        $userid = $userid ? IFilter::act($userid, 'int') : 0;
        $query  = new IQuery('partner_account as a');
        $query->join = "left join partner as b on a.appid=b.appid left join partner_account_config as c on a.account_type=c.code left join partner as d on a.appid=d.appid";
        $query->where = "a.user_id = " . $userid . " and a.is_sale=1";
        $query->fields = 'a.*,b.partner_name,c.percent,d.partner_name';
        $query->order = 'a.id asc';
        return $query->find();
    }

    //用户转让的权益金列表
    public function getChangeList($userid = '')
    {
        $userid = $userid ? IFilter::act($userid, 'int') : 0;
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query  = new IQuery('partner_account_change as a');
        $query->join = "left join partner as b on a.appid=b.appid";
        $query->fields = 'a.*,b.partner_name';
        $query->where = "a.change_user_id = " . $userid;
        $query->order = 'a.id desc';
        $query->page  = $page;
        return $query;
    }

    //权益金转让列表
    public function getIndexChangeList()
    {
        $where = '1=1';
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $sale_type = IReq::get('sale_type') ? IFilter::act(IReq::get('sale_type')) : '';
        $account_type = IReq::get('account_type') ? IFilter::act(IReq::get('account_type')) : '';
        $discount = IReq::get('discount') ? IFilter::act(IReq::get('discount')) : '';
        $order_ds = IReq::get('order_ds') ? IFilter::act(IReq::get('order_ds')) : '0';
        $query  = new IQuery('partner_account_change as a');
        $query->join = "left join partner_account_config as b on a.account_type=b.code left join partner as c on a.appid=c.appid";

        if (!empty($sale_type)) {
            $where .= " and a.status = 0 and a.num - a.sale_num >= 0";
        }

        if (!empty($account_type)) {
            $where .= " and a.account_type='" . $account_type . "'";
        }

        if(!empty($order_ds)){
            $query->order = 'a.status asc,a.discount desc,now_num desc';
        }else{
            $query->order = 'a.status asc,a.discount asc,now_num desc';
        }

        if (!empty($discount)) {
            if ($discount == 1) {
                $where .= " and a.discount<=5";
            } elseif ($discount == 2) {
                $where .= " and a.discount>5 and a.discount<=6";
            } elseif ($discount == 3) {
                $where .= " and a.discount>6 and a.discount<=7";
            } elseif ($discount == 4) {
                $where .= " and a.discount>7 and a.discount<=8";
            } elseif ($discount == 5) {
                $where .= " and a.discount>8 and a.discount<=9";
            } elseif ($discount == 6) {
                $where .= " and a.discount>9 and a.discount<=10";
            }
        }

        $query->where = $where;
        $query->fields = 'a.*,(a.num - a.sale_num) as now_num,b.percent,c.partner_name';
        $query->page  = $page;
        return $query;
    }

    //用户购买的权益金列表
    public function getBuyList($userid = '')
    {
        $userid = $userid ? IFilter::act($userid, 'int') : 0;
        $page   = IReq::get('page') ? IFilter::act(IReq::get('page'), 'int') : 1;
        $query  = new IQuery('partner_account_buy as a');
        $query->join = "left join partner_account_change as b on a.nid=b.nid left join partner as c on b.appid=c.appid";
        $query->fields = 'a.buy_user_id,a.buy_username,a.id,a.nid,a.money,a.num,a.status,a.buy_time,b.title,b.account_type,b.appid,b.discount,b.change_username,c.partner_name';
        if ($userid) {
            $query->where = "a.buy_user_id = " . $userid;
        }
        $query->order = 'a.id desc';
        $query->page  = $page;
        return $query;
    }

    //根据buyid获取购买信息
    public function getBuyInfo($userid = '', $buy_id = 0)
    {
        $userid = $userid ? IFilter::act($userid, 'int') : 0;
        $buy_id = $buy_id ? IFilter::act($buy_id, 'int') : 0;
        $query  = new IQuery('partner_account_buy as a');
        $query->join = "left join partner_account_change as b on a.nid=b.nid left join partner_account_config as c on b.account_type=c.code";
        $query->fields = 'a.do_time,a.buy_user_id,a.buy_username,a.id,a.nid,a.money,a.num,a.status,a.buy_time,b.title,b.account_type,b.appid,b.discount,b.change_username,b.title,c.percent';
        if ($buy_id) {
            $query->where = "a.id = " . $buy_id . " and a.buy_user_id=" . $userid;
        }
        return $query->find();
    }

    //首页购买列表
    public function getIndexBuyList()
    {
        $query  = new IQuery('partner_account_buy as a');
        $query->join = "left join partner_account_change as b on a.nid=b.nid";
        $query->fields = 'a.buy_user_id,a.buy_username,a.id,a.nid,a.money,a.num,a.status,a.buy_time,b.title,b.account_type,b.appid,b.discount,b.change_username';
        $query->order = 'a.id desc';
        $query->limit = '0,7';
        return $query;
    }

    //根据转让nid 获取购买者
    public function getBuyListByNid($nid = '')
    {
        $nid = $nid ? IFilter::act($nid) : '';
        $query  = new IQuery('partner_account_buy as a');
        $query->join = "left join partner_account_change as b on a.nid=b.nid";
        $query->fields = 'a.buy_user_id,a.buy_username,a.id,a.nid,a.money,a.num,a.status,a.buy_time,b.title,b.account_type,b.appid,b.discount,b.change_username';
        $query->where = "a.nid = " . $nid;
        $query->order = 'a.id desc';
        $query->limit = '0,1000';
        return $query->find();
    }
}
